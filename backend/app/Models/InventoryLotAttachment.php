<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLotAttachment extends Model
{
    protected $fillable = [
        'inventory_lot_id',
        'original_filename',
        'path',
        'mime_type',
        'size_bytes',
        'uploaded_by_user_id',
        'inventory_audit_event_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function auditEvent(): BelongsTo
    {
        return $this->belongsTo(InventoryAuditEvent::class, 'inventory_audit_event_id');
    }
}
