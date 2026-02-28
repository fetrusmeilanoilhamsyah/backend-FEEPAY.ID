<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'sku', 'product_name', 'target_number', 'zone_id',
        'customer_email', 'total_price', 'status', 'sn', 'payment_id',
        'confirmed_by', 'confirmed_at', 'midtrans_snap_token',
        'midtrans_transaction_id', 'midtrans_payment_type',
        'midtrans_transaction_status', 'midtrans_transaction_time',
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
        'status' => OrderStatus::class,
        'confirmed_at' => 'datetime',
        'midtrans_transaction_time' => 'datetime',
    ];

    public function statusHistories() 
    { 
        return $this->hasMany(OrderStatusHistory::class); 
    }

    public function logStatusChange(OrderStatus $status, ?string $note = null, ?int $userId = null): void
    {
        OrderStatusHistory::create([
            'order_id' => $this->id,
            'status' => $status->value,
            'note' => $note,
            'changed_by' => $userId,
        ]);
    }

    public function hasMidtransToken(): bool 
    { 
        return !empty($this->midtrans_snap_token); 
    }

    // --- QUERY SCOPES UNTUK STATISTIK DASHBOARD ADMIN ---
    public function scopePending($query) 
    { 
        return $query->where('status', 'pending'); 
    }

    public function scopeProcessing($query) 
    { 
        return $query->where('status', 'processing'); 
    }

    public function scopeSuccess($query) 
    { 
        return $query->where('status', 'success'); 
    }

    public function scopeFailed($query) 
    { 
        return $query->where('status', 'failed'); 
    }
}
