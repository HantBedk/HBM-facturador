<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLot extends Model
{
    public const LIFECYCLE_ACTIVO = 'activo';
    public const LIFECYCLE_REPARACION = 'reparacion';
    public const LIFECYCLE_BAJA = 'baja';

    protected $fillable = [
        'owner_user_id',
        'tenant_company_id',
        'sku',
        'serial_number',
        'mac_address',
        'fingerprint_hash',
        'name',
        'description',
        'quantity_available',
        'unit_price',
        'is_active',
        'lifecycle_status',
        'lifecycle_status_changed_at',
        'repair_reason',
        'repair_resolution',
        'decommission_reason',
        'decommissioned_at',
        'decommissioned_by_user_id',
        'allow_sale',
        'allow_rental',
    ];

    protected function casts(): array
    {
        return [
            'quantity_available' => 'integer',
            'unit_price' => 'decimal:2',
            'is_active' => 'boolean',
            'lifecycle_status_changed_at' => 'datetime',
            'decommissioned_at' => 'datetime',
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

    public function decommissionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decommissioned_by_user_id');
    }

    public function saleLines(): HasMany
    {
        return $this->hasMany(InventorySaleLine::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function lifecycleRequests(): HasMany
    {
        return $this->hasMany(InventoryLifecycleTransitionRequest::class, 'inventory_lot_id');
    }

    /**
     * Tras una venta que deja existencias en cero: el activo deja de estar disponible (no «vuelve» como el mismo
     * producto; un ingreso nuevo debe ser otro lote). Los alquileres no llaman a este método: al cerrarse devuelven
     * unidades y el lote sigue en ciclo activo.
     */
    public function markExhaustedAfterFullSale(int $actorUserId): void
    {
        if ((int) $this->quantity_available !== 0) {
            return;
        }

        $this->is_active = false;
        $this->allow_sale = false;
        $this->allow_rental = false;
        $this->lifecycle_status = self::LIFECYCLE_BAJA;
        $this->lifecycle_status_changed_at = now();
        $this->decommission_reason = 'Agotado por venta (existencias en cero).';
        $this->decommissioned_at = now();
        $this->decommissioned_by_user_id = $actorUserId;
        $this->save();
    }
}
