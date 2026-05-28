<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLifecycleTransitionRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const TARGET_REPARACION = 'reparacion';
    public const TARGET_BAJA = 'baja';
    public const TARGET_ACTIVO = 'activo';

    protected $fillable = [
        'inventory_lot_id',
        'tenant_company_id',
        'requested_by_user_id',
        'target_status',
        'status',
        'reason',
        'required_approvals',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'required_approvals' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'tenant_company_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(InventoryLifecycleRequestApproval::class, 'request_id');
    }
}

