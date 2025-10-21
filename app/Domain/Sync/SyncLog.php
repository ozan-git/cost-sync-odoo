<?php

namespace App\Domain\Sync;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'status',
        'payload',
        'response',
        'message',
        'direction',
        'operation',
    ];

    protected $casts = [
        'product_id' => 'int',
        'payload' => 'array',
        'response' => 'array',
    ];
}
