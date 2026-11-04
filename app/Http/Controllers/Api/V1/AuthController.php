<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IdentityStatus;
use App\Enums\SecurityEventCategory;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly SecurityEventService $security,
    ) {}

    /**
     * Registrasi publik. Role SELALU mahasiswa dan identitas selalu
     * unverified, apa pun yang dikirim klien.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $studentRole = Role::query()->where('key', UserRole::Student->value)->firstOrFail();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'nim' => $data['nim'],
            'phone' => $data['phone'] ?? null,
        ]);

        // Set lewat forceFill agar tidak bisa dipengaruhi payload.
        $user->forceFill([
            'role_id' => $studentRole->id,
            'identity_status' => IdentityStatus::Unverified,
            'is_active' => true,
        ])->save();

        $token = $user->createToken($data['device_name'] ?? 'web')->plainTextToken;

        return $this->respond(
            $this->authPayload($user, $token),
            'Registrasi berhasil. Unggah KTM untuk memulai verifikasi identitas.',
            201,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $throttleKey = 'login:'.mb_strtolower($request->string('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $event = $this->security->recordDenial(
                SecurityEventCategory::SuspiciousLogin,
                null,
                $request,
                null,
                'Terlalu banyak percobaan login.',
            );

            return $this->fail(
                'Terlalu banyak percobaan login. Coba lagi nanti.',
                'TOO_MANY_ATTEMPTS',
                429,
                null,
                ['retry_after' => RateLimiter::availableIn($throttleKey)],
                $event,
            );
        }

        $user = User::query()->where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check($request->string('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            $event = $this->security->recordDenial(
                SecurityEventCategory::SuspiciousLogin,
                $user,
                $request,
                null,
                'Kredensial login tidak cocok.',
            );

            return $this->fail(
                'Email atau kata sandi tidak sesuai.',
                'INVALID_CREDENTIALS',
                401,
                null,
                [],
                $event,
            );
        }

        RateLimiter::clear($throttleKey);

        if (! $user->is_active) {
            return $this->fail('Akun dinonaktifkan. Hubungi administrator.', 'ACCOUNT_DISABLED', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        // Token lama pada perangkat yang sama dicabut agar sesi tidak menumpuk.
        $device = $request->string('device_name', 'web')->toString();
        $user->tokens()->where('name', $device)->delete();

        $token = $user->createToken($device)->plainTextToken;

        return $this->respond($this->authPayload($user, $token), 'Login berhasil.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->respond(null, 'Logout berhasil.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return $this->respond(null, 'Semua sesi telah diakhiri.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'role.permissions:id,key',
            'activeMembership.classRoom:id,code,name',
        ]);

        return $this->respond(new UserResource($user), 'Data pengguna saat ini.');
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $data = $request->validated();

            if (isset($data['name']) && $data['name'] !== $user->name
                && (in_array($user->identity_status, [IdentityStatus::PendingVerification, IdentityStatus::Verified], true)
                    || $user->hasRole(UserRole::ClassLeader, UserRole::LecturerCoordinator))) {
                throw ValidationException::withMessages([
                    'name' => 'Nama identitas yang sedang diperiksa, terverifikasi, atau digunakan penangan tidak dapat diubah melalui profil.',
                ]);
            }

            $user->fill($data)->save();

            return $user;
        });

        return $this->respond(
            new UserResource($user->load('role.permissions:id,key')),
            'Profil diperbarui.',
        );
    }

    private function authPayload(User $user, string $token): array
    {
        $user->load(['role.permissions:id,key', 'activeMembership.classRoom:id,code,name']);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in_minutes' => config('sanctum.expiration'),
            'user' => new UserResource($user),
        ];
    }
}
