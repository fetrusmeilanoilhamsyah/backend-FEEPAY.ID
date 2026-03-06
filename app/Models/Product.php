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
        'brand',
        'cost_price',
        'selling_price',
        'status',
        'stock',
        'type',
    ];

    protected $casts = [
        'cost_price'    => 'decimal:2',
        'selling_price' => 'decimal:2',
    ];

    protected $appends = ['profit_margin'];

    public function getProfitMarginAttribute(): float
    {
        return (float) ($this->selling_price - $this->cost_price);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
