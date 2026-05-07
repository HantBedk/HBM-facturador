<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLot extends Model
{
    protected $fillable = [
        'owner_user_id',
        'tenant_company_id',
        'sku',
        'name',
        'description',
        'quantity_available',
        'unit_price',
        'is_active',
        'allow_sale',
        'allow_rental',
    ];

    protected function casts(): array
    {
        return [
            'quantity_available' => 'integer',
            'unit_price' => 'decimal:2',
            'is_active' => 'boolean',
            'allow_sale' => 'boolean',
            'allow_rental' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'tenant_company_id');
    }

    public function saleLines(): HasMany
    {
        return $this->hasMany(InventorySaleLine::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
