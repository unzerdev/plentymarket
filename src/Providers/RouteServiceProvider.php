<?php

namespace UnzerPayment\Providers;

use Plenty\Plugin\RouteServiceProvider as RouteServiceProviderBase;
use Plenty\Plugin\Routing\Router;

class RouteServiceProvider extends RouteServiceProviderBase
{
    public function map(Router $router)
    {
        $router->get('payment/unzer-test', 'UnzerPayment\Controllers\SystemController@test');
        $router->get('payment/unzer-get-table', 'UnzerPayment\Controllers\SystemController@getTable');
        $router->get('payment/unzer-external-order-matching', 'UnzerPayment\Controllers\SystemController@externalOrderMatching');

        $router->post('payment/unzer-webhook', 'UnzerPayment\Controllers\WebhookController@webhook');
        $router->get('payment/unzer-webhook-register', 'UnzerPayment\Controllers\WebhookController@register');

        $router->get('payment/unzer-checkout-return', 'UnzerPayment\Controllers\CheckoutController@return');


    }
}
