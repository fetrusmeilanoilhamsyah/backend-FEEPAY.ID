<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UsdtRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'rate',
        'is_active',
        'note',
        'created_by',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the active rate
     */
    public static function getActiveRate(): ?float
    {
        $activeRate = self::where('is_active', true)
            ->latest()
            ->first();

        return $activeRate ? (float) $activeRate->rate : null;
    }

    /**
     * Get the admin who created this rate
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope for active rates
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}