<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventorySale extends Model
{
    protected $fillable = [
        'sold_by_user_id',
        'tenant_company_id',
        'notes',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
        ];
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_user_id');
    }

    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'tenant_company_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventorySaleLine::class, 'inventory_sale_id');
    }
}
