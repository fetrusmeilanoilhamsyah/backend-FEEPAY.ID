<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'sku',
        'product_name',
        'target_number',
        'customer_email',
        'total_price',
        'status',
        'sn',
        'payment_id',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
        'status' => OrderStatus::class,
        'confirmed_at' => 'datetime',
    ];

    /**
     * Get the payment associated with this order
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the admin who confirmed this order
     */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Get the status histories for this order
     */
    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * Log status change
     */
    public function logStatusChange(OrderStatus $status, ?string $note = null, ?int $userId = null): void
    {
        OrderStatusHistory::create([
            'order_id' => $this->id,
            'status' => $status->value,
            'note' => $note,
            'changed_by' => $userId,
        ]);
    }

    /**
     * Scope a query to only include pending orders
     */
    public function scopePending($query)
    {
        return $query->where('status', OrderStatus::PENDING->value);
    }

    /**
     * Scope a query to only include successful orders
     */
    public function scopeSuccess($query)
    {
        return $query->where('status', OrderStatus::SUCCESS->value);
    }

    /**
     * Scope a query to only include failed orders
     */
    public function scopeFailed($query)
    {
        return $query->where('status', OrderStatus::FAILED->value);
    }

    /**
     * Mark order as successful
     */
    public function markAsSuccess(?int $userId = null): bool
    {
        $this->logStatusChange(OrderStatus::SUCCESS, 'Order processed successfully', $userId);
        return $this->update(['status' => OrderStatus::SUCCESS->value]);
    }

    /**
     * Mark order as failed
     */
    public function markAsFailed(?int $userId = null, ?string $reason = null): bool
    {
        $this->logStatusChange(OrderStatus::FAILED, $reason ?? 'Order failed', $userId);
        return $this->update(['status' => OrderStatus::FAILED->value]);
    }
}