<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

class SubmitIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->hasPermission('identity.submit');
    }

    public function rules(): array
    {
        $maxKb = (int) config('security.uploads.identity_max_kb', 4096);

        return [
            'ktm' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:'.$maxKb],
            'nim' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'full_name' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'ktm.mimes' => 'KTM harus berupa JPG, PNG, atau PDF.',
            'ktm.max' => 'Ukuran KTM melebihi batas.',
        ];
    }
}
