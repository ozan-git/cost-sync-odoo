<?php

namespace App\Domain\Sync;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $fillable = [
        'sku',
        'status',
        'payload',
        'response',
        'message',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
    ];
}
