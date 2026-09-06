<?php

namespace App\Http\Requests\Integration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectIntegrationRequest extends FormRequest
{
    private const PROVIDERS = ['google_drive', 'youtube', 'smtp'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $provider = (string) $this->route('provider');
        $common = [
            'credentials' => ['required', 'array'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];

        return match ($provider) {
            'google_drive' => [
                ...$common,
                'credentials' => ['required', 'array:access_token,refresh_token,client_id,client_secret,folder_id'],
                'credentials.access_token' => ['required', 'string', 'min:8', 'max:4096'],
                'credentials.refresh_token' => ['nullable', 'string', 'min:8', 'max:4096'],
                'credentials.client_id' => ['nullable', 'string', 'max:1000'],
                'credentials.client_secret' => ['nullable', 'string', 'max:1000'],
                'credentials.folder_id' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z0-9_-]+$/'],
            ],
            'youtube' => [
                ...$common,
                'credentials' => ['required', 'array:access_token,refresh_token,client_id,client_secret,privacy_status'],
                'credentials.access_token' => ['required', 'string', 'min:8', 'max:4096'],
                'credentials.refresh_token' => ['nullable', 'string', 'min:8', 'max:4096'],
                'credentials.client_id' => ['nullable', 'string', 'max:1000'],
                'credentials.client_secret' => ['nullable', 'string', 'max:1000'],
                'credentials.privacy_status' => ['nullable', Rule::in(['private', 'unlisted', 'public'])],
            ],
            'smtp' => [
                ...$common,
                'credentials' => ['required', 'array:host,port,username,password,from_email,from_name,encryption'],
                'credentials.host' => ['required', 'string', 'max:253'],
                'credentials.port' => ['required', 'integer', 'between:1,65535'],
                'credentials.username' => ['nullable', 'string', 'max:1000'],
                'credentials.password' => ['nullable', 'string', 'max:4096'],
                'credentials.from_email' => ['required', 'email:rfc', 'max:254'],
                'credentials.from_name' => ['nullable', 'string', 'max:160'],
                'credentials.encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            ],
            default => [
                'provider' => ['required', Rule::in(self::PROVIDERS)],
            ],
        };
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['provider' => $this->route('provider')]);
    }
}
