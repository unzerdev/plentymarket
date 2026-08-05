<?php

namespace UnzerPayment\Providers;

use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Plugin\Templates\Twig;
use UnzerPayment\Services\ConfigService;
use UnzerPayment\Services\PaymentMethodService;
use UnzerPayment\Traits\LoggingTrait;

class DataProviderReinitializeButton
{
    use LoggingTrait;

    public function call($order): string
    {

        $this->log(__CLASS__, __METHOD__, 'args', '', ['$order' => $order]);

        $paymentMethodId = null;
        foreach ($order['properties'] as $orderProperty) {
            if ((int)$orderProperty['typeId'] === OrderPropertyType::PAYMENT_METHOD) {
                $paymentMethodId = (int)$orderProperty['value'];
                break;
            }
        }
        $paymentStatus = null;
        foreach ($order['properties'] as $orderProperty) {
            if ((int)$orderProperty['typeId'] === OrderPropertyType::PAYMENT_STATUS) {
                $paymentStatus = (string)$orderProperty['value'];
            }
        }

        $paymentMethodService = pluginApp(PaymentMethodService::class);
        if (!$paymentMethodService->isUnzerPaymentMethod($paymentMethodId)) {
            return '';
        }

        $paymentMethodData = $paymentMethodService->getPaymentMethodData($paymentMethodId);

        if ($paymentMethodData['unzer']['long_code'] === 'prepayment') {
            return '';
        }

        if ($paymentStatus !== 'unpaid') {
            return '';
        }

        if ((float)$order['statusId'] > 3.001) {
            return '<div id="unzer-hide-payment-method-change-link-marker" style="display: none;"></div>';
        }

        $twig = pluginApp(Twig::class);
        $configService = pluginApp(ConfigService::class);
        $url = $configService->getPayUrl($order['id'], '', false);
        return $twig->render('UnzerPayment::payment-method-reinitialize', ['url' => $url]);
    }
}