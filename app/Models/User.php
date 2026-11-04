<?php

namespace App\Models;

use App\Enums\IdentityStatus;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Catatan keamanan:
 * role_id dan identity_status TIDAK fillable. Keduanya hanya boleh berubah
 * lewat service administratif yang mencatat audit log, sehingga payload
 * registrasi/PATCH profil tidak bisa dipakai untuk privilege escalation.
 */
#[Fillable(['name', 'email', 'password', 'nim', 'phone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'identity_status' => IdentityStatus::class,
            'is_active' => 'boolean',
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    // ----------------------------------------------------------------- relasi

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ClassMembership::class, 'user_id');
    }

    public function activeMembership(): HasOne
    {
        return $this->hasOne(ClassMembership::class, 'user_id')
            ->where('status', MembershipStatus::Active->value);
    }

    public function identityVerifications(): HasMany
    {
        return $this->hasMany(IdentityVerification::class, 'user_id')->latest();
    }

    public function latestIdentityVerification(): HasOne
    {
        return $this->hasOne(IdentityVerification::class, 'user_id')->latestOfMany();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function lecturerAssignments(): HasMany
    {
        return $this->hasMany(LecturerAssignment::class, 'lecturer_id');
    }

    public function classAdministratorAssignments(): HasMany
    {
        return $this->hasMany(ClassAdministratorAssignment::class, 'user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AppNotification::class, 'user_id');
    }

    // ------------------------------------------------------------------ bantuan

    public function roleKey(): ?UserRole
    {
        return $this->role?->key;
    }

    public function hasRole(UserRole ...$roles): bool
    {
        $key = $this->roleKey();

        return $key !== null && in_array($key, $roles, true);
    }

    public function isStudent(): bool
    {
        return $this->roleKey() === UserRole::Student;
    }

    public function isVerified(): bool
    {
        return $this->identity_status === IdentityStatus::Verified;
    }

    /**
     * Mahasiswa boleh memakai fitur kelas jika identitas terverifikasi,
     * akun aktif, dan keanggotaan kelasnya sudah disetujui.
     */
    public function canUseClassFeatures(): bool
    {
        return $this->is_active
            && $this->hasRole(UserRole::Student, UserRole::ClassLeader)
            && $this->isVerified()
            && $this->activeMembership()->exists();
    }

    public function activeClassId(): ?int
    {
        return $this->activeMembership()->value('class_id');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->role?->permissions()->where('key', $permission)->exists() ?? false;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return $this->role?->permissions()->whereIn('key', $permissions)->exists() ?? false;
    }

    /**
     * Apakah pengguna menjadi dosen pengampu aktif pada kelas tertentu.
     */
    public function isLecturerOf(int $classId): bool
    {
        return $this->is_active
            && $this->hasRole(UserRole::LecturerCoordinator)
            && $this->lecturerAssignments()
                ->where('class_id', $classId)
                ->where('starts_at', '<=', now())
                ->whereNull('ends_at')
                ->exists();
    }

    /**
     * Apakah pengguna adalah ketua kelas aktif pada kelas tertentu.
     */
    public function isClassLeaderOf(int $classId): bool
    {
        return $this->hasRole(UserRole::ClassLeader)
            && $this->canUseClassFeatures()
            && $this->activeClassId() === $classId
            && $this->classAdministratorAssignments()
                ->where('class_id', $classId)
                ->where('starts_at', '<=', now())
                ->whereNull('ends_at')
                ->exists();
    }

    /**
     * Semua kelas tempat pengguna dapat mengakses data (aktif).
     *
     * @return array<int, int>
     */
    public function accessibleClassIds(): array
    {
        if (! $this->is_active) {
            return [];
        }

        if ($this->hasRole(UserRole::SuperAdmin)) {
            return ClassRoom::query()->pluck('id')->all();
        }

        $ids = collect();

        if ($this->canUseClassFeatures()) {
            $ids->push($this->activeClassId());
        }

        if ($this->hasRole(UserRole::LecturerCoordinator)) {
            $ids = $ids->merge(
                $this->lecturerAssignments()
                    ->where('starts_at', '<=', now())
                    ->whereNull('ends_at')
                    ->pluck('class_id')
            );
        }

        return $ids->filter()->unique()->values()->all();
    }

    /** @return array<int, int> */
    public function managedClassIds(): array
    {
        if ($this->hasRole(UserRole::LecturerCoordinator)) {
            return $this->accessibleClassIds();
        }

        $classId = $this->activeClassId();

        return $classId !== null && $this->isClassLeaderOf($classId) ? [$classId] : [];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
