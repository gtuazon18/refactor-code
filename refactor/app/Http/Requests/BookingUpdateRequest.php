// BookingUpdateRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingUpdateRequest extends FormRequest
{
    public function rules()
    {
        return [
            'distance' => 'sometimes|numeric',
            'time' => 'sometimes|numeric',
            'session_time' => 'sometimes|numeric',
            'flagged' => 'sometimes|boolean',
            'manually_handled' => 'sometimes|boolean',
            'by_admin' => 'sometimes|boolean',
            'admin_comment' => 'sometimes|string',
        ];
    }
}
