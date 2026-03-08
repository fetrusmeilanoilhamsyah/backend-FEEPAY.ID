<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => 'required|string|exists:products,sku',

            // Hanya boleh angka, huruf, dan tanda minus — cegah injection
            'target_number' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9\-]+$/'],

            'customer_email' => 'required|email|max:255',

            // zone_id: opsional, hanya angka (Server ID game seperti ML, GI, HoK)
            'zone_id' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^\d+$/',
                function ($attribute, $value, $fail) {
                    $product = Product::where('sku', $this->sku)->first();
                    if (!$product) return;

                    // Game yang wajib zone_id — case-insensitive agar aman dari variasi nama Digiflazz
                    $needsZone = ['mobile legends', 'genshin impact', 'honor of kings'];
                    if (
                        $product->category === 'Games' &&
                        in_array(strtolower($product->brand ?? ''), $needsZone) &&
                        empty($value)
                    ) {
                        $fail('ID Server wajib diisi untuk produk ini.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.required'            => 'SKU produk wajib dipilih.',
            'sku.exists'              => 'Produk tidak ditemukan.',
            'target_number.required'  => 'Nomor tujuan atau User ID wajib diisi.',
            'target_number.regex'     => 'Nomor tujuan hanya boleh berisi huruf, angka, dan tanda minus.',
            'customer_email.required' => 'Alamat email wajib diisi.',
            'customer_email.email'    => 'Format email tidak valid.',
            'zone_id.regex'           => 'Zone ID hanya boleh berisi angka.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Data tidak valid, periksa kembali input Anda.',
            'errors'  => $validator->errors(),
        ], 422));
    }
}