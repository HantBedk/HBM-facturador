<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCatalogSuggestion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'description',
        'suggested_price',
        'status',
        'resolved_catalog_id',
    ];

    protected function casts(): array
    {
        return [
            'suggested_price' => 'decimal:2',
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

    public function resolvedCatalog(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalog::class, 'resolved_catalog_id');
    }

    public function serviceItems(): HasMany
    {
        return $this->hasMany(ServiceItem::class, 'catalog_suggestion_id');
    }
}
