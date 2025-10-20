<?php

namespace App\Services\Odoo;

interface OdooClientInterface
{
    public function updateProductCost(string $sku, float $cost, float $salePrice, string $currency): OdooResponse;
}
