<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_INACTIVO = 'inactivo';

    protected $fillable = [
        'nombre',
        'factura_sigla',
        'nit',
        'estado',
        'telefono',
        'correo',
        /** Cliente puntual (sin alta formal); `telefono_normalizado` agrupa por teléfono. */
        'es_cliente_puntual',
        'telefono_normalizado',
    ];

    protected function casts(): array
    {
        return [
            'es_cliente_puntual' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Company $company) {
            if ($company->factura_sigla !== null && $company->factura_sigla !== '') {
                $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $company->factura_sigla) ?? '');
                $company->factura_sigla = strlen($letters) >= 3 ? substr($letters, 0, 3) : str_pad($letters, 3, 'X');
            }
        });
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('estado', self::ESTADO_ACTIVO);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function recurringServices(): HasMany
    {
        return $this->hasMany(CompanyRecurringService::class)->orderBy('sort_order')->orderBy('id');
    }
}
