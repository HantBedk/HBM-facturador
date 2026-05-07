<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public const TYPE_ALTA = 'alta';

    public const TYPE_AJUSTE = 'ajuste';

    public const TYPE_VENTA = 'venta';
    public const TYPE_ALQUILER_SALIDA = 'alquiler_salida';
    public const TYPE_ALQUILER_DEVOLUCION = 'alquiler_devolucion';
    public const TYPE_REPARACION = 'reparacion';
    public const TYPE_REACTIVACION = 'reactivacion';
    public const TYPE_BAJA = 'baja';

    public $timestamps = false;

    protected $fillable = [
        'inventory_lot_id',
        'user_id',
        'tenant_company_id',
        'type',
        'quantity_delta',
        'inventory_sale_line_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'tenant_company_id');
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(InventorySaleLine::class, 'inventory_sale_line_id');
    }
}
