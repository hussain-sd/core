<?php

namespace SmartTill\Core\Services;

use App\Models\Store;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SmartTill\Core\Enums\FbrEnvironment;
use SmartTill\Core\Enums\SalePaymentStatus;
use SmartTill\Core\Models\Sale;
use SmartTill\Core\Models\Stock;

class FbrPosService
{
    public function __construct(
        private Store $store
    ) {}

    /**
     * Generate FBR invoice for a sale.
     *
     * @return array{success: bool, data?: array<mixed>, invoice_number?: string|null, response_message?: string|null, code?: string|null, error?: string, status?: int, response?: mixed}
     */
    public function generateInvoice(Sale $sale): array
    {
        try {
            $payload = $this->buildInvoicePayload($sale);

            $response = $this->sendToFbr($payload);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::info('FBR Raw Response', [
                    'sale_id' => $sale->id,
                    'raw_response' => $responseData,
                ]);

                $isSuccess = isset($responseData['Code']) && $responseData['Code'] === '100';

                if ($isSuccess) {
                    Log::info('FBR Invoice generated successfully', [
                        'sale_id' => $sale->id,
                        'fbr_invoice_number' => $responseData['InvoiceNumber'] ?? null,
                        'response_message' => $responseData['Response'] ?? null,
                        'code' => $responseData['Code'] ?? null,
                    ]);

                    return [
                        'success' => true,
                        'data' => $responseData,
                        'invoice_number' => $responseData['InvoiceNumber'] ?? null,
                        'response_message' => $responseData['Response'] ?? null,
                        'code' => $responseData['Code'] ?? null,
                    ];
                }

                Log::error('FBR API returned error response', [
                    'sale_id' => $sale->id,
                    'response' => $responseData,
                ]);

                return [
                    'success' => false,
                    'error' => $responseData['Response'] ?? 'FBR API returned an error',
                    'code' => $responseData['Code'] ?? null,
                    'response' => $responseData,
                ];
            }

            Log::error('FBR API request failed', [
                'sale_id' => $sale->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => 'FBR API request failed',
                'status' => $response->status(),
                'response' => $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('FBR Invoice generation failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate FBR refund invoice for a cancelled sale.
     *
     * @return array{success: bool, data?: array<mixed>, invoice_number?: string|null, response_message?: string|null, code?: string|null, error?: string, status?: int, response?: mixed}
     */
    public function generateRefundInvoice(Sale $sale): array
    {
        try {
            $payload = $this->buildRefundInvoicePayload($sale);

            $response = $this->sendToFbr($payload);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::info('FBR Refund Invoice Raw Response', [
                    'sale_id' => $sale->id,
                    'raw_response' => $responseData,
                ]);

                $isSuccess = isset($responseData['Code']) && $responseData['Code'] === '100';

                if ($isSuccess) {
                    Log::info('FBR Refund Invoice generated successfully', [
                        'sale_id' => $sale->id,
                        'fbr_refund_invoice_number' => $responseData['InvoiceNumber'] ?? null,
                        'response_message' => $responseData['Response'] ?? null,
                        'code' => $responseData['Code'] ?? null,
                    ]);

                    return [
                        'success' => true,
                        'data' => $responseData,
                        'invoice_number' => $responseData['InvoiceNumber'] ?? null,
                        'response_message' => $responseData['Response'] ?? null,
                        'code' => $responseData['Code'] ?? null,
                    ];
                }

                Log::error('FBR Refund API returned error response', [
                    'sale_id' => $sale->id,
                    'response' => $responseData,
                ]);

                return [
                    'success' => false,
                    'error' => $responseData['Response'] ?? 'FBR API returned an error',
                    'code' => $responseData['Code'] ?? null,
                    'response' => $responseData,
                ];
            }

            Log::error('FBR Refund API request failed', [
                'sale_id' => $sale->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => 'FBR API request failed',
                'status' => $response->status(),
                'response' => $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('FBR Refund Invoice generation failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test FBR connection.
     *
     * @return array{success: bool, status?: int, response?: mixed, message?: string|null, code?: string|null, error?: string}
     */
    public function testConnection(): array
    {
        try {
            $testPayload = [
                'InvoiceNumber' => '',
                'POSID' => (int) $this->getPosId(),
                'USIN' => 'TEST_'.time(),
                'DateTime' => now()->format('Y-m-d H:i:s'),
                'BuyerNTN' => '1234567-8',
                'BuyerCNIC' => '12345-1234567-8',
                'BuyerName' => 'Test Buyer',
                'BuyerPhoneNumber' => '0000-0000000',
                'TotalBillAmount' => 0.0,
                'TotalQuantity' => 0.0,
                'TotalSaleValue' => 0.0,
                'TotalTaxCharged' => 0.0,
                'Discount' => 0.0,
                'FurtherTax' => 0.0,
                'PaymentMode' => 1,
                'RefUSIN' => null,
                'InvoiceType' => 1,
                'Items' => [
                    [
                        'ItemCode' => 'TEST_ITEM',
                        'ItemName' => 'Test Item',
                        'Quantity' => 1.0,
                        'PCTCode' => '11001010',
                        'TaxRate' => 0.0,
                        'SaleValue' => 0.0,
                        'TotalAmount' => 0.0,
                        'TaxCharged' => 0.0,
                        'Discount' => 0.0,
                        'FurtherTax' => 0.0,
                        'InvoiceType' => 1,
                        'RefUSIN' => null,
                    ],
                ],
            ];

            $response = $this->sendToFbr($testPayload);

            if ($response->successful()) {
                $responseData = $response->json();
                $isSuccess = isset($responseData['Code']) && $responseData['Code'] === '100';

                return [
                    'success' => $isSuccess,
                    'status' => $response->status(),
                    'response' => $responseData,
                    'message' => $responseData['Response'] ?? null,
                    'code' => $responseData['Code'] ?? null,
                ];
            }

            return [
                'success' => false,
                'status' => $response->status(),
                'response' => $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build the invoice payload for FBR API.
     *
     * @return array<string, mixed>
     */
    private function buildInvoicePayload(Sale $sale): array
    {
        $customer = $sale->customer;
        $saleVariations = $sale->variations->keyBy('id');
        $multiplier = $sale->currencyMultiplier();
        $rows = DB::table('sale_variation')
            ->where('sale_id', $sale->id)
            ->get(['variation_id', 'stock_id', 'description', 'quantity', 'unit_price', 'tax', 'discount', 'total']);

        $totalQuantity = $rows->sum('quantity');
        $totalSaleValue = $rows->sum(function ($row) use ($multiplier): float {
            $unitPrice = $multiplier ? (float) ($row->unit_price ?? 0) / $multiplier : (float) ($row->unit_price ?? 0);

            return $unitPrice * (float) ($row->quantity ?? 0);
        });
        $totalTaxCharged = $rows->sum(function ($row) use ($multiplier): float {
            $tax = $multiplier ? (float) ($row->tax ?? 0) / $multiplier : (float) ($row->tax ?? 0);

            return $tax * (float) ($row->quantity ?? 0);
        });
        $totalDiscount = $rows->sum(function ($row) use ($multiplier): float {
            return $multiplier ? (float) ($row->discount ?? 0) / $multiplier : (float) ($row->discount ?? 0);
        }) + ($sale->discount ?? 0);
        $totalBillAmount = $sale->total;

        $invoiceType = $this->resolveInvoiceType($totalBillAmount, $sale->payment_status);

        $items = $rows->values()->map(function ($row, int $index) use ($invoiceType, $saleVariations, $multiplier): array {
            $variation = $row->variation_id
                ? $saleVariations->get($row->variation_id)
                : (object) ['id' => $index + 1, 'sku' => 'CUSTOM_'.($index + 1), 'description' => $row->description ?? 'Custom item', 'pct_code' => null];

            $pivot = $this->buildPivotObject($row, $multiplier);

            return [
                'ItemCode' => $variation->sku ?? 'ITEM_'.$variation->id,
                'ItemName' => $variation->description,
                'Quantity' => (float) $pivot->quantity,
                'PCTCode' => $variation->pct_code ?? '11001010',
                'TaxRate' => $pivot->stock_id ? (float) (Stock::query()->whereKey($pivot->stock_id)->value('tax_percentage') ?? 0.0) : 0.0,
                'SaleValue' => (float) ($pivot->unit_price * $pivot->quantity),
                'TotalAmount' => (float) $pivot->total,
                'TaxCharged' => (float) ($pivot->tax * $pivot->quantity),
                'Discount' => (float) $pivot->discount,
                'FurtherTax' => 0.0,
                'InvoiceType' => $invoiceType,
                'RefUSIN' => null,
            ];
        })->toArray();

        return [
            'InvoiceNumber' => '',
            'POSID' => (int) $this->getPosId(),
            'USIN' => $sale->reference,
            'DateTime' => $sale->created_at->format('Y-m-d H:i:s'),
            'BuyerNTN' => $customer?->ntn ?? '',
            'BuyerCNIC' => $customer?->cnic ?? '',
            'BuyerName' => $customer?->name ?? 'Walk-in Customer',
            'BuyerPhoneNumber' => $customer?->phone ?? '',
            'TotalBillAmount' => (float) $totalBillAmount,
            'TotalQuantity' => (float) $totalQuantity,
            'TotalSaleValue' => (float) $totalSaleValue,
            'TotalTaxCharged' => (float) $totalTaxCharged,
            'Discount' => (float) $totalDiscount,
            'FurtherTax' => 0.0,
            'PaymentMode' => $this->mapPaymentMode($sale->payment_status),
            'RefUSIN' => null,
            'InvoiceType' => $invoiceType,
            'Items' => $items,
        ];
    }

    /**
     * Build the refund invoice payload for FBR API.
     * Note: FBR requires POSITIVE values with InvoiceType=2 and RefUSIN to indicate refund.
     *
     * @return array<string, mixed>
     */
    private function buildRefundInvoicePayload(Sale $sale): array
    {
        $customer = $sale->customer;
        $saleVariations = $sale->variations->keyBy('id');
        $multiplier = $sale->currencyMultiplier();
        $rows = DB::table('sale_variation')
            ->where('sale_id', $sale->id)
            ->get(['variation_id', 'stock_id', 'description', 'quantity', 'unit_price', 'tax', 'discount', 'total']);

        $totalQuantity = abs($rows->sum('quantity'));
        $totalSaleValue = abs($rows->sum(function ($row) use ($multiplier): float {
            $unitPrice = $multiplier ? (float) ($row->unit_price ?? 0) / $multiplier : (float) ($row->unit_price ?? 0);

            return $unitPrice * (float) ($row->quantity ?? 0);
        }));
        $totalTaxCharged = abs($rows->sum(function ($row) use ($multiplier): float {
            $tax = $multiplier ? (float) ($row->tax ?? 0) / $multiplier : (float) ($row->tax ?? 0);

            return $tax * (float) ($row->quantity ?? 0);
        }));
        $totalDiscount = abs($rows->sum(function ($row) use ($multiplier): float {
            return $multiplier ? (float) ($row->discount ?? 0) / $multiplier : (float) ($row->discount ?? 0);
        }) + ($sale->discount ?? 0));
        $totalBillAmount = abs($sale->total);

        $items = $rows->values()->map(function ($row, int $index) use ($sale, $saleVariations, $multiplier): array {
            $variation = $row->variation_id
                ? $saleVariations->get($row->variation_id)
                : (object) ['id' => $index + 1, 'sku' => 'CUSTOM_'.($index + 1), 'description' => $row->description ?? 'Custom item', 'pct_code' => null];

            $pivot = $this->buildPivotObject($row, $multiplier);

            return [
                'ItemCode' => $variation->sku ?? 'ITEM_'.$variation->id,
                'ItemName' => $variation->description,
                'Quantity' => abs((float) $pivot->quantity),
                'PCTCode' => $variation->pct_code ?? '11001010',
                'TaxRate' => $pivot->stock_id ? (float) (Stock::query()->whereKey($pivot->stock_id)->value('tax_percentage') ?? 0.0) : 0.0,
                'SaleValue' => abs((float) ($pivot->unit_price * $pivot->quantity)),
                'TotalAmount' => abs((float) $pivot->total),
                'TaxCharged' => abs((float) ($pivot->tax * $pivot->quantity)),
                'Discount' => abs((float) $pivot->discount),
                'FurtherTax' => 0.0,
                'InvoiceType' => 1,
                'RefUSIN' => $sale->reference,
            ];
        })->toArray();

        return [
            'InvoiceNumber' => '',
            'POSID' => (int) $this->getPosId(),
            'USIN' => $sale->reference.'-REFUND',
            'DateTime' => now()->format('Y-m-d H:i:s'),
            'BuyerNTN' => $customer?->ntn ?? '',
            'BuyerCNIC' => $customer?->cnic ?? '',
            'BuyerName' => $customer?->name ?? 'Walk-in Customer',
            'BuyerPhoneNumber' => $customer?->phone ?? '',
            'TotalBillAmount' => (float) $totalBillAmount,
            'TotalQuantity' => (float) $totalQuantity,
            'TotalSaleValue' => (float) $totalSaleValue,
            'TotalTaxCharged' => (float) $totalTaxCharged,
            'Discount' => (float) $totalDiscount,
            'FurtherTax' => 0.0,
            'PaymentMode' => $this->mapPaymentMode($sale->payment_status),
            'RefUSIN' => $sale->reference,
            'InvoiceType' => 2,
            'Items' => $items,
        ];
    }

    /**
     * Build a normalised pivot object from a sale_variation row.
     */
    private function buildPivotObject(\stdClass $row, ?float $multiplier): \stdClass
    {
        $divide = static fn (float $value): float => $multiplier ? $value / $multiplier : $value;

        return (object) [
            'stock_id' => $row->stock_id,
            'quantity' => (float) ($row->quantity ?? 0),
            'unit_price' => $divide((float) ($row->unit_price ?? 0)),
            'tax' => $divide((float) ($row->tax ?? 0)),
            'discount' => $divide((float) ($row->discount ?? 0)),
            'total' => $divide((float) ($row->total ?? 0)),
        ];
    }

    /**
     * Determine the FBR invoice type (1 = paid, 2 = return, 3 = credit).
     */
    private function resolveInvoiceType(float $totalBillAmount, SalePaymentStatus $paymentStatus): int
    {
        if ($totalBillAmount < 0) {
            return 2;
        }

        if ($paymentStatus === SalePaymentStatus::Credit) {
            return 3;
        }

        return 1;
    }

    /**
     * Get the appropriate POS ID based on environment.
     */
    private function getPosId(): ?int
    {
        return $this->store->fbr_environment === FbrEnvironment::SANDBOX
            ? $this->store->fbr_sandbox_pos_id
            : $this->store->fbr_pos_id;
    }

    /**
     * Get the bearer token based on environment.
     */
    private function getBearerToken(): string
    {
        return $this->store->fbr_environment === FbrEnvironment::SANDBOX
            ? '1298b5eb-b252-3d97-8622-a4a69d5bf818'
            : $this->store->fbr_bearer_token;
    }

    /**
     * Send a payload to the FBR API.
     */
    private function sendToFbr(array $payload): Response
    {
        $url = $this->store->fbr_environment->getApiUrl();
        $token = $this->getBearerToken();

        Log::info('FBR API Request Details', [
            'url' => $url,
            'environment' => $this->store->fbr_environment->value,
            'token_length' => strlen($token),
            'payload' => $payload,
        ]);

        try {
            $client = Http::withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(30);

            if ($this->store->fbr_environment === FbrEnvironment::SANDBOX) {
                $client = $client->withOptions(['verify' => false]);
            }

            $response = $client->post($url, $payload);

            Log::info('FBR API Response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body(),
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('FBR API Request Failed', [
                'error' => $e->getMessage(),
                'url' => $url,
                'environment' => $this->store->fbr_environment->value,
            ]);
            throw $e;
        }
    }

    /**
     * Map a payment status to the FBR payment mode integer.
     */
    private function mapPaymentMode(SalePaymentStatus $paymentStatus): int
    {
        return match ($paymentStatus) {
            SalePaymentStatus::Paid, SalePaymentStatus::Pending, SalePaymentStatus::Refunded => 1,
            SalePaymentStatus::Credit => 5,
        };
    }
}
