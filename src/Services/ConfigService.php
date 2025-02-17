<?php

namespace UnzerPayment\Services;


use IO\Extensions\Constants\ShopUrls;
use IO\Services\SessionStorageService;
use IO\Services\UrlBuilder\UrlQuery;
use IO\Services\WebstoreConfigurationService;
use Plenty\Modules\Helper\Services\WebstoreHelper;
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

    public function getShopCheckoutUrlRelative(): string
    {
        $shopUrls = pluginApp(ShopUrls::class);
        return (string)$shopUrls->checkout;
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
            $plugin = $pluginRepo->decoratePlugin($plugin, $pluginSetId);
            return $plugin;
        }
        return null;
    }

    public function getStoreName(): string
    {
        /** @var WebstoreHelper $storeHelper */
        $storeHelper = pluginApp(WebstoreHelper::class);
        $storeConfig = $storeHelper->getCurrentWebstoreConfiguration();
        $storeName = $storeConfig->name;
        return (string)$storeName;
    }

    public function isExternalOrderMatchingActive(): bool
    {
        return $this->getConfigurationValue('useExternalOrderMatching') === 'true';
    }

    public function getPrivateKey():string
    {
        return (string)$this->getConfigurationValue('privateKey');
    }

    public function getPublicKey():string
    {
        return (string)$this->getConfigurationValue('publicKey');
    }

    public function getWebhookUrl():?string
    {
        return $this->getUrl('payment/unzer-webhook');
    }

    public function getReturnUrl(?string $reference = null):?string
    {
        $url = $this->getUrl('payment/unzer-checkout-return');
        if(!empty($reference)){
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'reference=' . $reference;
        }
        return $url;
    }
}