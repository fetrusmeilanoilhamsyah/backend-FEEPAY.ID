<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'status',
        'note',
        'changed_by',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
    ];

    /**
     * Get the order that owns this history
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the admin who made this change
     */
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}