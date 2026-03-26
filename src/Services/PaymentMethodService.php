<?php

namespace UnzerPayment\Services;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Method\Models\PaymentMethod;
use UnzerPayment\Constants\Constants;
use UnzerPayment\Traits\LoggingTrait;

class PaymentMethodService
{
    use LoggingTrait;

    private static array $paymentMethodId = [];

    private PaymentMethodRepositoryContract $paymentMethodRepository;

    public function __construct(PaymentMethodRepositoryContract $paymentMethodRepository)
    {
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    public function getUnzerPaymentMethodIds(): array
    {
        /** @var PaymentMethod[] $paymentMethods */
        $paymentMethods = $this->paymentMethodRepository->allForPlugin(Constants::PLUGIN_KEY);
        $paymentMethodIds = [];
        if (!is_null($paymentMethods)) {
            foreach ($paymentMethods as $paymentMethod) {
                $paymentMethodIds[$paymentMethod->paymentKey] = (int)$paymentMethod->id;
            }
        }

        return $paymentMethodIds;
    }

    public function isUnzerPaymentMethod(int $paymentMethodId): bool
    {
        return in_array($paymentMethodId, $this->getUnzerPaymentMethodIds());
    }

    public function getPaymentMethodById(int $paymentMethodId): ?PaymentMethod
    {
        $paymentMethods = $this->paymentMethodRepository->allForPlugin(Constants::PLUGIN_KEY);
        $this->log(__CLASS__, __METHOD__, 'all', '', [
            'paymentMethods' => $paymentMethods,
        ]);

        if (!is_null($paymentMethods)) {
            foreach ($paymentMethods as $paymentMethod) {
                if ((int)$paymentMethod->id === $paymentMethodId) {
                    return $paymentMethod;
                }
            }
        }

        return null;
    }

    public function getPaymentMethodData(int $paymentMethodId): ?array
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', [
            'paymentMethodId' => $paymentMethodId,
        ]);
        $paymentMethod = $this->getPaymentMethodById($paymentMethodId);
        $this->log(__CLASS__, __METHOD__, 'method', '', [
            'paymentMethod' => $paymentMethod,
        ]);
        if ($paymentMethod) {
            $unzerDetails = [];
            foreach (Constants::PAYMENT_METHODS as $unzerPaymentMethodData) {
                if ($unzerPaymentMethodData['payment_method_code'] === $paymentMethod->paymentKey) {
                    $unzerDetails = $unzerPaymentMethodData;
                }
            }
            return [
                'plenty' => [
                    'id' => $paymentMethod->id,
                    'pluginKey' => $paymentMethod->pluginKey,
                    'paymentKey' => $paymentMethod->paymentKey,
                    'name' => $paymentMethod->name,
                ],
                'unzer' => $unzerDetails,
            ];
        }

        return null;
    }

    public function getPaymentMethodId($paymentMethodCode, $paymentMethodName): int
    {
        if (!isset(self::$paymentMethodId[$paymentMethodCode])) {
            $paymentMethodId = $this->getExistingPaymentMethodId($paymentMethodCode);
            if ($paymentMethodId === false) {
                $paymentMethodData = [
                    'pluginKey' => Constants::PLUGIN_KEY,
                    'paymentKey' => $paymentMethodCode,
                    'name' => $paymentMethodName,
                ];

                $this->paymentMethodRepository->createPaymentMethod($paymentMethodData);
                $paymentMethodId = $this->getExistingPaymentMethodId($paymentMethodCode);
            }
            self::$paymentMethodId[$paymentMethodCode] = $paymentMethodId;
        }

        return (int)self::$paymentMethodId[$paymentMethodCode];
    }


    public function getExistingPaymentMethodId($paymentMethodCode)
    {
        $paymentMethods = $this->paymentMethodRepository->allForPlugin(Constants::PLUGIN_KEY);
        if (!is_null($paymentMethods)) {
            foreach ($paymentMethods as $paymentMethod) {
                if ($paymentMethod->paymentKey === $paymentMethodCode) {
                    return $paymentMethod->id;
                }
            }
        }

        return false;
    }

    public function getPaymentMethodDataFromUnzerPaymentTypeId(string $unzerPaymentTypeId): ?array{
        $parts = explode('-', $unzerPaymentTypeId);
        $shortCode = $parts[1];
        foreach(Constants::PAYMENT_METHODS as $paymentMethod){
            if($paymentMethod['short_code'] === $shortCode){
                return $paymentMethod;
            }
        }
        return null;
    }
}
