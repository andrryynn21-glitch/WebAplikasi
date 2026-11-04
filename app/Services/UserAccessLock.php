<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use LogicException;

final class UserAccessLock
{
    // One ordering boundary for identity decisions and administrative access changes.
    // This is intentionally coarse for the small pilot; never acquire after user locks.
    public function lock(): Role
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Access lock requires an active transaction.');
        }

        return Role::query()->where('key', UserRole::SuperAdmin->value)->lockForUpdate()->firstOrFail();
    }
}
