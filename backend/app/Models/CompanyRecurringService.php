<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de cargo fijo mensual por empresa (membresía, internet, etc.).
 * La automatización de borradores materializa un {@see Service} por periodo facturado.
 */
class CompanyRecurringService extends Model
{
    protected $fillable = [
        'company_id',
        'catalog_id',
        /** venta | servicio | alquiler: clasificación del cargo fijo (prefijo de código al materializar). */
        'billing_kind',
        'service_type',
        'description',
        'amount',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalog::class, 'catalog_id');
    }

    public function materializedServices(): HasMany
    {
        return $this->hasMany(Service::class, 'recurring_service_id');
    }
}
