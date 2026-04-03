<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCatalog extends Model
{
    public const STATUS_ACTIVO = 'activo';

    public const STATUS_INACTIVO = 'inactivo';

    protected $table = 'service_catalog';

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'base_price',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'catalog_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('status', self::STATUS_ACTIVO);
    }

    /** Ítems globales (empresa) o de una empresa concreta. */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where(function ($q) use ($companyId) {
            $q->whereNull('company_id')
                ->orWhere('company_id', $companyId);
        });
    }
}
