<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Registrasi publik HANYA membuat akun mahasiswa.
 * Field role/identity_status/is_active tidak diterima di sini;
 * jika dikirim, diabaikan oleh User::$fillable.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'nim' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'phone' => ['nullable', 'string', 'max:24'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
