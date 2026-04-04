<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceItem extends Model
{
    protected $fillable = [
        'service_id',
        'catalog_id',
        'catalog_suggestion_id',
        'label',
        'line_description',
        'amount',
        'technician_line_amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'technician_line_amount' => 'decimal:2',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalog::class, 'catalog_id');
    }

    public function catalogSuggestion(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogSuggestion::class, 'catalog_suggestion_id');
    }
}
