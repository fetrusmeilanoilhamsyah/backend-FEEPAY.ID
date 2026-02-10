<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUsdtRateRequest;
use App\Models\UsdtRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UsdtRateController extends Controller
{
    /**
     * Get current active USDT rate (Public)
     * 
     * GET /api/usdt/rate
     */
    public function getCurrent()
    {
        try {
            $rate = UsdtRate::where('is_active', true)
                ->latest()
                ->first();

            if (!$rate) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active USDT rate found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'rate' => $rate->rate,
                    'updated_at' => $rate->created_at->toIso8601String(),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch USDT rate', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rate',
            ], 500);
        }
    }

    /**
     * Update USDT rate (Admin only)
     * 
     * POST /api/admin/x7k2m/usdt/rate
     */
    public function update(UpdateUsdtRateRequest $request)
    {
        try {
            DB::beginTransaction();

            // Deactivate all previous rates
            UsdtRate::where('is_active', true)->update(['is_active' => false]);

            // Create new active rate
            $rate = UsdtRate::create([
                'rate' => $request->rate,
                'is_active' => true,
                'note' => $request->note,
                'created_by' => $request->user()->id,
            ]);

            DB::commit();

            Log::info('USDT rate updated', [
                'new_rate' => $request->rate,
                'updated_by' => $request->user()->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'USDT rate updated successfully',
                'data' => [
                    'rate' => $rate->rate,
                    'note' => $rate->note,
                    'created_by' => $rate->createdBy->name,
                    'created_at' => $rate->created_at->toIso8601String(),
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to update USDT rate', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update rate: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get rate history (Admin only)
     * 
     * GET /api/admin/x7k2m/usdt/rate/history
     */
    public function history()
    {
        try {
            $rates = UsdtRate::with('createdBy')
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $rates,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch rate history', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch history',
            ], 500);
        }
    }
}