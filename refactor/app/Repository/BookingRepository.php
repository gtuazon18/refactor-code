<?php

namespace DTApi\Repository;

use App\Services\PushNotificationService;
use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

class BookingRepository extends BaseRepository
{
    function __construct(protected Job $model, protected MailerInterface $mailer, protected Logger $logger, protected PushNotificationService $pushNotificationService)
    {
        parent::__construct($model);
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function getUsersJobs(int $userId): array
    {
        $cuser = User::find($userId);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $this->getCustomerJobs($cuser);
                $usertype = 'customer';
            } elseif ($cuser->is('translator')) {
                $jobs = $this->getTranslatorJobs($cuser);
                $usertype = 'translator';
            }

            if (!empty($jobs)) {
                foreach ($jobs as $jobitem) {
                    $this->filterJobs($jobitem, $emergencyJobs, $normalJobs);
                }

                $normalJobs = $this->processNormalJobs($normalJobs, $userId);
            }
        }

        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }

    private function getCustomerJobs($cuser)
    {
        return $cuser->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
            ->whereIn('status', ['pending', 'assigned', 'started'])
            ->orderBy('due', 'asc')
            ->get();
    }

    private function getTranslatorJobs($cuser)
    {
        return Job::getTranslatorJobs($cuser->id, 'new')->pluck('jobs')->all();
    }

    private function filterJobs($jobitem, &$emergencyJobs, &$normalJobs)
    {
        if ($jobitem->immediate === 'yes') {
            $emergencyJobs[] = $jobitem;
        } else {
            $normalJobs[] = $jobitem;
        }
    }

    private function processNormalJobs($normalJobs, $userId)
    {
        return collect($normalJobs)->each(function ($item) use ($userId) {
            $item['usercheck'] = Job::checkParticularJob($userId, $item);
        })->sortBy('due')->all();
    }

    public function getUsersJobsHistory($userId, Request $request)
    {
        $page = $request->get('page', 1);
        $cuser = User::find($userId);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser && $cuser->is('customer')) {
            $jobs = $this->getCustomerJobsHistory($cuser, $page);
            $usertype = 'customer';
            return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => 0, 'pagenum' => 0];
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs = $this->getTranslatorJobsHistory($cuser, $page);
            $usertype = 'translator';
            return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $jobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => $jobs->lastPage(), 'pagenum' => $page];
        }
    }

    private function getCustomerJobsHistory($cuser, $page)
    {
        return $cuser->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
            ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
            ->orderBy('due', 'desc')
            ->paginate(15, ['*'], 'page', $page);
    }

    private function getTranslatorJobsHistory($cuser, $page)
    {
        $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $page);
        return $jobs_ids;
    }

    public function store($user, $data)
    {
        $response = [];

        if ($user->user_type != config('constants.customer_role_id')) {
            return ['status' => 'fail', 'message' => "Translator can not create booking"];
        }

        if (!isset($data['from_language_id'])) {
            return ['status' => 'fail', 'message' => "Du måste fylla in alla fält", 'field_name' => "from_language_id"];
        }

        if ($data['immediate'] == 'no') {
            $requiredFields = ['due_date', 'due_time', 'customer_phone_type', 'customer_physical_type', 'duration'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return ['status' => 'fail', 'message' => "Du måste fylla in alla fält", 'field_name' => $field];
                }
            }
        } elseif (empty($data['duration'])) {
            return ['status' => 'fail', 'message' => "Du måste fylla in alla fält", 'field_name' => "duration"];
        }

        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

        if ($data['immediate'] == 'yes') {
            $due = Carbon::now()->addMinute(5);
            $data['due'] = $due->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        } else {
            $due = Carbon::createFromFormat('m/d/Y H:i', $data['due_date'] . " " . $data['due_time']);
            if ($due->isPast()) {
                return ['status' => 'fail', 'message' => "Can't create booking in past"];
            }
            $data['due'] = $due->format('Y-m-d H:i:s');
            $response['type'] = 'regular';
        }

        $data['certified'] = 'normal';
        if (in_array('male', $data['job_for'])) {
            $data['gender'] = 'male';
        } elseif (in_array('female', $data['job_for'])) {
            $data['gender'] = 'female';
        }
        $data['job_type'] = ($user->userMeta->consumer_type == 'rwsconsumer') ? 'rws' : (($user->userMeta->consumer_type == 'ngo') ? 'unpaid' : 'paid');
        $data['b_created_at'] = now()->format('Y-m-d H:i:s');
        $data['will_expire_at'] = isset($due) ? TeHelper::willExpireAt($due, $data['b_created_at']) : null;
        $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

        $job = $user->jobs()->create($data);

        $response['status'] = 'success';
        $response['id'] = $job->id;

        return $response;
    }


    public function storeJobEmail($data)
    {
        $job = Job::findOrFail($data['user_email_job_id']);
        $user = $job->user;

        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $data['reference'] ?? '';

        if (!empty($data['address'])) {
            $job->address = $data['address'];
            $job->instructions = $data['instructions'] ?? $user->userMeta->instructions;
            $job->town = $data['town'] ?? $user->userMeta->city;
        }

        $job->save();

        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        $send_data = [
            'user' => $user,
            'job'  => $job
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $data['user_type'] ?? null;
        $response['job'] = $job;
        $response['status'] = 'success';

        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;
    }

    public function jobToData($job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
        ];

        [$due_date, $due_time] = explode(" ", $job->due);
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = [];
        if ($job->gender != null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }
        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
                    break;
            }
        }

        return $data;
    }


    public function jobEnd($post_data = [])
    {
        $completeddate = now();
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->findOrFail($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');

        $job = $job_detail;
        $job->end_at = $completeddate;
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user;
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_time = $diff->format('%h tim %i min');
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

        $user = $tr->user;
        $email = $user->email;
        $name = $user->name;
        $data['for_text'] = 'lön';
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));
    }


    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = $translator_type == 'professional' ? 'paid' : ($translator_type == 'rwstranslator' ? 'rws' : 'unpaid');

        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;

        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $languages, $gender, $translator_level);

        foreach ($job_ids as $k => $job) {
            $checktown = Job::checkTowns($job->user_id, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checktown) {
                unset($job_ids[$k]);
            }
        }

        return TeHelper::convertJobIdsInObjs($job_ids);
    }


    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $translators = User::where('user_type', '2')
            ->where('status', '1')
            ->where('id', '!=', $exclude_user_id)
            ->get();

        $translator_array = $translators->filter(function ($translator) use ($data) {
            return $this->isNeedToSendPush($translator->id)
                && !($data['immediate'] == 'yes' && TeHelper::getUsermeta($translator->id, 'not_get_emergency') == 'yes');
        })->flatMap(function ($translator) use ($job, $data) {
            $jobs = $this->getPotentialJobIdsWithUserId($translator->id);

            return $jobs->filter(function ($potentialJob) use ($job) {
                return $job->id == $potentialJob->id
                    && Job::assignedToPaticularTranslator($translator->id, $potentialJob->id) == 'SpecificJob'
                    && Job::checkParticularJob($translator->id, $potentialJob) != 'userCanNotAcceptJob';
            })->map(function ($potentialJob) use ($translator) {
                return $this->isNeedToDelayPush($translator->id) ? [] : $translator;
            });
        });
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = $data['immediate'] == 'no' ? 'suitable_job' : 'emergency_job';
        $msg_contents = ($data['immediate'] == 'no' ? 'Ny bokning för ' : 'Ny akutbokning för ') . $data['language'] . 'tolk ' . $data['duration'] . 'min' . ($data['immediate'] == 'no' ? ' ' . $data['due'] : '');
        $msg_text = ["en" => $msg_contents];
        $this->logPushInfo($job->id, $translator_array, [], $msg_text, $data);
        $this->pushNotificationService->sendPushNotificationToSpecificUsers($translator_array->all(), $job->id, $data, $msg_text, false);
    }

    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));
        $physicalJobMessageTemplate = trans('sms.physical_job', compact('date', 'time', 'city', 'duration', 'jobId'));
        $message = ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') ? $physicalJobMessageTemplate : $phoneJobMessageTemplate;

        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    public function isNeedToDelayPush($user_id)
    {
        return DateTimeHelper::isNightTime() && TeHelper::getUsermeta($user_id, 'not_get_nighttime') === 'yes';
    }

    public function isNeedToSendPush($user_id)
    {
        return TeHelper::getUsermeta($user_id, 'not_get_notification') !== 'yes';
    }

    public function getPotentialTranslators(Job $job)
    {
        $translator_type = '';
        $job_type = $job->job_type;

        switch ($job_type) {
            case 'paid':
                $translator_type = 'professional';
                break;
            case 'rws':
                $translator_type = 'rwstranslator';
                break;
            case 'unpaid':
                $translator_type = 'volunteer';
                break;
        }

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;

        $translator_levels = [];
        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_levels[] = 'Certified';
                $translator_levels[] = 'Certified with specialisation in law';
                $translator_levels[] = 'Certified with specialisation in health care';
            } elseif ($job->certified == 'law' || $job->certified == 'n_law') {
                $translator_levels[] = 'Certified with specialisation in law';
            } elseif ($job->certified == 'health' || $job->certified == 'n_health') {
                $translator_levels[] = 'Certified with specialisation in health care';
            } elseif ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_levels[] = 'Layman';
                $translator_levels[] = 'Read Translation courses';
            } elseif ($job->certified == null) {
                $translator_levels = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'];
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();
        $users = User::where('user_type', '=', '2')
            ->where('status', '=', '1')
            ->whereNotIn('id', $blacklist)
            ->where(function ($query) use ($translator_type, $joblanguage, $gender, $translator_levels) {
                $query->where('translator_type', '=', $translator_type)
                    ->orWhere('translator_type', '=', 'both');
            })
            ->where(function ($query) use ($joblanguage, $gender, $translator_levels) {
                $query->whereHas('languages', function ($subquery) use ($joblanguage) {
                    $subquery->where('lang_id', $joblanguage);
                })
                    ->when($gender, function ($subquery, $gender) {
                        $subquery->where('gender', $gender);
                    })
                    ->when($translator_levels, function ($subquery, $translator_levels) {
                        $subquery->where(function ($innerQuery) use ($translator_levels) {
                            foreach ($translator_levels as $level) {
                                $innerQuery->orWhere('translator_level', $level);
                            }
                        });
                    });
            })
            ->get();

        return $users;
    }

    public function updateJob($id, $data, $cuser)
    {
        $job = Job::findOrFail($id);
        $current_translator = $job->translatorJobRel->where('cancel_at', null)->first() ?? $job->translatorJobRel->whereNotNull('completed_at')->first();
        $log_data = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        $changeDue = $this->changeDue($job->due, $data['due']);
        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);

        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }
        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];
        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);
        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        }

        $job->save();
        if ($changeDue['dateChanged']) {
            $this->sendChangedDateNotification($job, $old_time);
        }
        if ($changeTranslator['translatorChanged']) {
            $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
        }
        if ($langChanged) {
            $this->sendChangedLangNotification($job, $old_lang);
        }
    }

    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;

        $statusMethods = [
            'timedout' => 'changeTimedoutStatus',
            'completed' => 'changeCompletedStatus',
            'started' => 'changeStartedStatus',
            'pending' => 'changePendingStatus',
            'withdrawafter24' => 'changeWithdrawafter24Status',
            'assigned' => 'changeAssignedStatus',
        ];

        if (isset($statusMethods[$old_status])) {
            $method = $statusMethods[$old_status];
            $statusChanged = $this->$method($job, $data, $changedTranslator);
        }

        if ($statusChanged) {
            $log_data = [
                'old_status' => $old_status,
                'new_status' => $data['status']
            ];
            return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
        }

        return ['statusChanged' => $statusChanged];
    }

    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');
            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout' && $data['admin_comments'] !== '') {
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
    }

    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] === '') {
            return false;
        }
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            if ($data['sesion_time'] === '') {
                return false;
            }
            $user = $job->user()->first();
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];
            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
            $email = $translator->user->email;
            $name = $translator->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $translator,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
        }
        $job->save();
        return true;
    }

    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] === '' && $data['status'] === 'timedout') {
            return false;
        }
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] === 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }
        return false;
    }

    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = [
            'notification_type' => 'session_start_remind',
        ];
        $due_explode = explode(' ', $due);
        $location = $job->customer_physical_type === 'yes' ? ' (på plats i ' . $job->town . ')' : ' (telefon)';
        $msg_text = [
            'en' => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning' . $location . ' kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!',
        ];
        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    private function changeWithdrawafter24Status($job, $data)
    {
        $allowedStatus = ['timedout'];
        if (in_array($data['status'], $allowedStatus)) {
            $job->status = $data['status'];
            if ($data['admin_comments'] === '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    private function changeAssignedStatus($job, $data)
    {
        $allowedStatus = ['withdrawbefore24', 'withdrawafter24', 'timedout'];
        if (in_array($data['status'], $allowedStatus)) {
            $job->status = $data['status'];
            if ($data['admin_comments'] === '' && $data['status'] === 'timedout') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();
                $email = !empty($job->user_email) ? $job->user_email : $user->email;
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

                $email = $translator->user->email;
                $name = $translator->user->name;
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }


    private function changeTranslator($current_translator, $data, $job)
    {
        if (empty($current_translator) && (empty($data['translator']) || $data['translator'] == 0) && empty($data['translator_email'])) {
            return ['translatorChanged' => false];
        }

        $log_data = [];

        if (!empty($current_translator)) {
            if ((!empty($data['translator']) && $current_translator->user_id != $data['translator']) || !empty($data['translator_email'])) {
                $new_translator = $this->createNewTranslator($current_translator, $data, $job);
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                return ['translatorChanged' => true, 'new_translator' => $new_translator, 'log_data' => $log_data];
            }
        } elseif (!empty($data['translator']) && ($data['translator'] != 0 || !empty($data['translator_email']))) {
            $new_translator = $this->createNewTranslator(null, $data, $job);
            $log_data[] = [
                'old_translator' => null,
                'new_translator' => $new_translator->user->email
            ];
            return ['translatorChanged' => true, 'new_translator' => $new_translator, 'log_data' => $log_data];
        }

        return ['translatorChanged' => false];
    }

    private function createNewTranslator($current_translator, $data, $job)
    {
        if (!empty($data['translator_email'])) {
            $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
        }
        $new_translator = $current_translator ? $current_translator->replicate() : new Translator();
        $new_translator->user_id = $data['translator'];
        $new_translator->job_id = $job->id;
        $new_translator->save();

        if ($current_translator) {
            $current_translator->cancel_at = Carbon::now();
            $current_translator->save();
        }

        return $new_translator;
    }

    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;

        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id;
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if (!empty($current_translator)) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;
            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $this->getUserDetails($job);
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->sendNotification($user, $subject, 'emails.job-changed-date', $data);
    }

    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $this->getUserDetails($job);
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->sendNotification($user, $subject, 'emails.job-changed-lang', $data);
    }

    private function getUserDetails($job)
    {
        $user = $job->user()->first();
        return !empty($job->user_email) ? $job->user_email : $user->email;
    }

    private function sendNotification($user, $subject, $template, $data)
    {
        $name = is_string($user) ? '' : $user->name;
        $email = is_string($user) ? $user : $user->email;
        $this->mailer->send($email, $name, $subject, $template, $data);
    }

    public function sendExpiredNotification($job, $user)
    {
        $data = [
            'notification_type' => 'job_expired',
        ];

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $this->pushNotificationService->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::with('user.userMeta')->findOrFail($job_id);

        $data = [
            'job_id'               => $job->id,
            'from_language_id'     => $job->from_language_id,
            'immediate'            => $job->immediate,
            'duration'             => $job->duration,
            'status'               => $job->status,
            'gender'               => $job->gender,
            'certified'            => $job->certified,
            'due'                  => $job->due,
            'job_type'             => $job->job_type,
            'customer_phone_type'  => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town'       => $job->user->userMeta->city,
            'customer_type'        => $job->user->userMeta->customer_type,
            'due_date'             => explode(" ", $job->due)[0],
            'due_time'             => explode(" ", $job->due)[1],
            'job_for'              => [],
        ];

        if ($job->gender != null) {
            $data['job_for'][] = ucfirst($job->gender);
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Normal';
                $data['job_for'][] = 'Certifierad';
            } else {
                $data['job_for'][] = ucfirst($job->certified);
            }
        }

        $this->sendNotificationTranslator($job, $data, '*');
    }

    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [
            'notification_type' => 'session_start_remind',
        ];

        $msg_text = [
            "en" => sprintf(
                'Du har nu fått %stolkningen för %s kl %s den %s. Vänligen säkerställ att du är förberedd för den tiden. Tack!',
                ($job->customer_physical_type == 'yes' ? 'platst' : 'telefon'),
                $language,
                $duration,
                $due
            ),
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $this->pushNotificationService->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    private function getUserTagsStringFromArray($users)
    {
        $user_tags = [];

        foreach ($users as $oneUser) {
            $user_tags[] = [
                'key'      => 'email',
                'relation' => '=',
                'value'    => strtolower($oneUser->email),
            ];
        }

        return json_encode($user_tags);
    }

    public function acceptJob($data, $user)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due) && $job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();

            $user = $job->user()->first();
            $mailer = new AppMailer();

            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

            $data = [
                'user' => $user,
                'job'  => $job
            ];

            $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            $jobs = $this->getPotentialJobs($cuser);

            return [
                'list'   => json_encode(['jobs' => $jobs, 'job' => $job], true),
                'status' => 'success',
            ];
        } else {
            return [
                'status'  => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.',
            ];
        }
    }
    public function acceptJobWithId($job_id, $cuser)
    {
        $response = [];
        $job = Job::findOrFail($job_id);

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user;
                $this->sendJobAcceptedNotification($user, $job);

                $this->sendJobAcceptedPushNotification($user, $job);

                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . ' tolk ' . $job->duration . ' min ' . $job->due;
            } else {
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . ' tolkning ' . $job->duration . ' min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }

        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = [];

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            $job->status = $job->withdraw_at->diffInHours($job->due) >= 24 ? 'withdrawbefore24' : 'withdrawafter24';
            $job->save();
            Event::fire(new JobWasCanceled($job));

            if ($translator) {
                $this->sendJobCancelledPushNotification($translator, $job);
            }

            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user;
                if ($customer) {
                    $this->sendJobCancelledPushNotification($customer, $job);
                }

                $job->status = 'pending';
                $job->created_at = now();
                $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
                $job->save();

                Job::deleteTranslatorJobRel($translator->id, $job_id);
                $this->sendNotificationTranslator($job, $translator->id);

                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
            }
        }

        return $response;
    }

    private function sendJobAcceptedNotification($user, $job)
    {
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        (new AppMailer())->send($email, $name, $subject, 'emails.job-accepted', $data);
    }

    private function sendJobAcceptedPushNotification($user, $job)
    {
        $data = [
            'notification_type' => 'job_accepted',
        ];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
        ];
        if ($this->isNeedToSendPush($user->id)) {
            $this->pushNotificationService->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    private function sendJobCancelledPushNotification($user, $job)
    {
        $data = [
            'notification_type' => 'job_cancelled',
        ];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
        ];
        if ($this->isNeedToSendPush($user->id)) {
            $this->pushNotificationService->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = $this->getJobType($cuser_meta->translator_type);
        $userlanguage = $this->getUserLanguages($cuser->id);
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;

        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        foreach ($job_ids as $k => $job) {
            if ($this->shouldExcludeJob($job, $cuser)) {
                unset($job_ids[$k]);
            }
        }

        return $job_ids;
    }

    private function getJobType($translator_type)
    {
        switch ($translator_type) {
            case 'professional':
                return 'paid';
            case 'rwstranslator':
                return 'rws';
            case 'volunteer':
                return 'unpaid';
            default:
                return 'unpaid';
        }
    }

    private function getUserLanguages($user_id)
    {
        return UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
    }

    private function shouldExcludeJob($job, $cuser)
    {
        $jobuserid = $job->user_id;
        $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
        $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
        $checktown = Job::checkTowns($jobuserid, $cuser->id);

        if ($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') {
            return true;
        }

        if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checktown) {
            return true;
        }

        return false;
    }

    public function endJob($post_data)
    {
        $completeddate = now();
        $job_id = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);

        if ($job_detail->status != 'started') {
            return ['status' => 'success'];
        }

        $session_time = $this->calculateSessionTime($job_detail->due, $completeddate);
        $this->sendSessionEndedEmail($job_detail, $session_time, 'faktura');
        $job_detail->end_at = $completeddate;
        $job_detail->status = 'completed';
        $job_detail->session_time = $session_time;
        $job_detail->save();

        $translator_rel = $job_detail->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
        $this->sendSessionEndedEmail($translator_rel->user, $session_time, 'lön');
        $translator_rel->update([
            'completed_at' => $completeddate,
            'completed_by' => $post_data['user_id']
        ]);

        Event::fire(new SessionEnded($job_detail, $this->getRecipientUserId($post_data, $job_detail)));

        return ['status' => 'success'];
    }

    public function customerNotCall($post_data)
    {
        $completeddate = now();
        $job_id = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);
        $session_time = $this->calculateSessionTime($job_detail->due, $completeddate);

        $job_detail->end_at = $completeddate;
        $job_detail->status = 'not_carried_out_customer';
        $job_detail->save();

        $translator_rel = $job_detail->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
        $translator_rel->update([
            'completed_at' => $completeddate,
            'completed_by' => $translator_rel->user_id
        ]);

        return ['status' => 'success'];
    }

    private function calculateSessionTime($start, $end)
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $start);
        $end = Carbon::createFromFormat('Y-m-d H:i:s', $end);
        return $end->diff($start)->format('%h:%i:%s');
    }

    private function sendSessionEndedEmail($user, $session_time, $for_text)
    {
        $data = [
            'user'         => $user,
            'job'          => $job_detail,
            'session_time' => $session_time,
            'for_text'     => $for_text
        ];
        $mailer = new AppMailer();
        $mailer->send($user->email, $user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job_detail->id, 'emails.session-ended', $data);
    }

    private function getRecipientUserId($post_data, $job_detail)
    {
        return $post_data['user_id'] == $job_detail->user_id ? $job_detail->translatorJobRel->user_id : $job_detail->user_id;
    }

    public function getAll($requestdata, $user, $limit = null)
    {
        $consumer_type = optional($user)->consumer_type;

        $allJobs = Job::query();

        if ($user && $user->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs = $this->applySuperadminFilters($allJobs, $requestdata);
        } else {
            $allJobs = $this->applyRegularUserFilters($allJobs, $requestdata, $consumer_type);
        }

        $allJobs = $this->applyCommonFilters($allJobs, $requestdata);

        $allJobs = $allJobs->orderBy('created_at', 'desc')
            ->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        if ($limit == 'all') {
            $allJobs = $allJobs->get();
        } else {
            $allJobs = $allJobs->paginate(15);
        }

        return $allJobs;
    }

    private function applySuperadminFilters($query, $requestdata)
    {
        $query = $this->applyIdFilter($query, $requestdata);

        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            $query = $this->applyDateFilter($query, 'created_at', $requestdata);
        }

        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
            $query = $this->applyDateFilter($query, 'due', $requestdata);
        }

        if (isset($requestdata['booking_type'])) {
            $query = $this->applyBookingTypeFilter($query, $requestdata['booking_type']);
        }

        return $query;
    }

    private function applyRegularUserFilters($query, $requestdata, $consumer_type)
    {
        $query->where('job_type', '=', $consumer_type == 'RWS' ? 'rws' : 'unpaid');

        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            $query = $this->applyDateFilter($query, 'created_at', $requestdata);
        }

        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
            $query = $this->applyDateFilter($query, 'due', $requestdata);
        }

        return $query;
    }

    private function applyCommonFilters($query, $requestdata)
    {
        $query = $this->applyFeedbackFilters($query, $requestdata);

        $query = $this->applyLanguageFilter($query, $requestdata);

        return $query;
    }

    private function applyIdFilter($query, $requestdata)
    {
        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            $query->whereIn('id', (array) $requestdata['id']);
        }

        return $query;
    }

    private function applyDateFilter($query, $field, $requestdata)
    {
        if (isset($requestdata['from']) && $requestdata['from'] != "") {
            $query->where($field, '>=', $requestdata["from"]);
        }

        if (isset($requestdata['to']) && $requestdata['to'] != "") {
            $to = $requestdata["to"] . " 23:59:00";
            $query->where($field, '<=', $to);
        }

        return $query;
    }

    private function applyBookingTypeFilter($query, $booking_type)
    {
        if ($booking_type == 'physical') {
            $query->where('customer_physical_type', 'yes');
        }

        if ($booking_type == 'phone') {
            $query->where('customer_phone_type', 'yes');
        }

        return $query;
    }

    private function applyFeedbackFilters($query, $requestdata)
    {
        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $query->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
        }

        return $query;
    }

    private function applyLanguageFilter($query, $requestdata)
    {
        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $query->whereIn('from_language_id', (array) $requestdata['lang']);
        }

        return $query;
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
                if ($diff[count($diff) - 1] >= $job->duration * 2) {
                    $sesJobs[] = $job;
                }
            }
        }

        $jobId = array_map(function ($job) {
            return $job->id;
        }, $sesJobs);

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = User::where('user_type', '1')->pluck('email');
        $all_translators = User::where('user_type', '2')->pluck('email');

        $cuser = Auth::user();
        $consumer_type = optional($cuser)->consumer_type;

        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = Job::query()
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $jobId)
                ->where('jobs.ignore', 0);

            $allJobs = $this->applyFilters($allJobs, $requestdata);

            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc')
                ->paginate(15);
        }

        return compact('allJobs', 'languages', 'all_customers', 'all_translators', 'requestdata');
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);
        return compact('throttles');
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = User::where('user_type', '1')->pluck('email');
        $all_translators = User::where('user_type', '2')->pluck('email');

        $cuser = Auth::user();
        $consumer_type = optional($cuser)->consumer_type;

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = Job::query()
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0)
                ->where('jobs.status', 'pending')
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs = $this->applyFilters($allJobs, $requestdata);

            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc')
                ->paginate(15);
        }

        return compact('allJobs', 'languages', 'all_customers', 'all_translators', 'requestdata');
    }

    private function applyFilters($query, $requestdata)
    {
        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $query->whereIn('jobs.from_language_id', $requestdata['lang']);
        }

        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $query->whereIn('jobs.status', $requestdata['status']);
        }

        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $user = User::where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $query->where('jobs.user_id', $user->id);
            }
        }

        if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
            $user = User::where('email', $requestdata['translator_email'])->first();
            if ($user) {
                $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                $query->whereIn('jobs.id', $allJobIDs);
            }
        }

        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $query->where('jobs.created_at', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $query->where('jobs.created_at', '<=', $to);
            }
            $query->orderBy('jobs.created_at', 'desc');
        }

        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $query->where('jobs.due', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $query->where('jobs.due', '<=', $to);
            }
            $query->orderBy('jobs.due', 'desc');
        }

        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $query->whereIn('jobs.job_type', $requestdata['job_type']);
        }

        return $query;
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);
        $job = $job->toArray();

        $data = [
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
            'updated_at' => now(),
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => now(),
        ];

        $datareopen = [
            'status' => 'pending',
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
        ];

        if ($job['status'] != 'timedout') {
            Job::where('id', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = now();
            $job['updated_at'] = now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], now());
            $job['updated_at'] = now();
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }

        Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
        $Translator = Translator::create($data);

        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } elseif ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }
}
