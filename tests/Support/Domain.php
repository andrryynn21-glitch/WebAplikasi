<?php

namespace Tests\Support;

use App\Enums\IdentityStatus;
use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\ClassAdministratorAssignment;
use App\Models\ClassMembership;
use App\Models\ClassRoom;
use App\Models\ReportCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReportCategorySeeder;
use Illuminate\Support\Str;

/**
 * Membangun data domain untuk test secara eksplisit (tanpa factory
 * tersembunyi), sehingga setiap test membaca dengan jelas siapa siapa.
 */
class Domain
{
    public static function seedCatalog(): void
    {
        (new PermissionSeeder)->run();
        (new ReportCategorySeeder)->run();
    }

    public static function user(UserRole $role, array $attributes = []): User
    {
        $roleModel = Role::query()->firstOrCreate(
            ['key' => $role->value],
            ['name' => $role->label(), 'is_system' => true],
        );

        $user = User::create(array_merge([
            'name' => fake()->name(),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'Password123!',
        ], $attributes));

        $user->forceFill([
            'role_id' => $roleModel->id,
            'identity_status' => $attributes['identity_status'] ?? IdentityStatus::Verified,
            'is_active' => true,
        ])->save();

        return $user->refresh();
    }

    public static function term(): AcademicTerm
    {
        return AcademicTerm::query()->firstOrCreate(
            ['code' => '2026-1'],
            [
                'name' => 'Semester Ganjil 2026/2027',
                'starts_on' => now()->subMonth(),
                'ends_on' => now()->addMonths(4),
                'is_active' => true,
            ],
        );
    }

    public static function classRoom(array $attributes = []): ClassRoom
    {
        return ClassRoom::create(array_merge([
            'academic_term_id' => self::term()->id,
            'code' => 'CLS-'.Str::upper(Str::random(5)),
            'name' => 'Kelas '.Str::upper(Str::random(3)),
            'program_study' => 'Teknik Informatika',
            'semester' => 5,
            'is_active' => true,
            'join_code' => Str::upper(Str::random(8)),
        ], $attributes));
    }

    public static function member(User $user, ClassRoom $class): ClassMembership
    {
        return ClassMembership::create([
            'user_id' => $user->id,
            'class_id' => $class->id,
            'status' => 'active',
            'nim' => $user->nim,
            'approved_at' => now(),
        ]);
    }

    public static function leader(User $leader, ClassRoom $class): ClassAdministratorAssignment
    {
        self::member($leader, $class);

        return ClassAdministratorAssignment::create([
            'user_id' => $leader->id,
            'class_id' => $class->id,
            'starts_at' => now(),
        ]);
    }

    public static function lecturer(User $lecturer, ClassRoom $class): void
    {
        $class->lecturerAssignments()->create([
            'lecturer_id' => $lecturer->id,
            'role' => 'coordinator',
            'starts_at' => now(),
        ]);
    }

    public static function category(string $code): ReportCategory
    {
        return ReportCategory::query()->where('code', $code)->firstOrFail();
    }
}
