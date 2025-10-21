<?php

namespace App\Services\Odoo;

use App\Domain\Products\Product;
use App\Domain\Sync\SyncLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class OdooSyncService
{
    public function __construct(
        private readonly OdooClientInterface $client
    ) {}

    public function syncCostById(int $productId): void
    {
        $product = Product::find($productId);

        if (! $product) {
            SyncLog::create([
                'product_id' => null,
                'sku' => 'missing-'.$productId,
                'status' => 'failed',
                'direction' => 'push',
                'operation' => 'cost_update',
                'payload' => ['product_id' => $productId],
                'response' => [],
                'message' => 'Product not found for sync.',
            ]);

            return;
        }

        $this->syncCost($product);
    }

    public function syncCost(Product $product): OdooResponse
    {
        $payload = [
            'sku' => $product->sku,
            'cost_price' => round($product->cost_price, 2),
            'sale_price' => round($product->sale_price, 2),
            'markup_percent' => round($product->markup_percent, 2),
            'currency' => $product->currency,
        ];

        $this->markProductState($product, [
            'last_sync_status' => 'processing',
            'last_sync_direction' => 'push',
            'last_sync_message' => 'Pushing product to Odoo...',
        ]);

        try {
            $response = $this->client->updateProductCost(
                sku: $product->sku,
                cost: $product->cost_price,
                salePrice: $product->sale_price,
                currency: $product->currency,
            );

            SyncLog::create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'status' => $response->isSuccess() ? 'success' : 'failed',
                'direction' => 'push',
                'operation' => 'cost_update',
                'payload' => $payload,
                'response' => $response->response,
                'message' => $response->message,
            ]);

            if ($response->isSuccess()) {
                $this->markProductState($product, [
                    'origin_system' => 'local',
                    'last_synced_at' => now(),
                    'last_sync_status' => 'success',
                    'last_sync_direction' => 'push',
                    'last_sync_message' => $response->message ?? 'Odoo product cost updated.',
                    'last_sync_payload' => [
                        'request' => $payload,
                        'response' => $response->response,
                    ],
                ]);
            } else {
                $this->markProductState($product, [
                    'last_sync_status' => 'failed',
                    'last_sync_direction' => 'push',
                    'last_sync_message' => $response->message ?? 'Odoo product cost update failed.',
                    'last_sync_payload' => [
                        'request' => $payload,
                        'response' => $response->response,
                    ],
                ]);
            }

            return $response;
        } catch (Throwable $exception) {
            Log::error('Odoo sync failed', [
                'sku' => $product->sku,
                'exception' => $exception->getMessage(),
            ]);

            SyncLog::create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'status' => 'failed',
                'direction' => 'push',
                'operation' => 'cost_update',
                'payload' => $payload,
                'response' => [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'message' => $exception->getMessage(),
            ]);

            $this->markProductState($product, [
                'last_sync_status' => 'failed',
                'last_sync_direction' => 'push',
                'last_sync_message' => $exception->getMessage(),
                'last_sync_payload' => [
                    'request' => $payload,
                    'exception' => [
                        'type' => get_class($exception),
                        'message' => $exception->getMessage(),
                    ],
                ],
            ]);

            throw $exception;
        }
    }

    /**
     * @param  iterable<int, Product>  $products
     * @return array<string, mixed>
     */
    public function pushProducts(iterable $products): array
    {
        $summary = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'results' => [],
        ];

        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }

            $summary['total']++;

            try {
                $response = $this->syncCost($product);

                if ($response->isSuccess()) {
                    $summary['success']++;
                } else {
                    $summary['failed']++;
                }

                $summary['results'][] = [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'status' => $response->isSuccess() ? 'success' : 'failed',
                    'message' => $response->message,
                ];
            } catch (Throwable $exception) {
                $summary['failed']++;

                $summary['results'][] = [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function pullProducts(array $filters = [], array $options = []): array
    {
        $records = $this->client->fetchProducts($filters, $options);

        $summary = [
            'fetched' => count($records),
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => [],
        ];

        foreach ($records as $record) {
            try {
                $result = $this->importProductFromOdoo($record);

                if (! array_key_exists($result, $summary)) {
                    $summary[$result] = 0;
                }

                $summary[$result]++;
            } catch (Throwable $exception) {
                $summary['errors'][] = [
                    'sku' => $record['sku'] ?? null,
                    'message' => $exception->getMessage(),
                ];

                Log::error('Odoo import failed', [
                    'sku' => $record['sku'] ?? null,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    private function importProductFromOdoo(array $record): string
    {
        $sku = trim((string) ($record['sku'] ?? ''));

        if ($sku === '') {
            throw new \RuntimeException('Fetched product is missing a SKU.');
        }

        $cost = round((float) ($record['cost_price'] ?? 0), 2);
        $sale = round((float) ($record['sale_price'] ?? 0), 2);
        $currency = strtoupper((string) ($record['currency'] ?? config('services.odoo.currency', 'USD')));
        $markup = $cost > 0 ? round((($sale - $cost) / $cost) * 100, 2) : 0.0;

        $attributes = [
            'sku' => $sku,
            'name' => (string) ($record['name'] ?? $sku),
            'cost_price' => $cost,
            'markup_percent' => $markup,
            'currency' => $currency,
            'sale_price' => $sale,
        ];

        $existing = Product::where('sku', $sku)->first();

        $hasChanges = $existing
            ? $this->productHasDifferences($existing, $attributes)
            : true;

        $product = Product::withoutOdooSync(function () use ($existing, $attributes, $record) {
            $instance = $existing ?? new Product(['sku' => $attributes['sku']]);
            $instance->fill($attributes);
            $instance->origin_system = 'odoo';
            $instance->last_sync_direction = 'pull';
            $instance->last_sync_status = 'success';
            $instance->last_synced_at = now();
            $instance->last_sync_message = 'Imported from Odoo.';
            $instance->last_sync_payload = $record;
            $instance->save();

            return $instance;
        });

        SyncLog::create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'status' => 'success',
            'direction' => 'pull',
            'operation' => $existing ? ($hasChanges ? 'import_update' : 'import_touch') : 'import_create',
            'payload' => [
                'record' => $record,
            ],
            'response' => [],
            'message' => $existing
                ? ($hasChanges ? 'Product updated from Odoo.' : 'Product already up to date.')
                : 'Product created from Odoo.',
        ]);

        return $existing
            ? ($hasChanges ? 'updated' : 'unchanged')
            : 'created';
    }

    private function productHasDifferences(Product $product, array $incoming): bool
    {
        foreach (['name', 'cost_price', 'markup_percent', 'currency', 'sale_price'] as $field) {
            $current = $product->getAttribute($field);
            $new = $incoming[$field] ?? null;

            if (in_array($field, ['cost_price', 'markup_percent', 'sale_price'], true)) {
                $current = round((float) $current, 2);
                $new = round((float) $new, 2);
            }

            if ($current != $new) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function markProductState(Product $product, array $attributes): void
    {
        Product::withoutOdooSync(function () use ($product, $attributes): void {
            $product->forceFill($attributes)->save();
        });
    }
}
