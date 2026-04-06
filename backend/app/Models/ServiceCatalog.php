<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCatalog extends Model
{
    public const STATUS_ACTIVO = 'activo';

    public const STATUS_INACTIVO = 'inactivo';

    protected $table = 'service_catalog';

    protected $fillable = [
        'name',
        'description',
        'base_price',
        'technician_discount_percent',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'technician_discount_percent' => 'decimal:2',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'catalog_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('status', self::STATUS_ACTIVO);
    }

}
