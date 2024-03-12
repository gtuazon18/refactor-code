<?php

namespace DTApi\Services;

use DTApi\Models\Distance;
use DTApi\Models\Job;

class BookingService
{
    public function updateDistanceAndJobModels($data)
    {
        $jobId = $data['jobid'] ?? null;
        if (!$jobId) {
            throw new \InvalidArgumentException('Job ID is required.');
        }

        // Update Distance model
        Distance::where('job_id', '=', $jobId)->update([
            'distance' => $data['distance'] ?? null,
            'time' => $data['time'] ?? null,
        ]);

        // Update Job model
        Job::where('id', '=', $jobId)->update([
            'admin_comments' => $data['admincomment'] ?? '',
            'flagged' => $data['flagged'] === 'true' ?? 'no',
            'session_time' => $data['session_time'] ?? null,
            'manually_handled' => $data['manually_handled'] === 'true' ?? 'no',
            'by_admin' => $data['by_admin'] === 'true' ?? 'no',
        ]);
    }
}
