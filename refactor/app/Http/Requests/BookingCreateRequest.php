<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingCreateRequest extends FormRequest
{

    public function rules()
    {
        return [
            'distance' => 'required|numeric',
            'time' => 'required|numeric',
            'session_time' => 'required|numeric',
            'flagged' => 'required|boolean',
            'manually_handled' => 'required|boolean',
            'by_admin' => 'required|boolean',
            'admin_comment' => 'required|string',
        ];
    }
}
