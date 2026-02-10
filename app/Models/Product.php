<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    /**
     * Kolom yang boleh diisi (Mass Assignable)
     * Ditambahkan 'is_active' agar bisa diupdate via Admin Dashboard
     */
    protected $fillable = [
        'sku',
        'name',
        'category',
        'cost_price',
        'selling_price',
        'is_active'
    ];

    /**
     * Casting data agar formatnya sesuai saat keluar dari database
     */
    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_active' => 'boolean', // Penting agar frontend menerima true/false, bukan 0/1
    ];

    /**
     * Appends: Agar profit_margin otomatis muncul saat data dipanggil
     */
    protected $appends = ['profit_margin'];

    /**
     * Accessor untuk menghitung Profit secara otomatis
     */
    public function getProfitMarginAttribute(): float
    {
        return (float) ($this->selling_price - $this->cost_price);
    }

    /**
     * Scope untuk filter kategori produk
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}