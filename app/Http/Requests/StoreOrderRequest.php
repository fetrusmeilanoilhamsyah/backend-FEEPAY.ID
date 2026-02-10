<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
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
            'sku' => 'required|string|max:100',
            'target_number' => 'required|string|max:50',
            'customer_email' => 'required|email|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'sku.required' => 'Product SKU is required',
            'target_number.required' => 'Target number is required',
            'customer_email.required' => 'Email is required',
            'customer_email.email' => 'Email must be valid',
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