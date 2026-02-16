<?php

namespace App\Models;

use App\Enums\UsdtConversionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UsdtConversion extends Model
{
    use HasFactory;

    protected $fillable = [
        'trx_id',
        'amount',
        'network',
        'idr_received',
        'bank_details',
        'proof_path',
        'status',
        'admin_note',
        'approved_by',
        'approved_at',
        'customer_email',
        'customer_phone',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'idr_received' => 'decimal:2',
        'bank_details' => 'array',
        'status' => UsdtConversionStatus::class,
        'approved_at' => 'datetime',
    ];

    /**
     * Get the admin who approved this conversion
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope a query to filter by network
     */
    public function scopeNetwork($query, string $network)
    {
        return $query->where('network', $network);
    }

    /**
     * Scope for pending conversions
     */
    public function scopePending($query)
    {
        return $query->where('status', UsdtConversionStatus::PENDING->value);
    }

    /* * Method getProofUrlAttribute() dihapus.
     * File sekarang menggunakan private storage dan diakses melalui endpoint khusus admin.
     */
}