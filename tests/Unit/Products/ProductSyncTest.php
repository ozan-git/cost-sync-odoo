<?php

namespace Tests\Unit\Products;

use App\Domain\Products\Product;
use App\Domain\Sync\SyncLog;
use App\Services\Odoo\OdooClientInterface;
use App\Services\Odoo\OdooResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class ProductSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_sale_price_change_triggers_sync_even_when_markup_is_unchanged(): void
    {
        Carbon::setTestNow('2025-01-01 12:00:00');

        $client = Mockery::mock(OdooClientInterface::class);
        $client->allows('fetchProducts')->andReturn([]);

        $calls = [];
        $client->shouldReceive('updateProductCost')
            ->andReturnUsing(function (string $sku, float $cost, float $salePrice, string $currency) use (&$calls) {
                $calls[] = compact('sku', 'cost', 'salePrice', 'currency');

                return new OdooResponse(
                    ok: true,
                    payload: ['sku' => $sku, 'sale_price' => $salePrice],
                    response: ['status' => 'success'],
                    message: 'Synced'
                );
            });

        $this->app->instance(OdooClientInterface::class, $client);

        $product = Product::withoutOdooSync(fn () => Product::create([
            'sku' => 'ZERO-1',
            'name' => 'Freebie',
            'cost_price' => 0.0,
            'markup_percent' => 0.0,
            'sale_price' => 0.0,
            'currency' => 'USD',
        ]));

        $product->sale_price = 15.0;
        $product->save();

        $this->assertTrue($product->wasChanged('sale_price'));

        $this->assertSame(1, SyncLog::count(), 'Expected sync log to be created after sale price change.');

        $product->refresh();

        $this->assertCount(1, $calls, 'Expected exactly one sync attempt.');
        $this->assertSame('ZERO-1', $calls[0]['sku']);
        $this->assertSame(0.0, $calls[0]['cost']);
        $this->assertSame(15.0, $calls[0]['salePrice']);
        $this->assertSame('USD', $calls[0]['currency']);

        $this->assertSame('success', $product->last_sync_status);
        $this->assertSame('push', $product->last_sync_direction);
        $this->assertSame('Synced', $product->last_sync_message);
        $this->assertNotNull($product->last_synced_at);

        $log = SyncLog::first();
        $this->assertNotNull($log);
        $this->assertSame('ZERO-1', $log->sku);
        $this->assertSame('success', $log->status);
        $this->assertSame('push', $log->direction);
        $this->assertSame('cost_update', $log->operation);
        $this->assertSame(15.0, (float) $log->payload['sale_price']);
    }
}
