<?php

namespace Paymenter\Extensions\Gateways\MercadoPago;

use App\Classes\Extension\Gateway;
use App\Models\Invoice;
use Illuminate\Http\Request;

class MercadoPago extends Gateway
{
// 1. Informações Básicas da Extensão
    public function getName(): string
    {
        return 'Mercado Pago';
    }

    public function getDescription(): string
    {
        return 'Gateway de pagamento Mercado Pago para faturas e serviços.';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function pay(Invoice $invoice, $total)
    {
        $currency = (string) ($invoice->currency_code ?? $invoice->currency ?? $invoice->currency_id ?? 'BRL');

        return $this->createPayment($invoice, (float) $total, $currency);
    }

    // 2. Configuração (Credenciais)
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'accessToken',
                'label' => 'Access Token (Produção)',
                'type' => 'text',
                'required' => false,
                'description' => 'O seu Access Token de Produção do Mercado Pago.'
            ],
            [
                'name' => 'sandboxAccessToken',
                'label' => 'Access Token (Sandbox)',
                'type' => 'text',
                'required' => true,
                'description' => 'O seu Access Token de Sandbox/Teste do Mercado Pago.'
            ],
            [
                'name' => 'mode',
                'label' => 'Modo de Operação',
                'type' => 'select',
                'default' => 'production',
                'options' => [
                    'production' => 'Produção',
                    'sandbox' => 'Sandbox (Teste)',
                ],
                'description' => 'Selecione o ambiente para processar pagamentos.'
            ],
            [
                'name' => 'webhookSecret',
                'label' => 'Webhook Secret',
                'type' => 'text',
                'required' => true,
                'description' => 'Chave secreta do webhook do Mercado Pago para validar o cabeçalho x-signature.'
            ]
            // Você pode adicionar outras configurações como public key, etc.
        ];
    }

    // 3. Método para Processar o Pagamento
    // Este método é chamado quando o usuário clica em "Pagar" na fatura.
    // Ele deve retornar a URL para onde o usuário será redirecionado para concluir o pagamento.
    public function createPayment(Invoice $invoice, float $amount, string $currency): string
    {
        // 1. Obter as credenciais
        $mode = $this->getSetting('mode');
        $accessToken = ($mode === 'production') ?
                       $this->getSetting('accessToken') :
                       $this->getSetting('sandboxAccessToken');

        // 2. Inicializar o SDK do Mercado Pago (via Composer)
        // **IMPORTANTE**: Você precisará garantir que o SDK do Mercado Pago
        // esteja disponível para sua extensão (geralmente via composer.json da extensão).

        \MercadoPago\SDK::setAccessToken($accessToken);

        // 3. Criar o objeto de Preferência de Pagamento
        $preference = new \MercadoPago\Preference();

        // Dados do Item (Fatura)
        $item = new \MercadoPago\Item();
        $item->title = 'Fatura #' . $invoice->id;
        $item->quantity = 1;
        $item->unit_price = $amount;
        $preference->items = [$item];

        // URLs de Retorno
        $preference->back_urls = [
            "success" => route('ext.mercadopago.callback', ['invoice_id' => $invoice->id, 'status' => 'success']),
            "failure" => route('ext.mercadopago.callback', ['invoice_id' => $invoice->id, 'status' => 'failure']),
            "pending" => route('ext.mercadopago.callback', ['invoice_id' => $invoice->id, 'status' => 'pending']),
        ];

        $preference->auto_return = "approved";

        // Metadados para identificar o pagamento
        $preference->external_reference = $invoice->id;

        // Salvar a preferência no Mercado Pago
        $preference->save();

        // Retornar a URL de pagamento
        if ($mode === 'production') {
            return $preference->init_point; // URL de Produção
        } else {
            return $preference->sandbox_init_point; // URL de Sandbox
        }
    }

    // 4. Métodos de Callback/Webhook
    // Estes métodos serão usados para as URLs de retorno e para as Notificações IPN.

    // Este é um exemplo de como você pode registrar as rotas no método 'boot'
    public function boot()
    {
        // 🚨 IMPORTANTE: Registra o arquivo de rotas
        // __DIR__ aponta para /extensions/Gateways/MercadoPago/
        require __DIR__ . '/routes/web.php';
    }

        // Dentro da classe MercadoPago.php

    // 4.1 Método para processar o Retorno do Navegador (Callback)
    public function handleCallback(Request $request)
    {
        // Lógica simples de redirecionamento baseada no status
        $status = $request->input('status');
        $invoiceId = $request->input('invoice_id');

        $invoice = Invoice::find($invoiceId);
        if (!$invoice) {
            return redirect()->route('client.invoices.index')->with('error', 'Fatura não encontrada.');
        }

        if ($status === 'success' || $status === 'pending') {
            // Redireciona para a fatura com uma mensagem de sucesso/pendente
            return redirect()->route('client.invoices.show', $invoice->id)->with('success', 'Seu pagamento foi iniciado. Aguardando confirmação do Mercado Pago.');
        }

        // Caso contrário (failure)
        return redirect()->route('client.invoices.show', $invoice->id)->with('error', 'O pagamento foi cancelado ou falhou.');
    }


    // 4.2 Método para processar Notificações IPN (Webhook)
    // ESSENCIAL para atualizar o status da fatura para "paga" de forma assíncrona.
    public function handleIpn(Request $request)
    {
        if (!$this->isValidWebhookRequest($request)) {
            \Log::warning('Webhook Mercado Pago rejeitado por assinatura inválida.', [
                'x_request_id' => $request->header('x-request-id'),
                'ip' => $request->ip(),
            ]);

            return response('Unauthorized', 401);
        }

        // 1. Obter as credenciais
        $mode = $this->getSetting('mode');
        $accessToken = ($mode === 'production') ?
                    $this->getSetting('accessToken') :
                    $this->getSetting('sandboxAccessToken');

        \MercadoPago\SDK::setAccessToken($accessToken);

        // 2. Processar a notificação
        $notificationType = $request->input('topic', $request->input('type'));
        $paymentId = $this->extractPaymentId($request);

        if ($notificationType === 'payment' && $paymentId !== null) {
            try {
                // Buscar o status do pagamento diretamente no Mercado Pago
                $payment = \MercadoPago\Payment::find_by_id($paymentId);

                if ($payment) {
                    $invoiceId = (string) $payment->external_reference;
                    $invoice = Invoice::find($invoiceId);

                    if (!$invoice) {
                        \Log::warning('Webhook Mercado Pago recebido para fatura inexistente.', [
                            'payment_id' => $payment->id,
                            'external_reference' => $invoiceId,
                        ]);
                        return response('Invalid invoice', 422);
                    }

                    if ((string) $invoice->id !== $invoiceId) {
                        \Log::warning('Webhook Mercado Pago com external_reference divergente da fatura.', [
                            'payment_id' => $payment->id,
                            'external_reference' => $invoiceId,
                            'invoice_id' => $invoice->id,
                        ]);
                        return response('Invoice mismatch', 422);
                    }

                    if ($this->isInvoiceAlreadyPaid($invoice)) {
                        \Log::info('Webhook Mercado Pago duplicado ignorado (idempotência).', [
                            'payment_id' => $payment->id,
                            'invoice_id' => $invoice->id,
                        ]);
                        return response('OK', 200);
                    }

                    if ($this->isInvoiceStateBlockedForPayment($invoice)) {
                        \Log::warning('Webhook Mercado Pago rejeitado por estado inválido da fatura.', [
                            'payment_id' => $payment->id,
                            'invoice_id' => $invoice->id,
                            'invoice_status' => (string) $invoice->getAttribute('status'),
                        ]);
                        return response('Invalid invoice state', 422);
                    }

                    $expectedAmount = $this->resolveInvoiceExpectedAmount($invoice);
                    $expectedCurrency = $this->resolveInvoiceExpectedCurrency($invoice);
                    $paidAmount = (float) $payment->transaction_amount;
                    $paidCurrency = $this->normalizeCurrency((string) $payment->currency_id);

                    if ($expectedAmount === null || $expectedCurrency === null) {
                        \Log::error('Não foi possível validar vínculo forte do webhook Mercado Pago com a fatura.', [
                            'payment_id' => $payment->id,
                            'invoice_id' => $invoice->id,
                            'expected_amount' => $expectedAmount,
                            'expected_currency' => $expectedCurrency,
                        ]);
                        return response('Invoice validation unavailable', 422);
                    }

                    if (!$this->isAmountEquivalent($expectedAmount, $paidAmount)) {
                        \Log::warning('Webhook Mercado Pago rejeitado por divergência de valor.', [
                            'payment_id' => $payment->id,
                            'invoice_id' => $invoice->id,
                            'expected_amount' => $expectedAmount,
                            'paid_amount' => $paidAmount,
                        ]);
                        return response('Amount mismatch', 422);
                    }

                    if ($this->normalizeCurrency($expectedCurrency) !== $paidCurrency) {
                        \Log::warning('Webhook Mercado Pago rejeitado por divergência de moeda.', [
                            'payment_id' => $payment->id,
                            'invoice_id' => $invoice->id,
                            'expected_currency' => $expectedCurrency,
                            'paid_currency' => $paidCurrency,
                        ]);
                        return response('Currency mismatch', 422);
                    }

                    if ($payment->status === 'approved') {
                        // Marcar a fatura como paga no Paymenter.
                        // **IMPORTANTE**: Use os Helpers do Paymenter para isso.
                        \App\Helpers\ExtensionHelper::paymentSuccess($invoice, $payment->transaction_amount, $payment->currency_id, $payment->id);

                        return response('OK', 200);
                    }
                    // Lidar com outros status como 'pending', 'rejected', etc.
                }

            } catch (\Exception $e) {
                // Logar o erro
                \Log::error('Erro no Webhook do Mercado Pago: ' . $e->getMessage());
                return response('Error', 500);
            }
        }

        return response('OK', 200);
    }

    private function isValidWebhookRequest(Request $request): bool
    {
        $secret = (string) $this->getSetting('webhookSecret');
        if ($secret === '') {
            \Log::error('Webhook Secret do Mercado Pago não configurado.');
            return false;
        }

        $signatureHeader = (string) $request->header('x-signature');
        $requestId = (string) $request->header('x-request-id');
        $notificationId = $this->extractPaymentId($request);

        if ($signatureHeader === '' || $requestId === '' || $notificationId === null) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[trim($key)] = trim($value);
            }
        }

        $timestamp = $parts['ts'] ?? null;
        $signatureV1 = $parts['v1'] ?? null;

        if ($timestamp === null || $signatureV1 === null) {
            return false;
        }

        $manifest = sprintf('id:%s;request-id:%s;ts:%s;', $notificationId, $requestId, $timestamp);
        $expectedSignature = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expectedSignature, $signatureV1);
    }

    private function extractPaymentId(Request $request): ?string
    {
        $paymentId = $request->input('id');
        if ($paymentId !== null && $paymentId !== '') {
            return (string) $paymentId;
        }

        $query = $request->query();
        if (isset($query['data.id']) && $query['data.id'] !== '') {
            return (string) $query['data.id'];
        }

        $bodyDataId = $request->input('data.id');
        if ($bodyDataId !== null && $bodyDataId !== '') {
            return (string) $bodyDataId;
        }

        return null;
    }

    private function isInvoiceAlreadyPaid(Invoice $invoice): bool
    {
        $status = strtolower((string) $invoice->getAttribute('status'));
        return in_array($status, ['paid', 'completed'], true);
    }

    private function isInvoiceStateBlockedForPayment(Invoice $invoice): bool
    {
        $status = strtolower((string) $invoice->getAttribute('status'));
        return in_array($status, ['cancelled', 'canceled', 'refunded', 'void'], true);
    }

    private function resolveInvoiceExpectedAmount(Invoice $invoice): ?float
    {
        foreach (['total', 'amount', 'price', 'due', 'total_due'] as $field) {
            $value = $invoice->getAttribute($field);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function resolveInvoiceExpectedCurrency(Invoice $invoice): ?string
    {
        foreach (['currency_code', 'currency', 'currency_id'] as $field) {
            $value = $invoice->getAttribute($field);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeCurrency(string $currency): string
    {
        return strtoupper(trim($currency));
    }

    private function isAmountEquivalent(float $left, float $right): bool
    {
        return abs($left - $right) < 0.01;
    }
}
