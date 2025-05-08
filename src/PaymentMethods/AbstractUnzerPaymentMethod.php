<?php

namespace UnzerPayment\PaymentMethods;

use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Payment\Method\Models\PaymentMethod;
use Plenty\Modules\Payment\Method\Services\PaymentMethodBaseService;
use Plenty\Plugin\Application;
use Plenty\Plugin\Translation\Translator;
use UnzerPayment\Constants\Constants;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\ConfigService;
use UnzerPayment\Services\PaymentMethodService;
use UnzerPayment\Traits\LoggingTrait;

class AbstractUnzerPaymentMethod extends PaymentMethodBaseService
{
    const UNZER_SHORT_CODE = '';
    const UNZER_LONG_CODE = '';
    const PAYMENT_METHOD_CODE = '';
    const PAYMENT_METHOD_NAME = '';

    use LoggingTrait;

    protected static ?Application $app = null;

    protected static ?PaymentMethod $paymentMethod = null;

    final public static function getPaymentMethod(): ?PaymentMethod
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

    final protected function getApp(): Application
    {
        if (empty(self::$app)) {
            self::$app = pluginApp(Application::class);
        }
        return self::$app;
    }

    final public static function getPaymentMethodId(): int
    {
        return pluginApp(PaymentMethodService::class)->getPaymentMethodId(static::PAYMENT_METHOD_CODE, static::PAYMENT_METHOD_NAME);
    }

    final public function isActive(): bool
    {
        try {
            $configService = pluginApp(ConfigService::class);
            if (!$configService->getPrivateKey() || !$configService->getPublicKey()) {
                return false;
            }
            /** @var ApiService $apiService */
            $apiService = pluginApp(ApiService::class);
            $paymentTypes = $apiService->getAvailablePaymentTypes();

            if (empty($paymentTypes)) {
                return false;
            }

            foreach ($paymentTypes as $paymentType) {
                $paymentType = (array)$paymentType;
                $code = strtolower(str_replace('-', '_', $paymentType['type']));

                if ($code === static::UNZER_LONG_CODE) {
                    /** @var Checkout $checkout */
                    $checkout = pluginApp(Checkout::class);
                    $currency = $checkout->getCurrency();
                    if (!empty($currency) && strlen($currency) === 3) {
                        $supportedCurrencies = $paymentType['currency'] ?? [];
                        if (!empty($supportedCurrencies)) {
                            if (!in_array($currency, $supportedCurrencies)) {
//                                $this->log(__CLASS__, __METHOD__, 'currencyNotSupported', '', [
//                                    'currency' => $currency,
//                                    'supportedCurrencies' => $supportedCurrencies,
//                                ]);
                                return false;
                            }
                        }
                    }
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->error(__CLASS__, __METHOD__, 'error', '', ['error' => $e->getMessage()]);
        }

        return false;
    }

    final public function getName(string $lang = ""): string
    {
        $translator = pluginApp(Translator::class);
        return $translator->trans('UnzerPayment::Frontend.paymentMethodName_' . static::UNZER_LONG_CODE, [], $lang);
    }

    final public function getFee(): float
    {
        return 0;
    }

    final public function getIcon(string $lang = ""): string
    {
        $pluginPath = $this->getApp()->getUrlPath(Constants::PLUGIN_NAME);
        return $pluginPath . '/images/icons/' . static::UNZER_LONG_CODE . '.png';
    }

    final public function getDescription(string $lang = ""): string
    {
        $translator = pluginApp(Translator::class);
        return $translator->trans('UnzerPayment::Frontend.paymentMethodDescription_' . static::UNZER_LONG_CODE, [], $lang);
    }

    final public function getSourceUrl(string $lang = ""): string
    {
        return '';
    }

    final public function isSwitchableTo(): bool
    {
        return false;
    }

    final public function isSwitchableFrom(): bool
    {
        return true;
    }

    final public function isBackendSearchable(): bool
    {
        return true;
    }

    final public function isBackendActive(): bool
    {
        return true;
    }

    final public function getBackendName(string $lang = ""): string
    {
        return 'Unzer ' . $this->getName($lang);
    }

    final public function canHandleSubscriptions(): bool
    {
        return false;
    }

    final public function getBackendIcon(): string
    {
        return $this->getIcon();
    }
}