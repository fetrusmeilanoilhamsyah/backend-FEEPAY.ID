<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|string|exists:orders,order_id',
            'type' => 'required|in:bank_transfer,qris',
            // ✅ FIX C-02: 'amount' dihapus — tidak boleh diterima dari user
            'proof' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
                // ✅ FIX L-01: Cek magic bytes — isi file beneran, bukan cuma ekstensi
                // Mencegah hacker rename shell.php jadi bukti.jpg
                function ($attribute, $value, $fail) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $value->getPathname());
                    finfo_close($finfo);

                    $allowed = ['image/jpeg', 'image/png', 'application/pdf'];
                    if (!in_array($mimeType, $allowed)) {
                        $fail('File tidak valid atau telah dimanipulasi');
                    }
                }
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'order_id.required' => 'Order ID is required',
            'order_id.exists' => 'Order not found',
            'type.required' => 'Payment type is required',
            'type.in' => 'Payment type must be bank_transfer or qris',
            'proof.required' => 'Payment proof is required',
            'proof.mimes' => 'Proof must be JPG, PNG, or PDF',
            'proof.max' => 'Proof file size must not exceed 5MB',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}