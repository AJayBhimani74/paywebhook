<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public const RECEIVED = 'received';

    public const PROCESSED = 'processed';

    public const IGNORED = 'ignored';

    public const FAILED = 'failed';

    protected $fillable = ['event_id', 'type', 'payload', 'status', 'attempts', 'error', 'processed_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
