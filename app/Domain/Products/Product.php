<?php

namespace App\Domain\Products;

use App\Jobs\SyncProductCostToOdoo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'cost_price',
        'markup_percent',
        'currency',
        'origin_system',
        'last_synced_at',
        'last_sync_direction',
        'last_sync_status',
        'last_sync_payload',
        'last_sync_message',
    ];

    protected $casts = [
        'cost_price' => 'float',
        'markup_percent' => 'float',
        'sale_price' => 'float',
        'last_synced_at' => 'datetime',
        'last_sync_payload' => 'array',
    ];

    protected $attributes = [
        'origin_system' => 'local',
        'last_sync_status' => 'never',
    ];

    protected static bool $shouldDispatchOdooSync = true;

    public static function withoutOdooSync(callable $callback): mixed
    {
        $previous = static::$shouldDispatchOdooSync;
        static::$shouldDispatchOdooSync = false;

        try {
            return $callback();
        } finally {
            static::$shouldDispatchOdooSync = $previous;
        }
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            $product->cost_price = round((float) ($product->cost_price ?? 0), 2);
            $product->currency = strtoupper($product->currency ?? config('services.odoo.currency', 'USD'));

            if ($product->isDirty('sale_price')) {
                $sale = round((float) $product->sale_price, 2);
                $product->sale_price = $sale;

                if ($product->cost_price > 0) {
                    $markup = (($sale - $product->cost_price) / $product->cost_price) * 100;
                    $product->markup_percent = round($markup, 2);
                } else {
                    $product->markup_percent = 0;
                }
            } else {
                $product->markup_percent = round((float) ($product->markup_percent ?? 0), 2);
                $markup = ($product->markup_percent ?? 0) / 100;
                $product->sale_price = round(($product->cost_price ?? 0) * (1 + $markup), 2);
            }

            if (! $product->origin_system) {
                $product->origin_system = 'local';
            }

            if (static::$shouldDispatchOdooSync && $product->isDirty(['cost_price', 'markup_percent', 'currency', 'sale_price'])) {
                $product->last_sync_status = 'pending';
                $product->last_sync_direction = 'push';
                $product->last_sync_message = 'Awaiting push to Odoo.';
            }
        });

        static::saved(function (Product $product) {
            if (static::$shouldDispatchOdooSync && $product->wasChanged(['cost_price', 'markup_percent', 'currency'])) {
                SyncProductCostToOdoo::dispatchSync($product->id);
            }
        });
    }

    public function salePrice(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => $value !== null ? (float) $value : null,
        );
    }
}
