<?php

namespace Paymenter\Extensions\Gateways\MercadoPago;

use App\Classes\Extension\Gateway;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Helpers\ExtensionHelper;

class MercadoPago extends Gateway
{
    public function boot()
    {
        if (!Route::has('extensions.gateways.mercadopago.webhook')) {
            Route::post('/extensions/mercadopago/webhook', [self::class, 'webhook'])
                ->name('extensions.gateways.mercadopago.webhook')
                ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
        }
    }

    private function getInvoiceUrl(Invoice $invoice): string
    {
        $reference = $invoice->invoice_id ?? $invoice->reference ?? $invoice->number ?? $invoice->id;
        return url('/invoices/' . $reference);
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'access_token',
                'label' => 'Access Token (Produção ou Teste)',
                'friendlyName' => 'Access Token',
                'type' => 'password',
                'description' => 'Credencial Access Token do Mercado Pago (Produção ou Teste)',
                'required' => true,
            ],
            [
                'name' => 'currency',
                'label' => 'Moeda',
                'friendlyName' => 'Moeda (Currency)',
                'type' => 'text',
                'description' => 'Código da moeda (ex: BRL). Padrão: BRL',
                'required' => false,
            ]
        ];
    }

    public function pay(Invoice $invoice, $total)
    {
        $accessToken = (string) ($this->config('access_token') ?? '');
        $currency = strtoupper(trim((string) ($this->config('currency') ?? 'BRL')));

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'BRL';
        }

        if (empty($accessToken)) {
            Log::channel('paymenter')->error('MercadoPago Error (Missing Token)', ['invoice_id' => $invoice->id]);
            return $this->getInvoiceUrl($invoice) . '?error=mercadopago_config_invalida';
        }

        $webhookUrl = Route::has('api.webhooks')
            ? route('api.webhooks', ['gateway' => 'MercadoPago'])
            : url('/extensions/mercadopago/webhook');

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(20)
            ->post('https://api.mercadopago.com/checkout/preferences', [
                'items' => [
                    [
                        'id' => (string) $invoice->id,
                        'title' => 'Fatura #' . $invoice->id,
                        'quantity' => 1,
                        'unit_price' => (float) $total,
                        'currency_id' => $currency,
                    ]
                ],
                'payer' => [
                    'email' => $invoice->user->email,
                    'name' => $invoice->user->first_name,
                    'surname' => $invoice->user->last_name,
                ],
                'external_reference' => (string) $invoice->id,
                'notification_url' => $webhookUrl,
                'back_urls' => [
                    'success' => $this->getInvoiceUrl($invoice),
                    'pending' => $this->getInvoiceUrl($invoice),
                    'failure' => $this->getInvoiceUrl($invoice),
                ],
                'auto_return' => 'approved',
            ]);

        if ($response->successful()) {
            $data = $response->json();
            if (!empty($data['init_point'])) {
                return $data['init_point'];
            }
            if (!empty($data['sandbox_init_point'])) {
                return $data['sandbox_init_point'];
            }
            Log::channel('paymenter')->error('MercadoPago Error (Missing Checkout URL)', ['invoice_id' => $invoice->id, 'response' => $data]);
        }

        Log::channel('paymenter')->error('MercadoPago Error (Preference Generation)', [
            'status' => $response->status(),
            'body' => $response->json()
        ]);

        return $this->getInvoiceUrl($invoice) . '?error=mercadopago_falhou';
    }

    public function webhook(Request $request)
    {
        $accessToken = (string) ($this->config('access_token') ?? '');

        if (empty($accessToken)) {
            Log::channel('paymenter')->error('MercadoPago Webhook Error (Missing Access Token)');
            return response()->json(['status' => 'success'], 200);
        }

        $paymentId = $request->input('data.id') ?? $request->input('id');
        $topic = $request->input('type') ?? $request->input('topic') ?? $request->input('action');
        $isPaymentEvent = $topic === 'payment' || (is_string($topic) && str_starts_with($topic, 'payment.'));

        if ($isPaymentEvent && $paymentId) {
            $paymentId = (string) $paymentId;
            if (!ctype_digit($paymentId)) {
                Log::channel('paymenter')->warning('MercadoPago Webhook Ignored (Invalid ID)', ['payment_id' => $paymentId]);
                return response()->json(['status' => 'success'], 200);
            }

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(20)
                ->get("https://api.mercadopago.com/v1/payments/{$paymentId}");

            if ($response->successful()) {
                $paymentData = $response->json();

                if (isset($paymentData['status']) && $paymentData['status'] === 'approved') {
                    $invoiceId = $paymentData['external_reference'] ?? null;
                    if (!$invoiceId || !ctype_digit((string) $invoiceId)) {
                        Log::channel('paymenter')->error('MercadoPago Webhook Error (Invalid Ref)', ['payment_id' => $paymentId]);
                    } else {
                        DB::transaction(function () use ($invoiceId) {
                            $invoice = Invoice::whereKey((int) $invoiceId)->lockForUpdate()->first();

                            if ($invoice && $invoice->status !== 'paid') {

                                // 1. Tenta a assinatura de versões antigas do Paymenter
                                if (method_exists(\App\Helpers\ExtensionHelper::class, 'paymentDone')) {
                                    \App\Helpers\ExtensionHelper::paymentDone($invoice->id);
                                }
                                // 2. Tenta a assinatura de algumas variações/forks
                                elseif (method_exists(\App\Helpers\ExtensionHelper::class, 'paymentSuccessful')) {
                                    \App\Helpers\ExtensionHelper::paymentSuccessful($invoice->id);
                                }
                                // 3. Padrão moderno (Paymenter mais recente)
                                else {
                                    // Atualiza o modelo diretamente no banco
                                    $invoice->update([
                                        'status' => 'paid',
                                        'paid_at' => now()
                                    ]);

                                    // Dispara o evento nativo para que o Paymenter crie/libere o serviço no servidor
                                    if (class_exists(\App\Events\Invoice\InvoicePaid::class)) {
                                        event(new \App\Events\Invoice\InvoicePaid($invoice));
                                    } elseif (class_exists(\App\Events\InvoicePaid::class)) {
                                        event(new \App\Events\InvoicePaid($invoice));
                                    }
                                }

                                Log::channel('paymenter')->info("MercadoPago: Fatura #{$invoice->id} aprovada e processada com fallback.");
                            }
                        });
                    }
                }
            } else {
                Log::channel('paymenter')->error('MercadoPago Webhook Error (Validation)', ['payment_id' => $paymentId, 'response' => $response->body()]);
            }
        }

        return response()->json(['status' => 'success'], 200);
    }
}
