<?php

namespace App\Http\Requests;

use App\Enums\UsdtNetwork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Enum;

class StoreUsdtExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:1',
            'network' => ['required', new Enum(UsdtNetwork::class)],
            'idr_received' => 'required|numeric|min:1',
            'bank_name' => 'required|string|max:100',
            'account_number' => 'required|string|max:50',
            'account_name' => 'required|string|max:255',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB max
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'USDT amount is required',
            'amount.numeric' => 'Amount must be a number',
            'amount.min' => 'Amount must be at least 1 USDT',
            'network.required' => 'Network is required',
            'idr_received.required' => 'IDR amount is required',
            'bank_name.required' => 'Bank name is required',
            'account_number.required' => 'Account number is required',
            'account_name.required' => 'Account name is required',
            'proof.required' => 'Transfer proof is required',
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