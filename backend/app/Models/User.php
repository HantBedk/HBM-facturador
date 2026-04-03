<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROL_ADMIN = 'admin';

    /** Mismos privilegios operativos que admin (panel y políticas). */
    public const ROL_SUPER_ADMIN = 'super_admin';

    public const ROL_EMPLEADO = 'empleado';

    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_INACTIVO = 'inactivo';

    protected $fillable = [
        'nombre',
        'correo',
        'password',
        'rol',
        'estado',
        'telefono',
        'tipo_documento',
        'numero_documento',
        'ciudad',
        'departamento',
        'banco_codigo',
        'cuenta_tipo',
        'cuenta_numero',
        'perfil_completado_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'perfil_completado_at' => 'datetime',
        ];
    }

    public function getEmailForPasswordReset(): string
    {
        return $this->correo;
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function panelNotifications(): HasMany
    {
        return $this->hasMany(PanelNotification::class);
    }

    public function isAdminEquipo(): bool
    {
        return in_array($this->rol, [self::ROL_ADMIN, self::ROL_SUPER_ADMIN], true);
    }
}
