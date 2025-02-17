<?php

namespace UnzerPayment\PaymentMethods;

use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Method\Models\PaymentMethod;
use Plenty\Modules\Payment\Method\Services\PaymentMethodBaseService;
use Plenty\Plugin\Translation\Translator;
use UnzerPayment\Services\ConfigService;
use UnzerPayment\Services\PaymentMethodService;

class UnzerPaymentMethod extends PaymentMethodBaseService
{
    const PAYMENT_METHOD_CODE = 'UNZER_PAYMENT';
    const PAYMENT_NAME = 'Unzer Payments';
    protected static ?PaymentMethod $paymentMethod = null;

    public static function getPaymentMethod(): ?PaymentMethod
    {
        if (empty(self::$paymentMethod)) {
            $paymentMethodRepository = pluginApp(PaymentMethodRepositoryContract::class);
            $paymentMethod = $paymentMethodRepository->findByPaymentMethodId(self::getPaymentMethodId());
            if (!$paymentMethod) {
                return null;
            }
            self::$paymentMethod = $paymentMethod;
        }
        return self::$paymentMethod;
    }

    public static function getPaymentMethodId(): int
    {
        return pluginApp(PaymentMethodService::class)->getPaymentMethodId();
    }

    public function isActive(): bool
    {
        $configService = pluginApp(ConfigService::class);
        return $configService->getPrivateKey() && $configService->getPublicKey();
    }

    public function getName(string $lang = ""): string
    {
        $translator = pluginApp(Translator::class);
        return $translator->trans('UnzerPayment::Frontend.paymentMethodName', [], $lang);
    }

    public function getFee(): float
    {
        return 0;
    }

    public function getIcon(string $lang = ""): string
    {
        return 'data:image/png;base64,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';
    }

    public function getDescription(string $lang = ""): string
    {
        $translator = pluginApp(Translator::class);
        return $translator->trans('UnzerPayment::Frontend.paymentMethodDescription', [], $lang);
    }

    public function getSourceUrl(string $lang = ""): string
    {
        return '';
    }

    public function isSwitchableTo(): bool
    {
        return false;
    }

    public function isSwitchableFrom(): bool
    {
        return true;
    }

    public function isBackendSearchable(): bool
    {
        return true;
    }

    public function isBackendActive(): bool
    {
        return true;
    }

    public function getBackendName(string $lang = ""): string
    {
        return $this->getName($lang);
    }

    public function canHandleSubscriptions(): bool
    {
        return false;
    }

    public function getBackendIcon(): string
    {
        return $this->getIcon();
    }
}