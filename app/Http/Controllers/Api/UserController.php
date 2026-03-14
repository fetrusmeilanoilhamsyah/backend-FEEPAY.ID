<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /api/admin/{path}/users
     * Daftar pengguna terdaftar.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $users = User::orderBy('created_at', 'desc')->paginate(50);
            return response()->json([
                'success' => true,
                'data'    => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pengguna.'
            ], 500);
        }
    }
}
