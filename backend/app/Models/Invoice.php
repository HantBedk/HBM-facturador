<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public const STATUS_BORRADOR = 'borrador';

    public const STATUS_APROBADA = 'aprobada';

    public const STATUS_ENVIADA = 'enviada';

    public const STATUS_PARCIALMENTE_PAGADA = 'parcialmente_pagada';

    public const STATUS_PAGADA = 'pagada';

    /** Estados visibles en consulta pública (no borrador). */
    public const PUBLIC_STATUSES = [
        self::STATUS_APROBADA,
        self::STATUS_ENVIADA,
        self::STATUS_PARCIALMENTE_PAGADA,
        self::STATUS_PAGADA,
    ];

    protected $hidden = [
        'public_access_token',
    ];

    protected $fillable = [
        'code',
        'company_id',
        'period_month',
        'period_year',
        'status',
        'subtotal',
        'total',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
