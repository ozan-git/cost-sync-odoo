<?php

namespace App\Services\Odoo;

use Illuminate\Support\Str;

class OdooFakeClient implements OdooClientInterface
{
    public function __construct(
        protected readonly array $config = []
    ) {}

    public function updateProductCost(string $sku, float $cost, float $salePrice, string $currency): OdooResponse
    {
        usleep(random_int(150_000, 300_000));

        $payload = [
            'reference' => $sku,
            'cost' => $cost,
            'sale_price' => $salePrice,
            'currency' => $currency,
            'request_id' => (string) Str::uuid(),
        ];

        $shouldFail = random_int(1, 10) === 1;

        if ($shouldFail) {
            return new OdooResponse(
                ok: false,
                payload: $payload,
                response: [
                    'status' => 'error',
                    'code' => 500,
                    'detail' => 'Simulated Odoo failure.',
                ],
                message: 'Simulated Odoo failure.',
            );
        }

        return new OdooResponse(
            ok: true,
            payload: $payload,
            response: [
                'status' => 'success',
                'synced_at' => now()->toIso8601String(),
            ],
            message: 'Odoo cost updated.',
        );
    }
}
