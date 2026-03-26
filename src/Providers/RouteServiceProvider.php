<?php

namespace UnzerPayment\Providers;

use Plenty\Plugin\RouteServiceProvider as RouteServiceProviderBase;
use Plenty\Plugin\Routing\Router;

class RouteServiceProvider extends RouteServiceProviderBase
{
    public function map(Router $router)
    {
        //$router->get('payment/unzer-test', 'UnzerPayment\Controllers\SystemController@test');
        $router->get('payment/unzer-pay', 'UnzerPayment\Controllers\CheckoutController@payPage');
        $router->get('payment/unzer-get-table', 'UnzerPayment\Controllers\SystemController@getTable');
        $router->get('payment/unzer-external-order-matching', 'UnzerPayment\Controllers\SystemController@externalOrderMatching');
        $router->post('payment/unzer-webhook', 'UnzerPayment\Controllers\WebhookController@webhook');
        $router->get('payment/unzer-webhook-register', 'UnzerPayment\Controllers\WebhookController@register');
        $router->get('payment/unzer-checkout-return', 'UnzerPayment\Controllers\CheckoutController@return');
        $router->get('payment/unzer-checkout-pay-return', 'UnzerPayment\Controllers\CheckoutController@payReturn');
        $router->get('payment/unzer-checkout-cancel', 'UnzerPayment\Controllers\CheckoutController@cancel');
        $router->get('payment/unzer-checkout-error', 'UnzerPayment\Controllers\CheckoutController@checkoutError');
        $router->get('.well-known/apple-developer-merchantid-domain-association', 'UnzerPayment\Controllers\SystemController@applePayDomainVerification');
    }
}
