<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventorySaleLine extends Model
{
    protected $fillable = [
        'inventory_sale_id',
        'inventory_lot_id',
        'owner_user_id',
        'tenant_company_id',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'inventory_sale_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'tenant_company_id');
    }

    public function movement(): HasOne
    {
        return $this->hasOne(InventoryMovement::class);
    }
}
