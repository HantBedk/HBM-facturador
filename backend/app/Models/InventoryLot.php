<?php

namespace App\Models;

use App\Support\InventoryLotInternalCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote de inventario (operación interna o por empresa cliente vía `tenant_company_id`).
 *
 * Roadmap inventario-empresa (migrar desde `description` a columnas): tipo_activo, subcategoria, marca, modelo,
 * codigo_interno_dedicado, estado_fisico, condicion_compra, garantia_hasta, fecha_compra, fecha_ingreso_custodia,
 * responsable_empresa, sede, area, notas_internas — manteniendo `description` solo como texto libre opcional.
 */
class InventoryLot extends Model
{
    public const LIFECYCLE_ACTIVO = 'activo';

    /** Existencias agotadas por venta (distinto de baja por inservible). */
    public const LIFECYCLE_VENDIDO = 'vendido';

    public const LIFECYCLE_REPARACION = 'reparacion';

    public const LIFECYCLE_BAJA = 'baja';

    public const REPAIR_DAMAGE_HARDWARE = 'hardware';

    public const REPAIR_DAMAGE_SOFTWARE = 'software';

    /** Columna `sku` se mantiene en BD como referencia interna; no se expone en JSON. */
    protected $hidden = [
        'sku',
    ];

    protected $appends = [
        'internal_code',
    ];

    protected $fillable = [
        'owner_user_id',
        'tenant_company_id',
        'sku',
        'serial_number',
        'mac_address',
        'fingerprint_hash',
        'name',
        'description',
        'asset_type',
        'asset_subtype',
        'brand',
        'model',
        'site_label',
        'area_label',
        'physical_condition',
        'repair_damage_kind',
        'warranty_until',
        'purchase_date',
        'custody_received_at',
        'responsible_name',
        'responsible_role',
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
            'warranty_until' => 'date',
            'purchase_date' => 'date',
            'custody_received_at' => 'date',
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

    public function attachments(): HasMany
    {
        return $this->hasMany(InventoryLotAttachment::class, 'inventory_lot_id')->orderByDesc('id');
    }

    public function lifecycleRequests(): HasMany
    {
        return $this->hasMany(InventoryLifecycleTransitionRequest::class, 'inventory_lot_id');
    }

    public function getInternalCodeAttribute(): string
    {
        return InventoryLotInternalCode::resolve(
            $this->description,
            $this->sku,
            (int) $this->id,
            $this->serial_number,
        );
    }

    /**
     * Tras una venta que deja existencias en cero: el lote queda marcado como vendido (no «baja» por daño).
     * Los alquileres no llaman a este método: al cerrarse devuelven unidades y el lote sigue activo.
     */
    public function markExhaustedAfterFullSale(int $actorUserId): void
    {
        if ((int) $this->quantity_available !== 0) {
            return;
        }

        $this->is_active = false;
        $this->allow_sale = false;
        $this->allow_rental = false;
        $this->lifecycle_status = self::LIFECYCLE_VENDIDO;
        $this->lifecycle_status_changed_at = now();
        // No usar decommission_*: eso es solo para retiro/baja por inservible.
        $this->decommission_reason = null;
        $this->decommissioned_at = null;
        $this->decommissioned_by_user_id = null;
        $this->save();
    }

    /**
     * Al anular una venta que había dejado el lote en «vendido», restaurar disponibilidad operativa.
     */
    public function restoreAfterSaleVoid(int $qtyReturned, int $actorUserId): void
    {
        $this->quantity_available += $qtyReturned;
        if ($this->lifecycle_status === self::LIFECYCLE_VENDIDO) {
            $this->lifecycle_status = self::LIFECYCLE_ACTIVO;
            $this->is_active = true;
            $this->allow_sale = true;
            $this->allow_rental = false;
            $this->lifecycle_status_changed_at = now();
            $this->decommission_reason = null;
            $this->decommissioned_at = null;
            $this->decommissioned_by_user_id = null;
        }
        $this->save();
    }
}
