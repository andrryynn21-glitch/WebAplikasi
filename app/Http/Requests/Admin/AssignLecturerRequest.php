<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AssignLecturerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('classes.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'lecturer_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['nullable', 'in:coordinator,assistant'],
        ];
    }
}
