<?php

namespace App\Http\Requests\Execution;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InternalExecutionEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'event_type' => ['required', Rule::in([
                'RUN_STARTED',
                'NODE_STARTED',
                'NODE_RETRYING',
                'NODE_SUCCEEDED',
                'NODE_FAILED',
                'APPROVAL_REQUIRED',
                'RUN_SUCCEEDED',
                'RUN_FAILED',
                'RUN_CANCELLED',
            ])],
            'correlation_id' => ['required', 'uuid'],
            'node_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]{2,63}$/'],
            'occurred_at' => ['required', 'date'],
            'attempt' => ['required', 'integer', 'min:1', 'max:10'],
            'message' => ['required', 'string', 'max:2000'],
            'data' => ['present', 'array'],
        ];
    }
}
