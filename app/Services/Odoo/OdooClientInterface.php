<?php

namespace App\Services\Odoo;

interface OdooClientInterface
{
    public function updateProductCost(string $sku, float $cost, float $salePrice, string $currency): OdooResponse;

    /**
     * Fetch products from Odoo using optional filters (e.g., ['skus' => [...], 'updated_after' => Carbon]).
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    public function fetchProducts(array $filters = [], array $options = []): array;
}
