<?php

namespace Tests\Unit\Services\Odoo;

use App\Domain\Products\Product;
use App\Domain\Sync\SyncLog;
use App\Services\Odoo\OdooClientInterface;
use App\Services\Odoo\OdooResponse;
use App\Services\Odoo\OdooSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class OdooSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_cost_success_updates_product_state_and_logs(): void
    {
        Carbon::setTestNow('2025-10-21 09:00:00');

        $product = Product::withoutOdooSync(function () {
            return Product::create([
                'sku' => 'SKU-1001',
                'name' => 'Sample Product',
                'cost_price' => 10.00,
                'markup_percent' => 50.00,
                'currency' => 'USD',
            ]);
        });

        $client = new class implements OdooClientInterface {
            public array $calls = [];

            public function updateProductCost(string $sku, float $cost, float $salePrice, string $currency): OdooResponse
            {
                $this->calls[] = compact('sku', 'cost', 'salePrice', 'currency');

                return new OdooResponse(
                    ok: true,
                    payload: ['sku' => $sku],
                    response: ['status' => 'success'],
                    message: 'Test success'
                );
            }

            public function fetchProducts(array $filters = [], array $options = []): array
            {
                return [];
            }
        };

        $service = new OdooSyncService($client);

        $response = $service->syncCost($product->fresh());

        $this->assertTrue($response->isSuccess());

        $product->refresh();

        $this->assertEquals('local', $product->origin_system);
        $this->assertEquals('success', $product->last_sync_status);
        $this->assertEquals('push', $product->last_sync_direction);
        $this->assertSame('Test success', $product->last_sync_message);
        $this->assertNotNull($product->last_synced_at);
        $this->assertEquals(
            [
                'request' => [
                    'sku' => 'SKU-1001',
                    'cost_price' => 10.0,
                    'sale_price' => 15.0,
                    'markup_percent' => 50.0,
                    'currency' => 'USD',
                ],
                'response' => ['status' => 'success'],
            ],
            $product->last_sync_payload
        );

        $log = SyncLog::first();

        $this->assertNotNull($log);
        $this->assertEquals($product->id, $log->product_id);
        $this->assertEquals('SKU-1001', $log->sku);
        $this->assertEquals('success', $log->status);
        $this->assertEquals('push', $log->direction);
        $this->assertEquals('cost_update', $log->operation);
        $this->assertEquals('Test success', $log->message);

        Carbon::setTestNow();
    }

    public function test_sync_cost_failure_tracks_state_and_logs(): void
    {
        Carbon::setTestNow('2025-10-21 09:10:00');

        $product = Product::withoutOdooSync(function () {
            return Product::create([
                'sku' => 'SKU-FAIL',
                'name' => 'Failing Product',
                'cost_price' => 5.00,
                'markup_percent' => 20.00,
                'currency' => 'EUR',
            ]);
        });

        $client = new class implements OdooClientInterface {
            public function updateProductCost(string $sku, float $cost, float $salePrice, string $currency): OdooResponse
            {
                throw new RuntimeException('Boom');
            }

            public function fetchProducts(array $filters = [], array $options = []): array
            {
                return [];
            }
        };

        $service = new OdooSyncService($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Boom');

        try {
            $service->syncCost($product->fresh());
        } finally {
            $product->refresh();

            $this->assertEquals('failed', $product->last_sync_status);
            $this->assertEquals('push', $product->last_sync_direction);
            $this->assertEquals('Boom', $product->last_sync_message);
            $this->assertEquals(
                [
                    'request' => [
                        'sku' => 'SKU-FAIL',
                        'cost_price' => 5.0,
                        'sale_price' => 6.0,
                        'markup_percent' => 20.0,
                        'currency' => 'EUR',
                    ],
                    'exception' => [
                        'type' => RuntimeException::class,
                        'message' => 'Boom',
                    ],
                ],
                $product->last_sync_payload
            );

            $log = SyncLog::first();
            $this->assertNotNull($log);
            $this->assertEquals('failed', $log->status);
            $this->assertEquals('push', $log->direction);
            $this->assertEquals('cost_update', $log->operation);
            $this->assertEquals('Boom', $log->message);
        }

        Carbon::setTestNow();
    }

    public function test_pull_products_creates_and_updates_records(): void
    {
        Carbon::setTestNow('2025-10-21 10:00:00');

        Product::withoutOdooSync(function () {
            return Product::create([
                'sku' => 'SKU-1001',
                'name' => 'Local Name',
                'cost_price' => 10.00,
                'markup_percent' => 50.00,
                'currency' => 'USD',
            ]);
        });

        $client = new class implements OdooClientInterface {
            public array $fetchCalls = [];

            public function updateProductCost(string $sku, float $cost, float $salePrice, string $currency): OdooResponse
            {
                return new OdooResponse(true, [], []);
            }

            public function fetchProducts(array $filters = [], array $options = []): array
            {
                $this->fetchCalls[] = ['filters' => $filters, 'options' => $options];

                return [
                    [
                        'product_id' => 1,
                        'product_template_id' => 11,
                        'sku' => 'SKU-1001',
                        'name' => 'Updated Name',
                        'cost_price' => 20.00,
                        'sale_price' => 30.00,
                        'qty_available' => 5.0,
                        'currency' => 'EUR',
                        'write_date' => '2025-10-21 09:45:00',
                    ],
                    [
                        'product_id' => 2,
                        'product_template_id' => 22,
                        'sku' => 'SKU-NEW',
                        'name' => 'Brand New',
                        'cost_price' => 12.50,
                        'sale_price' => 18.00,
                        'qty_available' => 3.0,
                        'currency' => 'EUR',
                        'write_date' => '2025-10-21 09:30:00',
                    ],
                ];
            }
        };

        $service = new OdooSyncService($client);

        $summary = $service->pullProducts(
            filters: ['skus' => ['SKU-1001', 'SKU-NEW']],
            options: ['limit' => 50]
        );

        $this->assertSame(
            ['filters' => ['skus' => ['SKU-1001', 'SKU-NEW']], 'options' => ['limit' => 50]],
            $client->fetchCalls[0]
        );

        $this->assertEquals(2, Product::count());

        $updated = Product::where('sku', 'SKU-1001')->first();
        $this->assertNotNull($updated);
        $this->assertEquals('Updated Name', $updated->name);
        $this->assertEquals(20.00, $updated->cost_price);
        $this->assertEquals(30.00, $updated->sale_price);
        $this->assertEquals('odoo', $updated->origin_system);
        $this->assertEquals('pull', $updated->last_sync_direction);
        $this->assertEquals('success', $updated->last_sync_status);
        $this->assertSame('Imported from Odoo.', $updated->last_sync_message);

        $created = Product::where('sku', 'SKU-NEW')->first();
        $this->assertNotNull($created);
        $this->assertEquals('Brand New', $created->name);
        $this->assertEquals(12.50, $created->cost_price);
        $this->assertEquals(18.00, $created->sale_price);
        $this->assertEquals('odoo', $created->origin_system);
        $this->assertEquals('pull', $created->last_sync_direction);
        $this->assertEquals('success', $created->last_sync_status);
        $this->assertSame('Imported from Odoo.', $created->last_sync_message);

        $this->assertEquals([
            'fetched' => 2,
            'created' => 1,
            'updated' => 1,
            'unchanged' => 0,
            'errors' => [],
        ], $summary);

        $logs = SyncLog::orderBy('id')->get();
        $this->assertCount(2, $logs);

        $this->assertEquals('import_update', $logs[0]->operation);
        $this->assertEquals('SKU-1001', $logs[0]->sku);
        $this->assertEquals('pull', $logs[0]->direction);
        $this->assertEquals('Product updated from Odoo.', $logs[0]->message);

        $this->assertEquals('import_create', $logs[1]->operation);
        $this->assertEquals('SKU-NEW', $logs[1]->sku);
        $this->assertEquals('pull', $logs[1]->direction);
        $this->assertEquals('Product created from Odoo.', $logs[1]->message);

        Carbon::setTestNow();
    }
}
