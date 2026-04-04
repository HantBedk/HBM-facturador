<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdminEquipo() || $user->rol === User::ROL_EMPLEADO;
    }

    public function view(User $user, Service $service): bool
    {
        if ($user->isAdminEquipo()) {
            return true;
        }

        return $user->rol === User::ROL_EMPLEADO && (int) $service->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdminEquipo() || $user->rol === User::ROL_EMPLEADO;
    }

    public function update(User $user, Service $service): bool
    {
        if ($user->isAdminEquipo()) {
            return true;
        }

        return $user->rol === User::ROL_EMPLEADO && (int) $service->user_id === (int) $user->id;
    }

    public function delete(User $user, Service $service): bool
    {
        if ($user->isAdminEquipo()) {
            return true;
        }

        return $user->rol === User::ROL_EMPLEADO && (int) $service->user_id === (int) $user->id;
    }
}
