<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\CredentialService;
use Illuminate\Http\JsonResponse;

class CredentialController extends Controller
{
    public function __construct(private readonly CredentialService $credentials) {}

    public function change(ChangePasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->credentials->change($request->user(), $data['current_password'], $data['password']);

        return $this->respond(null, 'Password berhasil diubah. Sesi lain telah diakhiri.');
    }

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->credentials->requestReset(mb_strtolower($request->validated('email')));

        return $this->respond(null, 'Jika email terdaftar dan layanan email tersedia, tautan pemulihan akan dikirim.');
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->credentials->reset($request->validated());

        return $this->respond(null, 'Password berhasil dipulihkan. Silakan masuk kembali.');
    }
}
