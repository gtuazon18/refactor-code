<?php

namespace App\Services;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;
use DTApi\Repository\BookingRepository;

class PushNotificationService
{
    public function __construct(protected BookingRepository $bookingRepository)
    {
        $this->bookingRepo = $bookingRepository;
    }
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = env('APP_ENV') == 'prod' ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", env('APP_ENV') == 'prod' ? config('app.prodOnesignalApiKey') : config('app.devOnesignalApiKey'));

        $user_tags = $this->bookingRepo->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            $android_sound = $data['immediate'] == 'no' ? 'normal_booking' : 'emergency_booking';
            $ios_sound = $data['immediate'] == 'no' ? 'normal_booking.mp3' : 'emergency_booking.mp3';
        }

        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound,
        ];

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $fields = json_encode($fields);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://onesignal.com/api/v1/notifications",
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', $onesignalRestAuthKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }
}
