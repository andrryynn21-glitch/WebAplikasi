<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Menyinkronkan katalog permission dan role dari config/permissions.php.
 * Aman dijalankan berulang: permission/role yang tidak lagi ada di katalog
 * dicabut relasinya (role tidak dihapus agar tidak memutus akun).
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = config('permissions.permissions', []);
        $roles = config('permissions.roles', []);

        $permissionIds = [];

        foreach ($catalog as $key => $name) {
            $permission = Permission::query()->updateOrCreate(
                ['key' => $key],
                ['name' => $name],
            );
            $permissionIds[$key] = $permission->id;
        }

        foreach ($roles as $key => $definition) {
            $role = Role::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'is_system' => true,
                ],
            );

            $ids = collect($definition['permissions'])
                ->map(fn (string $permission) => $permissionIds[$permission] ?? null)
                ->filter()
                ->values()
                ->all();

            $role->permissions()->sync($ids);
        }

        // Permission yang tidak lagi ada di katalog dihapus relasinya.
        Permission::query()
            ->whereNotIn('key', array_keys($catalog))
            ->delete();
    }
}
