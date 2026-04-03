<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelNotification extends Model
{
    public const TYPE_SERVICE_CREATED = 'service_created';

    public const TYPE_INVOICE_DRAFT = 'invoice_draft';

    public const TYPE_INVOICE_PENDING_APPROVAL = 'invoice_pending_approval';

    public const TYPE_INVOICE_PENDING_SEND = 'invoice_pending_send';

    public const TYPE_INVOICE_PARTIAL_PAYMENT = 'invoice_partial_payment';

    public const TYPE_CUTOFF_APPROACHING = 'cutoff_approaching';

    public const TYPE_ALERT_DRAFTS_PENDING = 'alert_drafts_pending';

    public const TYPE_ALERT_APPROVED_UNSENT = 'alert_approved_unsent';

    public const TYPE_ALERT_SERVICES_ZERO = 'alert_services_zero_amount';

    public const TYPE_CUTOFF_AUTO_SENT = 'cutoff_auto_sent';

    protected $table = 'panel_notifications';

    protected $fillable = [
        'user_id',
        'type',
        'message',
        'read',
        'meta',
        'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'read' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
