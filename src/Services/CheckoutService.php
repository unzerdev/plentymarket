<?php

namespace UnzerPayment\Services;


use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Basket\Models\Basket as PlentyBasket;
use Plenty\Modules\Basket\Models\BasketItem;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use UnzerPayment\Traits\LoggingTrait;
use UnzerSDK\Resources\Basket;

class CheckoutService
{
    use LoggingTrait;

    private ApiService $apiService;

    public function __construct(ApiService $apiService)
    {
        $this->apiService = $apiService;
    }
    public function createUnzerPayPageFromBasket(PlentyBasket $basket, array $basketItems, Checkout $checkout, ?string $reference = null):?array
    {
        return $this->apiService->createPayPage(
            $this->getCheckoutData($basket, $basketItems, $checkout),
            $reference
        );
    }

    public function getCheckoutData(PlentyBasket $basket, array $basketItems, Checkout $checkout):array
    {

        $basketData = $basket->toArray();
        $this->log(__CLASS__, __METHOD__, 'basket', '', ['basketData'=>$basketData, 'items' => $basketItems]);

        $shippingAddressId = $checkout->getCustomerShippingAddressId() ?? $checkout->getCustomerInvoiceAddressId();
        $billingAddressId = $checkout->getCustomerInvoiceAddressId();;
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
            'billingCountry' => $billingCountry
        ];
    }


}
