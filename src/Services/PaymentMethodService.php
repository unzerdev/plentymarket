<?php

namespace UnzerPayment\Services;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use UnzerPayment\Constants\Constants;
use UnzerPayment\PaymentMethods\UnzerPaymentMethod;

class PaymentMethodService
{
    private static $paymentMethodId = null;

    private PaymentMethodRepositoryContract $paymentMethodRepository;

    public function __construct(PaymentMethodRepositoryContract $paymentMethodRepository)
    {
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    public function getPaymentMethodId(): int
    {
        if (!isset(self::$paymentMethodId)) {
            $paymentMethodId = $this->getExistingPaymentMethodId();
            if ($paymentMethodId === false) {
                $paymentMethodData = [
                    'pluginKey' => Constants::PLUGIN_KEY,
                    'paymentKey' => UnzerPaymentMethod::PAYMENT_METHOD_CODE,
                    'name' => UnzerPaymentMethod::PAYMENT_NAME,
                ];

                $this->paymentMethodRepository->createPaymentMethod($paymentMethodData);
                $paymentMethodId = $this->getExistingPaymentMethodId();
            }
            self::$paymentMethodId = $paymentMethodId;
        }

        return (int)self::$paymentMethodId;
    }


    public function getExistingPaymentMethodId()
    {
        $paymentMethods = $this->paymentMethodRepository->allForPlugin(Constants::PLUGIN_KEY);
        if (!is_null($paymentMethods)) {
            foreach ($paymentMethods as $paymentMethod) {
                if ($paymentMethod->paymentKey == UnzerPaymentMethod::PAYMENT_METHOD_CODE) {
                    return $paymentMethod->id;
                }
            }
        }

        return false;
    }
}
