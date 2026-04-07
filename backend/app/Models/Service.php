<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    public const STATUS_ACTIVO = 'activo';

    public const STATUS_CORREGIDO = 'corregido';

    public const STATUS_ELIMINADO = 'eliminado';

    protected $fillable = [
        'code',
        'company_id',
        'user_id',
        'catalog_id',
        'client_name',
        'service_type',
        'description',
        'amount',
        'service_date',
        'status',
        /** Marca contable interna: cuándo se registró el abono/pago al técnico (no sustituye nómina ni comprobantes). */
        'technician_paid_at',
        /** Origen plantilla mensual (servicio fijo); null si lo cargó un técnico. */
        'recurring_service_id',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'amount' => 'decimal:2',
            'technician_paid_at' => 'datetime',
        ];
    }

    /**
     * Importe de referencia pagadero al técnico (coherente con ServiceResource::technician_line_total).
     *
     * Requiere `withCount('items')` y `withSum('items', 'technician_line_amount')` cuando se usa desde listados.
     */
    public function technicianReferenceTotalValue(): float
    {
        if ($this->relationLoaded('items') && $this->items->isNotEmpty()) {
            return (float) $this->items->sum(fn ($i) => (float) ($i->technician_line_amount ?? 0));
        }

        $cnt = (int) ($this->items_count ?? 0);
        $sum = $this->items_sum_technician_line_amount;
        if ($cnt > 0 && $sum !== null) {
            return (float) $sum;
        }
        if ($cnt > 0) {
            return (float) $this->amount;
        }

        return (float) $this->amount;
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalog::class, 'catalog_id');
    }

    public function recurringTemplate(): BelongsTo
    {
        return $this->belongsTo(CompanyRecurringService::class, 'recurring_service_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ServicePhoto::class)->orderBy('sort_order');
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class)->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceItem::class)->orderBy('sort_order');
    }

    public function scopeVisibles($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVO, self::STATUS_CORREGIDO]);
    }
}
