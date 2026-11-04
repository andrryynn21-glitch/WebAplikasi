<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $payload = match ($user->roleKey()) {
            UserRole::SuperAdmin => $this->dashboard->forSuperAdmin($user),
            UserRole::LecturerCoordinator => $this->dashboard->forLecturer($user),
            UserRole::ClassLeader => $this->dashboard->forClassLeader($user),
            UserRole::IdentityVerifier => $this->dashboard->forIdentityVerifier($user),
            default => $this->dashboard->forStudent($user),
        };

        return $this->respond($payload, 'Dashboard.');
    }
}
