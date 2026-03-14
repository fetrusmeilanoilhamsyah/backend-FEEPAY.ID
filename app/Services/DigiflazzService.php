<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DigiflazzService
{
    private string $username;
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        // ✅ CRITICAL FIX: Load dari config, BUKAN hardcode
        $this->username = config('services.digiflazz.username');
        $this->apiKey = config('services.digiflazz.api_key');
        $this->baseUrl = config('services.digiflazz.base_url', 'https://api.digiflazz.com/v1');
        $this->timeout = config('services.digiflazz.timeout', 30);

        if (empty($this->username) || empty($this->apiKey)) {
            throw new \RuntimeException('Digiflazz credentials not configured properly');
        }
    }

    /**
     * Get balance dari Digiflazz
     */
    public function getBalance(): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$this->baseUrl}/cek-saldo", [
                    'cmd' => 'deposit',
                    'username' => $this->username,
                    'sign' => $this->generateSignature('depo'),
                ]);

            if (!$response->successful()) {
                throw new \Exception('Digiflazz API error: ' . $response->status());
            }

            $data = $response->json();

            Log::info('Digiflazz balance check', [
                'balance' => $data['data']['deposit'] ?? null
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('Digiflazz balance check failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Purchase product dari Digiflazz
     * 
     * @param string $buyerSkuCode SKU code produk
     * @param string $customerId Customer ID (nomor HP, meter, dll)
     * @param string $refId Reference ID (trx_id dari order)
     * @return array
     */
    public function purchaseProduct(string $buyerSkuCode, string $customerId, string $refId): array
    {
        $payload = [
            'username' => $this->username,
            'buyer_sku_code' => $buyerSkuCode,
            'customer_no' => $customerId,
            'ref_id' => $refId,
            'sign' => $this->generateSignature($refId),
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$this->baseUrl}/transaction", $payload);

            if (!$response->successful()) {
                throw new \Exception('Digiflazz transaction failed: ' . $response->status());
            }

            $data = $response->json();

            Log::info('Digiflazz purchase transaction', [
                'sku' => $buyerSkuCode,
                'customer' => $customerId,
                'ref_id' => $refId,
                'status' => $data['data']['status'] ?? null,
                'message' => $data['data']['message'] ?? null
            ]);

            return $data;

        } catch (\Exception $e) {
            Log::error('Digiflazz purchase failed', [
                'sku' => $buyerSkuCode,
                'customer' => $customerId,
                'ref_id' => $refId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get price list dari Digiflazz
     * 
     * ✅ PERFORMANCE FIX: Ditambahkan caching 1 jam
     */
    public function getPriceList(bool $forceRefresh = false): array
    {
        $cacheKey = 'digiflazz:pricelist';
        
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3600, function () {
            try {
                $response = Http::timeout($this->timeout)
                    ->post("{$this->baseUrl}/price-list", [
                        'cmd' => 'prepaid',
                        'username' => $this->username,
                        'sign' => $this->generateSignature('pricelist'),
                    ]);

                if (!$response->successful()) {
                    Log::error('Digiflazz pricelist failed', [
                        'status' => $response->status()
                    ]);
                    return [];
                }

                $data = $response->json();

                Log::info('Digiflazz pricelist fetched', [
                    'total_products' => count($data['data'] ?? [])
                ]);

                return $data['data'] ?? [];

            } catch (\Exception $e) {
                Log::error('Digiflazz pricelist error', [
                    'error' => $e->getMessage()
                ]);
                return [];
            }
        });
    }

    /**
     * Get prepaid products
     */
    public function getPrepaidProducts(bool $forceRefresh = false): array
    {
        return $this->getPriceList($forceRefresh);
    }

    /**
     * Get postpaid products
     */
    public function getPostpaidProducts(bool $forceRefresh = false): array
    {
        $cacheKey = 'digiflazz:postpaid';
        
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3600, function () {
            try {
                $response = Http::timeout($this->timeout)
                    ->post("{$this->baseUrl}/price-list", [
                        'cmd' => 'pasca',
                        'username' => $this->username,
                        'sign' => $this->generateSignature('pricelist'),
                    ]);

                if (!$response->successful()) {
                    return [];
                }

                $data = $response->json();
                return $data['data'] ?? [];

            } catch (\Exception $e) {
                Log::error('Digiflazz postpaid error', [
                    'error' => $e->getMessage()
                ]);
                return [];
            }
        });
    }

    /**
     * Check transaction status
     */
    public function checkStatus(string $refId): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/transaction", [
                    'commands' => 'status-pasca',
                    'username' => $this->username,
                    'ref_id' => $refId,
                    'sign' => $this->generateSignature($refId),
                ]);

            if (!$response->successful()) {
                throw new \Exception('Status check failed: ' . $response->status());
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Digiflazz status check error', [
                'ref_id' => $refId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate signature untuk API authentication
     * 
     * @param string $refId Reference ID atau command
     * @return string MD5 hash
     */
    private function generateSignature(string $refId): string
    {
        return md5($this->username . $this->apiKey . $refId);
    }

    /**
     * Sync products dari Digiflazz ke database lokal
     * Digunakan untuk scheduled command
     */
    public function syncProducts(): int
    {
        try {
            $products = $this->getPriceList(true); // Force refresh
            
            if (empty($products)) {
                Log::warning('Digiflazz sync: No products retrieved');
                return 0;
            }

            $syncedCount = 0;
            
            foreach ($products as $product) {
                $costPrice = (float) ($product['price'] ?? 0);
                $defaultMargin = (float) config('feepay.margin', 2000);
                $sellingPrice = $costPrice + $defaultMargin;
                $status = ($product['buyer_product_status'] ?? false) && ($product['seller_product_status'] ?? false) ? 'active' : 'inactive';

                $existing = \App\Models\Product::where('sku', $product['buyer_sku_code'])->first();
                if ($existing && $costPrice < $existing->selling_price) {
                    $sellingPrice = $existing->selling_price;
                }

                \App\Models\Product::updateOrCreate(
                    ['sku' => $product['buyer_sku_code']],
                    [
                        'name' => $product['product_name'] ?? 'Unknown',
                        'category' => $product['category'] ?? 'General',
                        'brand' => $product['brand'] ?? null,
                        'cost_price' => $costPrice,
                        'selling_price' => $sellingPrice,
                        'status' => $status,
                    ]
                );
                $syncedCount++;
            }

            Log::info('Digiflazz products synced', [
                'total' => $syncedCount
            ]);

            return $syncedCount;

        } catch (\Exception $e) {
            Log::error('Digiflazz sync failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}