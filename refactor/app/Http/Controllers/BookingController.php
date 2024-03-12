<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use DTApi\Http\Resources\JobResource;

class BookingController extends Controller
{
    public function __construct(protected BookingService $bookingService, protected BookingRepository $bookingRepository)
    {
        $this->bookingService = $bookingService;
        $this->bookingRepo = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $user = $request->__authenticatedUser;
        $jobs = $user->user_type == config('constants.admin_role_id') || $user->user_type == config('constants.superadmin_role_id')
            ? $this->bookingRepo->getAll($request->validated())
            : $this->bookingRepo->getUsersJobs($user->id);
        return JobResource::collection($jobs);
    }

    public function show($id)
    {
        $job = $this->bookingRepo->firstOrFail($id);
        return new JobResource($job);
    }

    public function store(BookingStoreRequest $request)
    {
        $job = $this->bookingRepo->store($request->__authenticatedUser, $request->validated());
        return new JobResource($job);
    }

    public function update($id, BookingUpdateRequest $request)
    {
        $job = $this->bookingRepo->updateJob($id, $request->validated(), $request->__authenticatedUser);
        return new JobResource($job);
    }

    public function immediateJobEmail(Request $request)
    {
        $job = $this->bookingRepo->storeJobEmail($request->validated());
        return new JobResource($job);
    }

    public function getHistory(Request $request)
    {
        $userId = $request->get('user_id');
        if (!$userId) {
            return null;
        }
        $job = $this->bookingRepo->getUsersJobsHistory($userId, $request->validated());
        return new JobResource($job);
    }

    public function acceptJob(Request $request)
    {
        $data = $request->validated();
        $user = $request->__authenticatedUser;
        $job = $this->bookingRepo->acceptJob($data, $user);
        return new JobResource($job);
    }

    public function acceptJobWithId(Request $request)
    {
        $job = $this->bookingRepo->acceptJobWithId($request->get('job_id'), $request->__authenticatedUser);

        return new JobResource($job);
    }

    public function cancelJob(Request $request)
    {
        $job = $this->bookingRepo->cancelJobAjax($request->validated(), $request->__authenticatedUser);
        return new JobResource($job);
    }

    public function endJob(Request $request)
    {
        $job = $this->bookingRepo->endJob($request->validated());
        return new JobResource($job);
    }

    public function customerNotCall(Request $request)
    {
        $job = $this->bookingRepository->customerNotCall($request->validated());
        return new JobResource($job);
    }

    public function getPotentialJobs(Request $request)
    {
        $job = $this->bookingRepository->getPotentialJobs($request->validated(), $request->__authenticatedUser);
        return new JobResource($job);
    }

    public function distanceFeed(Request $request)
    {
        $data = $request->validated();
        $this->bookingService->updateDistanceAndJobModels($data);
        return response('Record updated!');
    }

    public function reopen(Request $request)
    {
        $job = $this->bookingRepo->reopen($request->validated());
        return new JobResource($job);
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->validated();
        $job = $this->bookingRepo->find($data['jobid']);
        $jobData = $this->bookingRepo->jobToData($job);
        $this->bookingRepo->sendNotificationTranslator($job, $jobData, '*');
        return response(['success' => 'Push sent']);
    }

    public function resendSMSNotifications(Request $request)
    {
        $data = $request->validated();
        $job = $this->bookingRepo->find($data['jobid']);
        $this->bookingRepo->jobToData($job);
        try {
            $this->bookingRepo->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }
}
