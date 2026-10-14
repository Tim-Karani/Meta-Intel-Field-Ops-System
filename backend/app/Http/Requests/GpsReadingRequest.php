<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Raw GPS evidence from the device; the server decides what it means (GpsValidator).
 * There is deliberately NO offline_replay flag: clients cannot ask the server to skip clock checks.
 */
class GpsReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // authorisation happens in the controller via VisitPolicy
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'device_ts' => ['nullable', 'date'],
            'mock_flag' => ['nullable', 'boolean'],
            'provider' => ['nullable', 'string', 'max:30'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'network_type' => ['nullable', 'string', 'max:30'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }
}
