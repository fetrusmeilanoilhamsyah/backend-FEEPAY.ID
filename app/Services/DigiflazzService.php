<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use Exception;

class DigiflazzService
{
    protected string $username;
    protected string $apiKey;
    protected string $baseUrl;
    protected string $signature;

    public function __construct()
    {
        $this->username = config('services.digiflazz.username');
        $this->apiKey = config('services.digiflazz.api_key');
        $this->baseUrl = config('services.digiflazz.base_url', 'https://api.digiflazz.com/v1');
        
        // Generate signature (MD5 of username + apiKey + 'df')
        $this->signature = md5($this->username . $this->apiKey . 'df');
    }

    /**
     * Get product price from LOCAL database (NOT API)
     * 
     * @param string $sku Product SKU
     * @return array
     */
    public function getProductPrice(string $sku): array
    {
        try {
            $product = Product::where('sku', $sku)->first();

            if (!$product) {
                throw new Exception("Product with SKU '{$sku}' not found in database");
            }

            Log::info('Product price fetched from database', ['sku' => $sku]);

            return [
                'success' => true,
                'data' => [
                    'buyer_sku_code' => $product->sku,
                    'product_name' => $product->name,
                    'category' => $product->category,
                    'brand' => $product->brand,
                    'price' => (float) $product->cost_price,
                    'seller_price' => (float) $product->selling_price,
                    'buyer_product_status' => $product->status === 'active',
                    'seller_product_status' => $product->status === 'active',
                ],
            ];

        } catch (Exception $e) {
            Log::error('Failed to get product price from database', [
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Get price list from Digiflazz API
     * 
     * @param string|null $category Filter by category (optional)
     * @return array
     */
    public function getPriceList(?string $category = null): array
    {
        try {
            $payload = [
                'cmd' => 'prepaid',
                'username' => $this->username,
                'sign' => $this->signature,
            ];

            $response = Http::timeout(30)
                ->post("{$this->baseUrl}/price-list", $payload);

            if (!$response->successful()) {
                throw new Exception('Failed to fetch price list from Digiflazz: ' . $response->body());
            }

            $data = $response->json();

            if (!isset($data['data']) || !is_array($data['data'])) {
                throw new Exception('Invalid response format from Digiflazz');
            }

            // Filter by category if provided
            if ($category) {
                $data['data'] = array_filter($data['data'], function ($item) use ($category) {
                    return isset($item['category']) && 
                           strtolower($item['category']) === strtolower($category);
                });
            }

            Log::info('Digiflazz price list fetched', [
                'count' => count($data['data']),
                'category' => $category,
            ]);

            return [
                'success' => true,
                'data' => array_values($data['data']),
            ];

        } catch (Exception $e) {
            Log::error('Digiflazz getPriceList error', [
                'message' => $e->getMessage(),
                'category' => $category,
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * Place order to Digiflazz (REAL API)
     * 
     * @param string $sku Product SKU
     * @param string $targetNumber Customer number/ID
     * @param string $refId Reference ID (unique order ID)
     * @return array
     */
    public function placeOrder(string $sku, string $targetNumber, string $refId): array
    {
        try {
            $payload = [
                'username' => $this->username,
                'buyer_sku_code' => $sku,
                'customer_no' => $targetNumber,
                'ref_id' => $refId,
                'sign' => md5($this->username . $this->apiKey . $refId),
            ];

            $response = Http::timeout(60)
                ->post("{$this->baseUrl}/transaction", $payload);

            if (!$response->successful()) {
                throw new Exception('Failed to place order: ' . $response->body());
            }

            $data = $response->json();

            Log::info('Digiflazz order placed', [
                'ref_id' => $refId,
                'sku' => $sku,
                'target' => $targetNumber,
                'response' => $data,
            ]);

            return [
                'success' => true,
                'data' => $data['data'] ?? $data,
            ];

        } catch (Exception $e) {
            Log::error('Digiflazz placeOrder error', [
                'message' => $e->getMessage(),
                'sku' => $sku,
                'ref_id' => $refId,
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Check order status from Digiflazz
     * 
     * @param string $refId Reference ID
     * @return array
     */
    public function checkOrderStatus(string $refId): array
    {
        try {
            $payload = [
                'username' => $this->username,
                'ref_id' => $refId,
                'sign' => md5($this->username . $this->apiKey . $refId),
            ];

            $response = Http::timeout(30)
                ->post("{$this->baseUrl}/transaction", $payload);

            if (!$response->successful()) {
                throw new Exception('Failed to check order status: ' . $response->body());
            }

            $data = $response->json();

            Log::info('Digiflazz order status checked', [
                'ref_id' => $refId,
                'status' => $data['data']['status'] ?? 'unknown',
            ]);

            return [
                'success' => true,
                'data' => $data['data'] ?? $data,
            ];

        } catch (Exception $e) {
            Log::error('Digiflazz checkOrderStatus error', [
                'message' => $e->getMessage(),
                'ref_id' => $refId,
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Get account balance from Digiflazz
     * 
     * @return array
     */
    public function getBalance(): array
    {
        try {
            $payload = [
                'cmd' => 'deposit',
                'username' => $this->username,
                'sign' => $this->signature,
            ];

            $response = Http::timeout(30)
                ->post("{$this->baseUrl}/cek-saldo", $payload);

            if (!$response->successful()) {
                throw new Exception('Failed to fetch balance: ' . $response->body());
            }

            $data = $response->json();

            Log::info('Digiflazz balance fetched', [
                'balance' => $data['data']['deposit'] ?? 0,
            ]);

            return [
                'success' => true,
                'data' => $data['data'] ?? $data,
            ];

        } catch (Exception $e) {
            Log::error('Digiflazz getBalance error', [
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ];
        }
    }
}