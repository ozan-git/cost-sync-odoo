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
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable()
                ->after('id')
                ->constrained('products')
                ->nullOnDelete();
            $table->string('direction')->default('push')->after('sku');
            $table->string('operation')->default('cost_update')->after('direction');

            $table->index('direction');
            $table->index('operation');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropIndex(['direction']);
            $table->dropIndex(['operation']);
            $table->dropIndex(['product_id']);

            $table->dropColumn('direction');
            $table->dropColumn('operation');

            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });
    }
};
