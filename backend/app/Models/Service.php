<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    public const STATUS_ACTIVO = 'activo';

    public const STATUS_CORREGIDO = 'corregido';

    public const STATUS_ELIMINADO = 'eliminado';

    protected $fillable = [
        'code',
        'company_id',
        'user_id',
        'client_name',
        'service_type',
        'description',
        'amount',
        'service_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ServicePhoto::class)->orderBy('sort_order');
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class)->withTimestamps();
    }

    public function scopeVisibles($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVO, self::STATUS_CORREGIDO]);
    }
}
