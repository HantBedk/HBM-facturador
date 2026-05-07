<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAuditEvent extends Model
{
    protected $fillable = [
        'tenant_company_id',
        'entity_type',
        'entity_id',
        'action',
        'actor_user_id',
        'request_id',
        'ip_address',
        'user_agent',
        'before',
        'after',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'tenant_company_id');
    }
}
