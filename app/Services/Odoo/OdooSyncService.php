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
                'sku' => 'missing-'.$productId,
                'status' => 'failed',
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

        try {
            $response = $this->client->updateProductCost(
                sku: $product->sku,
                cost: $product->cost_price,
                salePrice: $product->sale_price,
                currency: $product->currency,
            );

            SyncLog::create([
                'sku' => $product->sku,
                'status' => $response->isSuccess() ? 'success' : 'failed',
                'payload' => $payload,
                'response' => $response->response,
                'message' => $response->message,
            ]);

            return $response;
        } catch (Throwable $exception) {
            Log::error('Odoo sync failed', [
                'sku' => $product->sku,
                'exception' => $exception->getMessage(),
            ]);

            SyncLog::create([
                'sku' => $product->sku,
                'status' => 'failed',
                'payload' => $payload,
                'response' => [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
