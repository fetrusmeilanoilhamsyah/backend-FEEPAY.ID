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
        'zone_id',          // Tambahan untuk Game
        'customer_email',
        'total_price',
        'status',
        'sn',
        'payment_id',
        'confirmed_by',
        'confirmed_at',
        
        // Midtrans fields
        'midtrans_snap_token',
        'midtrans_transaction_id',
        'midtrans_payment_type',
        'midtrans_transaction_status',
        'midtrans_transaction_time',
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
        'status' => OrderStatus::class, // Menggunakan Enum OrderStatus Anda
        'confirmed_at' => 'datetime',
        'midtrans_transaction_time' => 'datetime',
    ];

    /**
     * Relasi ke sistem pembayaran
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Relasi ke admin yang konfirmasi
     */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * History status pesanan
     */
    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * Mencatat setiap perubahan status ke tabel history
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

    // Helper Status
    public function isPaid(): bool
    {
        return in_array($this->midtrans_transaction_status, ['capture', 'settlement']);
    }

    public function isProcessing(): bool
    {
        return $this->status === OrderStatus::PROCESSING;
    }
}