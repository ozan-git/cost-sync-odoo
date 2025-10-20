<?php

namespace Database\Seeders;

use App\Domain\Products\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currency = config('services.odoo.currency', 'USD');

        $products = [
            ['sku' => 'SKU-1001', 'name' => 'Eco Water Bottle', 'cost_price' => 4.50, 'markup_percent' => 120],
            ['sku' => 'SKU-1002', 'name' => 'Adventure Backpack', 'cost_price' => 28.25, 'markup_percent' => 70],
            ['sku' => 'SKU-1003', 'name' => 'Wireless Earbuds', 'cost_price' => 18.90, 'markup_percent' => 95],
            ['sku' => 'SKU-1004', 'name' => 'Travel Mug', 'cost_price' => 6.10, 'markup_percent' => 110],
            ['sku' => 'SKU-1005', 'name' => 'Desk Lamp', 'cost_price' => 11.75, 'markup_percent' => 80],
            ['sku' => 'SKU-1006', 'name' => 'Yoga Mat', 'cost_price' => 9.40, 'markup_percent' => 90],
            ['sku' => 'SKU-1007', 'name' => 'Smart Notebook', 'cost_price' => 14.60, 'markup_percent' => 85],
            ['sku' => 'SKU-1008', 'name' => 'Portable Charger', 'cost_price' => 12.30, 'markup_percent' => 100],
        ];

        foreach ($products as $data) {
            Product::updateOrCreate(
                ['sku' => $data['sku']],
                [
                    'name' => $data['name'],
                    'cost_price' => $data['cost_price'],
                    'markup_percent' => $data['markup_percent'],
                    'currency' => $currency,
                ]
            );
        }
    }
}
