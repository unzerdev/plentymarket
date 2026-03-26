<?php

namespace UnzerPayment\Migrations;

use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;
use UnzerPayment\Constants\Constants;
use UnzerPayment\Services\PaymentMethodService;

class CreatePaymentMethodsSeparatelyRun2
{
    public function run(Migrate $migrate)
    {
        foreach (Constants::PAYMENT_METHODS as $paymentMethod) {
            pluginApp(PaymentMethodService::class)->getPaymentMethodId($paymentMethod['payment_method_code'], $paymentMethod['name']);
        }
    }
}