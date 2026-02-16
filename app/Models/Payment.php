<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'type',
        'amount',
        'proof_path',
        'status',
        'admin_note',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'verified_at' => 'datetime',
    ];

    /**
     * Get the order associated with this payment
     */
    public function order()
    {
        return $this->hasOne(Order::class);
    }

    /**
     * Get the admin who verified this payment
     */
    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Get the proof URL
     * DEPRECATED: Files are now in private storage, use downloadProof endpoint instead
     */
    // public function getProofUrlAttribute(): ?string
    // {
    //     return $this->proof_path 
    //         ? asset('storage/' . $this->proof_path) 
    //         : null;
    // }

    /**
     * Scope for pending payments
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for verified payments
     */
    public function scopeVerified($query)
    {
        return $query->where('status', 'verified');
    }
}