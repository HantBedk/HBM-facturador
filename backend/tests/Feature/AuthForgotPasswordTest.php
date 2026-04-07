<?php

namespace Tests\Feature;

use App\Models\PanelNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'Si los datos coinciden con un usuario registrado y activo, la solicitud fue enviada a quien corresponda.';

    public function test_forgot_password_returns_generic_when_no_match(): void
    {
        User::query()->create([
            'nombre' => 'E',
            'correo' => 'e@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_EMPLEADO,
            'estado' => User::ESTADO_ACTIVO,
            'numero_documento' => '12345678',
        ]);

        $this->postJson('/api/auth/forgot-password', [
            'correo' => 'e@hbm.local',
            'numero_documento' => '99999999',
        ])->assertOk()
            ->assertJsonPath('message', self::GENERIC);

        $this->assertSame(0, PanelNotification::query()->count());
    }

    public function test_admin_request_notifies_only_super_admin(): void
    {
        $super = User::query()->create([
            'nombre' => 'Super',
            'correo' => 'super@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_SUPER_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
            'numero_documento' => '11111111',
        ]);

        $plainAdmin = User::query()->create([
            'nombre' => 'Admin',
            'correo' => 'admin@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
            'numero_documento' => '22222222',
        ]);

        $this->postJson('/api/auth/forgot-password', [
            'correo' => 'admin@hbm.local',
            'numero_documento' => '22222222',
        ])->assertOk()
            ->assertJsonPath('message', self::GENERIC);

        $this->assertSame(1, PanelNotification::query()->count());
        $row = PanelNotification::query()->first();
        $this->assertSame($super->id, $row->user_id);
        $this->assertNotSame($plainAdmin->id, $row->user_id);
    }

    public function test_empleado_request_notifies_admin_and_super_admin(): void
    {
        $super = User::query()->create([
            'nombre' => 'Super',
            'correo' => 'super@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_SUPER_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
            'numero_documento' => '11111111',
        ]);

        $plainAdmin = User::query()->create([
            'nombre' => 'Admin',
            'correo' => 'admin@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
            'numero_documento' => '33333333',
        ]);

        $emp = User::query()->create([
            'nombre' => 'Técnico',
            'correo' => 'tec@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_EMPLEADO,
            'estado' => User::ESTADO_ACTIVO,
            'numero_documento' => '44444444',
        ]);

        $this->postJson('/api/auth/forgot-password', [
            'correo' => 'tec@hbm.local',
            'numero_documento' => '44444444',
        ])->assertOk()
            ->assertJsonPath('message', self::GENERIC);

        $recipientIds = PanelNotification::query()->pluck('user_id')->sort()->values()->all();
        $this->assertSame([$plainAdmin->id, $super->id], $recipientIds);
        $this->assertNotContains($emp->id, $recipientIds);
    }

    public function test_forgot_password_accepts_document_with_formatting(): void
    {
        User::query()->create([
            'nombre' => 'Super',
            'correo' => 'super@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_SUPER_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
            'numero_documento' => '12345678',
        ]);

        User::query()->create([
            'nombre' => 'Admin',
            'correo' => 'admin@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
            'numero_documento' => '99999999',
        ]);

        User::query()->create([
            'nombre' => 'E',
            'correo' => 'e@hbm.local',
            'password' => 'secret1234',
            'rol' => User::ROL_EMPLEADO,
            'estado' => User::ESTADO_ACTIVO,
            'numero_documento' => '87654321',
        ]);

        $this->postJson('/api/auth/forgot-password', [
            'correo' => 'e@hbm.local',
            'numero_documento' => '87.654.321',
        ])->assertOk()
            ->assertJsonPath('message', self::GENERIC);

        $this->assertSame(2, PanelNotification::query()->count());
    }
}
