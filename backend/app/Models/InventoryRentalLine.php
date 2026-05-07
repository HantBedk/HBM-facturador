<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryRentalLine extends Model
{
    protected $fillable = [
        'inventory_rental_id',
        'inventory_lot_id',
        'owner_user_id',
        'tenant_company_id',
        'quantity',
        'unit_price',
        'line_total',
        'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'returned_at' => 'datetime',
        ];
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(InventoryRental::class, 'inventory_rental_id');
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
}
