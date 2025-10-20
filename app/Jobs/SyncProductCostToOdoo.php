<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncProductCostToOdoo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 180, 360];

    public function __construct(
        public readonly int $productId
    ) {}

    public function handle(\App\Services\Odoo\OdooSyncService $syncService): void
    {
        $syncService->syncCostById($this->productId);
    }
}
