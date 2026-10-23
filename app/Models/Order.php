<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    protected $fillable = [
        'reference', 'customer_email', 'amount', 'currency', 'status', 'payment_id', 'paid_at', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
