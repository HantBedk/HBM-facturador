<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_accepts_hbm_local_domain(): void
    {
        User::query()->create([
            'nombre' => 'Admin',
            'correo' => 'admin@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
        ]);

        $this->postJson('/api/auth/login', [
            'correo' => 'admin@hbm.local',
            'password' => 'secret1234',
            'device_name' => 'web',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'correo', 'rol']]);
    }

    public function test_login_accepts_null_device_name(): void
    {
        User::query()->create([
            'nombre' => 'X',
            'correo' => 'x@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_EMPLEADO,
            'estado' => User::ESTADO_ACTIVO,
        ]);

        $this->postJson('/api/auth/login', [
            'correo' => 'x@hbm.local',
            'password' => 'secret1234',
            'device_name' => null,
        ])->assertOk()
            ->assertJsonPath('user.correo', 'x@hbm.local');
    }

    public function test_login_rejects_invalid_correo_shape(): void
    {
        $this->postJson('/api/auth/login', [
            'correo' => 'sin-arroba',
            'password' => 'x',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['correo']);
    }

    public function test_login_rejects_wrong_password_with_401(): void
    {
        User::query()->create([
            'nombre' => 'A',
            'correo' => 'a@hbm.local',
            'password' => 'goodpass123',
            'rol' => User::ROL_EMPLEADO,
            'estado' => User::ESTADO_ACTIVO,
        ]);

        $this->postJson('/api/auth/login', [
            'correo' => 'a@hbm.local',
            'password' => 'wrong',
        ])->assertStatus(401)
            ->assertJsonPath('errors.correo.0', 'Correo o contraseña incorrectos.');
    }
}
