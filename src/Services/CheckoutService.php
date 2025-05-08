<?php

namespace UnzerPayment\Services;


use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Basket\Models\Basket as PlentyBasket;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Frontend\Services\VatService;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use UnzerPayment\Traits\LoggingTrait;

class CheckoutService
{
    use LoggingTrait;

    private ApiService $apiService;

    public function __construct(ApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    public function createUnzerPayPageFromBasket(PlentyBasket $basket, array $basketItems, Checkout $checkout, ?array $paymentMethodData = null, ?string $reference = null): ?array
    {
        return $this->apiService->createPayPage(
            $this->getCheckoutData($basket, $basketItems, $checkout),
            $reference,
            $paymentMethodData['unzer']['short_code'] ?? null,
        );
    }

    public function getCheckoutData(PlentyBasket $basket, array $basketItems, Checkout $checkout): array
    {
        $isNet = false;
        if (!empty($basket) && !empty($basket->itemSum)) {
            /** @var VatService $vatService */
            $vatService = pluginApp(VatService::class);
            $vats = $vatService->getCurrentTotalVats();

            $order = pluginApp(SessionStorageRepositoryContract::class)->getOrder();
            $isOrderNet = false;
            if (!is_null($order)) {
                $isOrderNet = $order->isNet;
            }
            if (empty($vats) && $isOrderNet) {
                $isNet = true;
            }
        }

        $basketData = $basket->toArray();
        $basketData['isNet'] = $isNet;

        $this->log(__CLASS__, __METHOD__, 'basket', '', ['basketData' => $basketData, 'items' => $basketItems]);

        $shippingAddressId = $checkout->getCustomerShippingAddressId() ?? $checkout->getCustomerInvoiceAddressId();
        $billingAddressId = $checkout->getCustomerInvoiceAddressId();
        $this->log(__CLASS__, __METHOD__, 'addressIds', '', ['shippingAddressId' => $shippingAddressId, 'billingAddressId' => $billingAddressId]);

        $addressRepository = pluginApp(AddressRepositoryContract::class);
        $countryRepository = pluginApp(CountryRepositoryContract::class);
        $authHelper = pluginApp(AuthHelper::class);

        $shippingAddress = $authHelper->processUnguarded(function () use ($addressRepository, $shippingAddressId) {
            return $addressRepository->findAddressById($shippingAddressId);
        });
        $shippingCountry = $countryRepository->getCountryById($shippingAddress->countryId);

        $billingAddress = $authHelper->processUnguarded(function () use ($addressRepository, $billingAddressId) {
            return $addressRepository->findAddressById($billingAddressId);
        });
        $billingCountry = $countryRepository->getCountryById($billingAddress->countryId);

        return [
            'basket' => $basketData,
            'basketItems' => $basketItems,
            'shippingAddress' => $shippingAddress,
            'shippingCountry' => $shippingCountry,
            'billingAddress' => $billingAddress,
            'billingCountry' => $billingCountry,
        ];
    }


}
