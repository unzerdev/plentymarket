<?php

namespace UnzerPayment\Services;


use IO\Extensions\Constants\ShopUrls;
use IO\Services\SessionStorageService;
use IO\Services\UrlBuilder\UrlQuery;
use IO\Services\WebstoreConfigurationService;
use Plenty\Modules\Plugin\Contracts\PluginRepositoryContract;
use Plenty\Modules\Plugin\Models\Plugin;
use Plenty\Modules\Webshop\Contracts\LocalizationRepositoryContract;
use Plenty\Plugin\ConfigRepository;
use UnzerPayment\Traits\LoggingTrait;

class ConfigService
{
    use LoggingTrait;

    private ConfigRepository $configRepository;

    public function __construct(ConfigRepository $configRepository)
    {
        $this->configRepository = $configRepository;
    }


    public function getConfigurationValue($key)
    {
        return $this->configRepository->get('UnzerPayment.' . $key);
    }

    public function getUrl($path): ?string
    {
        return $this->getAbsoluteUrl($path);
    }

    public function getAbsoluteUrl($path): ?string
    {
        $webstoreConfigurationService = pluginApp(WebstoreConfigurationService::class);
        $sessionStorage = pluginApp(SessionStorageService::class);
        $defaultLanguage = $webstoreConfigurationService->getDefaultLanguage();
        $lang = $sessionStorage->getLang();

        $includeLanguage = $lang !== null && $lang !== $defaultLanguage;
        $urlQuery = pluginApp(UrlQuery::class, ['path' => $path, 'lang' => $lang]);

        return $urlQuery->toAbsoluteUrl($includeLanguage);
    }

    public function getShopCheckoutUrl(): string
    {
        return $this->getAbsoluteUrl($this->getShopCheckoutUrlRelative());
    }

    public function getShopConfirmationUrl(): string
    {
        return $this->getAbsoluteUrl($this->getShopConfirmationUrlRelative());
    }



    public function getShopCheckoutUrlRelative(): string
    {
        $shopUrls = pluginApp(ShopUrls::class);
        return (string)$shopUrls->checkout;
    }

    public function getShopConfirmationUrlRelative(): string
    {
        $shopUrls = pluginApp(ShopUrls::class);
        return (string)$shopUrls->confirmation;
    }

    public function getLocale(): ?string
    {
        /** @var LocalizationRepositoryContract $localizationRepository */
        $localizationRepository = pluginApp(LocalizationRepositoryContract::class);
        return (string)$localizationRepository->getLocale();
    }

    public function getPluginVersion(): ?string
    {
        $plugin = $this->getDecoratedPlugin('UnzerPayment');
        $version = $plugin->version;
        if (preg_match('/^(\d+\.\d+\.\d)/', $version, $match)) {
            return $match[1];
        }
        return null;
    }

    public function getShopVersion(): ?string
    {
        $plugin = $this->getDecoratedPlugin('Ceres');
        $version = $plugin->version;
        if (preg_match('/^(\d+\.\d+\.\d)/', $version, $match)) {
            return $match[1];
        }
        return null;
    }

    public function getDecoratedPlugin(string $pluginName, $pluginSetId = null): ?Plugin
    {

        $pluginRepo = pluginApp(PluginRepositoryContract::class);
        $plugin = $pluginRepo->getPluginByName($pluginName);
        if ($plugin && $plugin->name) {
            return $pluginRepo->decoratePlugin($plugin, $pluginSetId);
        }
        return null;
    }

    public function isExternalOrderMatchingActive(): bool
    {
        return $this->getConfigurationValue('useExternalOrderMatching') === 'true';
    }

    public function getPrivateKey(): string
    {
        return (string)$this->getConfigurationValue('privateKey');
    }

    public function getPublicKey(): string
    {
        return (string)$this->getConfigurationValue('publicKey');
    }

    public function getBookingMode(?string $paymentTypeCode): string
    {
        $response = null;
        switch ($paymentTypeCode) {
            case 'apl':
                $response = (string)$this->getConfigurationValue('bookingModeApplePay');
                break;
            case 'crd':
                $response = (string)$this->getConfigurationValue('bookingModeCard');
                break;
            case 'gop':
                $response = (string)$this->getConfigurationValue('bookingModeGooglePay');
            case 'ppl':
                $response = (string)$this->getConfigurationValue('bookingModePaypal');
                break;
            case 'wro':
                $response = 'charge'; //(string)$this->getConfigurationValue('bookingModeWero');
                break;
        }
        return in_array($response, ['charge', 'authorize'])?$response:'charge';
    }

    public function getPayUrl($orderId, $orderAccessKey, $allMethods = false): ?string
    {
        return $this->getUrl('payment/unzer-pay').'?orderId='.$orderId.'&orderAccessKey='.$orderAccessKey.($allMethods?'&allMethods=1':'');
    }

    public function getWebhookUrl(): ?string
    {
        return $this->getUrl('payment/unzer-webhook');
    }

    public function getPayReturnUrl(?string $reference = null, $orderId = null): ?string
    {
        $url = $this->getUrl('payment/unzer-checkout-pay-return');
        if (!empty($reference)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'reference=' . $reference;
        }
        if (!empty($orderId)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'orderId=' . $orderId;
        }
        return $url;
    }

    public function getReturnUrl(?string $reference = null): ?string
    {
        $url = $this->getUrl('payment/unzer-checkout-return');
        if (!empty($reference)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'reference=' . $reference;
        }
        return $url;
    }

    public function getCancelUrl(): ?string
    {
        $url = $this->getUrl('payment/unzer-checkout-cancel');
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'error-src=js';
        return $url;
    }

    public function getCheckoutErrorUrl(): ?string
    {
        $url = $this->getUrl('payment/unzer-checkout-error');
        return $url;
    }
}