<?php

namespace Paymenter\Extensions\Gateways\Mercado;

use App\Classes\Extension\Gateway;
use App\Models\Invoice;

class Mercado extends Gateway
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
        // 1. Obter as credenciais
        $mode = $this->getSetting('mode');
        $accessToken = ($mode === 'production') ? 
                    $this->getSetting('accessToken') : 
                    $this->getSetting('sandboxAccessToken');

        \MercadoPago\SDK::setAccessToken($accessToken);
        
        // 2. Processar a notificação
        if ($request->input('topic') === 'payment' && $request->input('id')) {
            try {
                // Buscar o status do pagamento diretamente no Mercado Pago
                $payment = \MercadoPago\Payment::find_by_id($request->input('id'));
                
                if ($payment) {
                    $invoiceId = $payment->external_reference;
                    $invoice = Invoice::find($invoiceId);

                    if ($invoice && $payment->status === 'approved') {
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
}