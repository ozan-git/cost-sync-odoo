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
    ];

    protected $casts = [
        'cost_price' => 'float',
        'markup_percent' => 'float',
        'sale_price' => 'float',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            $product->markup_percent = $product->markup_percent ?? 0;
            $product->cost_price = $product->cost_price ?? 0;
            $product->currency = strtoupper($product->currency ?? config('services.odoo.currency', 'USD'));

            $markup = ($product->markup_percent ?? 0) / 100;
            $product->sale_price = round(($product->cost_price ?? 0) * (1 + $markup), 2);
        });

        static::saved(function (Product $product) {
            if ($product->wasChanged(['cost_price', 'markup_percent', 'currency'])) {
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
