<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SyncSessionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sessions' => ['required', 'array'],
            'sessions.*.uuid' => ['required', 'uuid'],
            'sessions.*.admission_number' => ['required', 'string'],
            'sessions.*.login_time' => ['required', 'date'],
            'sessions.*.logout_time' => ['nullable', 'date'],
        ];
    }
}
