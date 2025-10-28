// routes/web.php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\MercadoPago\MercadoPago; // Sua classe principal

// Rota de retorno após o pagamento (quando o usuário é redirecionado)
// O nome da rota deve ser o que você definiu em $preference->back_urls
Route::get('mercadopago/callback', [MercadoPago::class, 'handleCallback'])
    ->name('ext.mercadopago.callback');

// Rota para Notificações IPN (Webhooks) do Mercado Pago
Route::post('mercadopago/ipn', [MercadoPago::class, 'handleIpn'])
    ->name('ext.mercadopago.ipn');