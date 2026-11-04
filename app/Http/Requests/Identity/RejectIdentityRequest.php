<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

class RejectIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('identity.verify') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
