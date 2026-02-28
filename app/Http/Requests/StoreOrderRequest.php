<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\Product;

class StoreOrderRequest extends FormRequest
{
    /**
     * Tentukan apakah pengguna diizinkan untuk membuat request ini.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi yang berlaku untuk request.
     */
    public function rules(): array
    {
        return [
            'sku' => 'required|string|exists:products,sku',
            'target_number' => 'required|string|max:50',
            'customer_email' => 'required|email|max:255',
            
            // Validasi dinamis untuk Zone ID (Server ID)
            'zone_id' => [
                'nullable',
                'string',
                'max:20',
                function ($attribute, $value, $fail) {
                    $product = Product::where('sku', $this->sku)->first();
                    
                    // Jika kategori adalah 'Games' dan brand membutuhkan Server ID
                    if ($product && $product->category === 'Games') {
                        $needsZone = ['Mobile Legends', 'Genshin Impact', 'HOK']; // Daftar game dengan Zone ID
                        
                        if (in_array($product->brand, $needsZone) && empty($value)) {
                            $fail('ID Server (Zone ID) wajib diisi untuk produk ini.');
                        }
                    }
                },
            ],
        ];
    }

    /**
     * Pesan kesalahan kustom untuk validasi.
     */
    public function messages(): array
    {
        return [
            'sku.required' => 'SKU produk wajib dipilih.',
            'sku.exists' => 'Produk tidak ditemukan dalam sistem.',
            'target_number.required' => 'Nomor tujuan atau User ID wajib diisi.',
            'customer_email.required' => 'Alamat email wajib diisi.',
            'customer_email.email' => 'Format email tidak valid.',
        ];
    }

    /**
     * Tangani kegagalan validasi dan kirim respon JSON.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validasi gagal, silakan periksa input Anda.',
            'errors' => $validator->errors(),
        ], 422));
    }
}