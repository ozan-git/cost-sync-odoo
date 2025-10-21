<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('origin_system')->default('local')->after('sale_price');
            $table->string('last_sync_status')->default('never')->after('origin_system');
            $table->string('last_sync_direction')->nullable()->after('last_sync_status');
            $table->timestamp('last_synced_at')->nullable()->after('last_sync_direction');
            $table->json('last_sync_payload')->nullable()->after('last_synced_at');
            $table->string('last_sync_message')->nullable()->after('last_sync_payload');

            $table->index('origin_system');
            $table->index('last_sync_status');
            $table->index('last_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['origin_system']);
            $table->dropIndex(['last_sync_status']);
            $table->dropIndex(['last_synced_at']);

            $table->dropColumn([
                'origin_system',
                'last_sync_status',
                'last_sync_direction',
                'last_synced_at',
                'last_sync_payload',
                'last_sync_message',
            ]);
        });
    }
};
