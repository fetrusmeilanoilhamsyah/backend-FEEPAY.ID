<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'name',
        'category',
        'brand',         // Kolom Baru
        'cost_price',
        'selling_price',
        'status',        // Menggantikan is_active agar sinkron dengan Controller
        'stock',         // Kolom Baru
        'type'           // Kolom Baru untuk filter UI Game
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
    ];

    protected $appends = ['profit_margin'];

    public function getProfitMarginAttribute(): float
    {
        return (float) ($this->selling_price - $this->cost_price);
    }

    /**
     * Scope untuk mempermudah filter di Controller
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}