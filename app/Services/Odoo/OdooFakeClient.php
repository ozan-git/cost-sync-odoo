<?php

namespace App\Services\Odoo;

use App\Domain\Products\Product;
use Illuminate\Support\Carbon;
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

    public function fetchProducts(array $filters = [], array $options = []): array
    {
        $skus = $this->normalizeSkus($filters['skus'] ?? null);

        $query = Product::query();

        if (! empty($skus)) {
            $query->whereIn('sku', $skus);
        }

        if ($updatedAfter = $this->resolveDateFilter($filters['updated_after'] ?? null)) {
            $query->where('updated_at', '>=', $updatedAfter);
        }

        if ($updatedBefore = $this->resolveDateFilter($filters['updated_before'] ?? null)) {
            $query->where('updated_at', '<=', $updatedBefore);
        }

        if (isset($options['offset'])) {
            $query->offset((int) $options['offset']);
        }

        if (isset($options['limit'])) {
            $query->limit((int) $options['limit']);
        }

        $products = $query
            ->orderByDesc('updated_at')
            ->orderBy('sku')
            ->get();

        if ($products->isEmpty() && empty($skus)) {
            return $this->fakeRemoteCatalog((int) ($options['limit'] ?? 5));
        }

        return $products->map(fn (Product $product) => $this->mapProduct($product))->all();
    }

    private function defaultCurrency(): string
    {
        $fromConfig = $this->config['currency'] ?? null;

        if (is_string($fromConfig) && $fromConfig !== '') {
            return strtoupper($fromConfig);
        }

        return strtoupper(config('services.odoo.currency', 'USD'));
    }

    private function fakeRemoteCatalog(int $limit): array
    {
        $count = max(1, min($limit, 10));
        $currency = $this->defaultCurrency();
        $now = Carbon::now();

        return collect(range(1, $count))->map(function (int $index) use ($currency, $now) {
            $cost = 10 + ($index * 1.5);
            $sale = round($cost * 1.2, 2);

            $sku = 'ODOO-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);

            return [
                'product_id' => 50_000 + $index,
                'product_template_id' => 60_000 + $index,
                'sku' => $sku,
                'name' => 'Remote product '.$index,
                'cost_price' => round($cost, 2),
                'sale_price' => $sale,
                'qty_available' => max(0, 120 - ($index * 3)),
                'currency' => $currency,
                'write_date' => $now->copy()->subMinutes($index)->toDateTimeString(),
                'raw' => [
                    'origin' => 'fake_odoo_catalog',
                ],
            ];
        })->all();
    }

    private function mapProduct(Product $product): array
    {
        $currency = $product->currency ?: $this->defaultCurrency();

        return [
            'product_id' => 10_000 + $product->id,
            'product_template_id' => 20_000 + $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'cost_price' => (float) $product->cost_price,
            'sale_price' => (float) $product->sale_price,
            'qty_available' => (float) ($product->getAttribute('qty_available') ?? random_int(5, 200)),
            'currency' => strtoupper($currency),
            'write_date' => optional($product->updated_at)->toDateTimeString(),
            'raw' => [
                'mirrored_from_local' => true,
            ],
        ];
    }

    private function normalizeSkus(mixed $skus): array
    {
        if (is_string($skus)) {
            $skus = preg_split('/[\s,]+/', $skus, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($skus)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($sku) => trim((string) $sku),
            $skus
        ), static fn ($sku) => $sku !== ''));
    }

    private function resolveDateFilter(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
