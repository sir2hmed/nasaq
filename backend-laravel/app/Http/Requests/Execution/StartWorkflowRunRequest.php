<?php

namespace App\Http\Requests\Execution;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartWorkflowRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['demo', 'real'])],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
