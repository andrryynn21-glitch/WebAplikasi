<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AssignClassLeaderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('classes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
