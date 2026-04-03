<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    public const KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT = 'technician_catalog_discount_percent';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getFloat(string $key, float $default = 0.0): float
    {
        $row = static::query()->where('key', $key)->first();
        if ($row === null || $row->value === '') {
            return $default;
        }

        return (float) $row->value;
    }

    public static function setValue(string $key, string $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
