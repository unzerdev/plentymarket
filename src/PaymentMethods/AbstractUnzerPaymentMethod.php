<?php

namespace UnzerPayment\PaymentMethods;

use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Payment\Method\Models\PaymentMethod;
use Plenty\Modules\Payment\Method\Services\PaymentMethodBaseService;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Plugin\Application;
use Plenty\Plugin\Http\Request;
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

    const ALLOWED_COUNTRIES = [];
    const IS_B2C_ONLY = false;

    use LoggingTrait;

    protected static ?Application $app = null;

    protected static ?PaymentMethod $paymentMethod = null;

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
        $currentCountryId = null;

        try {
            if (!$this->hasValidApiKeys()) {
                return false;
            }

            if (!$this->isAddressAllowed($currentCountryId)) {
                return false;
            }

            return $this->isPaymentTypeSupported();
        } catch (\Throwable $e) {
            $this->error(__CLASS__, __METHOD__, 'error', '', ['error' => $e->getMessage()]);
        }

        return false;
    }

    private function hasValidApiKeys(): bool
    {
        $configService = pluginApp(ConfigService::class);
        return !empty($configService->getPrivateKey()) && !empty($configService->getPublicKey());
    }

    private function isAddressAllowed(?int $currentCountryId = null): bool
    {
        if (empty(static::ALLOWED_COUNTRIES)) {
            return true;
        }

        if (empty($currentCountryId) || static::IS_B2C_ONLY) {
            $checkout = pluginApp(Checkout::class);
            $billingAddressId = $checkout->getCustomerInvoiceAddressId() ?: $checkout->getCustomerShippingAddressId();
            if (empty($billingAddressId)) {
                return false;
            }

            $addressRepository = pluginApp(AddressRepositoryContract::class);

            $authHelper = pluginApp(AuthHelper::class);


            /** @var Address $billingAddress */
            $billingAddress = $authHelper->processUnguarded(function () use ($addressRepository, $billingAddressId) {
                return $addressRepository->findAddressById($billingAddressId);
            });
            $currentCountryId = $billingAddress->countryId;
            if(static::IS_B2C_ONLY && !empty($billingAddress->companyName)){
                return false;
            }
        }

        $countryRepository = pluginApp(CountryRepositoryContract::class);
        $billingCountry = $countryRepository->getCountryById($currentCountryId);

        return in_array($billingCountry->isoCode2, static::ALLOWED_COUNTRIES);
    }

    private function isPaymentTypeSupported(?string $currency = null): bool
    {
        $apiService = pluginApp(ApiService::class);
        $paymentTypes = $apiService->getAvailablePaymentTypes();
        if (empty($paymentTypes)) {
            return false;
        }

        foreach ($paymentTypes as $paymentType) {
            $paymentType = (array)$paymentType;
            $code = strtolower(str_replace('-', '_', $paymentType['type']));

            if ($code === static::UNZER_LONG_CODE) {
                if ($this->isCurrencySupported($paymentType, $currency)) {
                    // there might be several entries for the same payment type code
                    return true;
                }
            }
        }

        return false;
    }

    private function isCurrencySupported(array $paymentType, ?string $currency = null): bool
    {
        if(empty($currency)) {
            $checkout = pluginApp(Checkout::class);
            $currency = $checkout->getCurrency();
        }

        if (empty($currency) || strlen($currency) !== 3) {
            return true;
        }

        $supportedCurrencies = $paymentType['currency'] ?? [];
        if (empty($supportedCurrencies)) {
            return true;
        }

        return in_array($currency, $supportedCurrencies);
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
        return true;
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
