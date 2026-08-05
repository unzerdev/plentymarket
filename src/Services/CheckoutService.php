<?php

namespace UnzerPayment\Services;


use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Basket\Models\Basket as PlentyBasket;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Frontend\PaymentMethod\Contracts\FrontendPaymentMethodRepositoryContract;
use Plenty\Modules\Frontend\Services\VatService;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Models\OrderAmount;
use Plenty\Modules\Order\Models\OrderItem;
use Plenty\Modules\Order\Models\OrderItemAmount;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Modules\Webshop\ItemSearch\SearchPresets\BasketItems;
use Plenty\Modules\Webshop\ItemSearch\Services\ItemSearchService;
use UnzerPayment\Traits\LoggingTrait;
use UnzerPayment\Traits\TranslationTrait;

class CheckoutService
{
    use LoggingTrait;
    use TranslationTrait;

    private ApiService $apiService;

    public function __construct(ApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    public function createUnzerPayPageFromOrder(Order $order, ?string $reference = null, $allPaymentMethods = false): ?array
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', [
            'order' => $order,
        ]);
        $paymentMethodService = pluginApp(PaymentMethodService::class);
        $orderService = pluginApp(OrderService::class);
        /** @var OrderAmount $amount */
        $amount = $order->amounts[0];
        /** @var OrderItemAmount $shippingAmount */
        $shippingAmount = null;
        if ($shippingItem = $orderService->getShippingItemObject($order)) {
            $shippingAmount = $shippingItem->amounts[0];
            if($shippingAmount->currency !== $amount->currency){
                foreach($shippingItem->amounts as $cShippingAmount){
                    if($cShippingAmount->currency === $amount->currency){
                        $shippingAmount = $cShippingAmount;
                        break;
                    }
                }
            }
        }

        $items = [];
        /** @var OrderItem $orderItem */
        foreach ($orderService->getRegularItemObjects($order) as $orderItem) {
            /** @var OrderItemAmount $orderItemAmount */
            $orderItemAmount = $orderItem->amounts[0];
            if($orderItemAmount->currency !== $amount->currency){
                foreach($orderItem->amounts as $cOrderItemAmount){
                    if($cOrderItemAmount->currency === $amount->currency){
                        $orderItemAmount = $cOrderItemAmount;
                        break;
                    }
                }
            }
            $item = [
                'priceNet' => $orderItemAmount->priceNet,
                'price' => $orderItemAmount->priceGross,
                'quantity' => $orderItem->quantity,
                'vat' => $orderItemAmount->priceGross - $orderItemAmount->priceNet,
                'name' => $orderItem->orderItemName,
            ];
            $items[] = $item;
        }
        $shippingAddress = $order->deliveryAddress;
        $billingAddress = $order->billingAddress;
        $this->log(__CLASS__, __METHOD__, 'addressIds', '', ['shippingAddress' => $shippingAddress, 'billingAddressId' => $billingAddress]);

        $countryRepository = pluginApp(CountryRepositoryContract::class);
        $shippingCountry = $countryRepository->getCountryById($shippingAddress->countryId);
        $billingCountry = $countryRepository->getCountryById($billingAddress->countryId);

        $paymentMethodId = $order->methodOfPaymentId;
        $paymentMethodData = $paymentMethodService->getPaymentMethodData($paymentMethodId);



        $basketData = [
            'customerId' => $orderService->getOrderRelationValue($order, 'receiver'),
            'basketAmount' => $amount->invoiceTotal,
            'basketAmountNet' => $amount->netTotal,
            'isNet' => (bool)$amount->isNet,
            'currency' => $amount->currency,
            'shippingAmountNet' => $shippingAmount ? $shippingAmount->priceNet : 0,
            'shippingAmount' => $shippingAmount ? $shippingAmount->priceGross : 0,
        ];

        $checkoutData = [
            'basket' => $basketData,
            'basketItems' => $items,
            'orderId'=>$order->id,
            'shippingAddress' => $shippingAddress,
            'shippingCountry' => $shippingCountry,
            'billingAddress' => $billingAddress,
            'billingCountry' => $billingCountry,
        ];

        if($allPaymentMethods){
            $frontendPaymentRepository = pluginApp(FrontendPaymentMethodRepositoryContract::class);
            $switchableToPaymentMethods = $frontendPaymentRepository->getCurrentPaymentMethodsListForSwitch($paymentMethodId, $order->id);
            $this->log(__CLASS__, __METHOD__, 'switchableTo', '', ['$switchableToPaymentMethods' => $switchableToPaymentMethods]);
            $shortCodes = [];
            foreach($switchableToPaymentMethods as $switchableToPaymentMethod){
                $switchableToPaymentMethod = (array)$switchableToPaymentMethod;
                $this->log(__CLASS__, __METHOD__, 'switchableToSingle', '', ['$switchableToPaymentMethod' => $switchableToPaymentMethod]);
                $switchableToPaymentMethodData = $paymentMethodService->getPaymentMethodData((int)$switchableToPaymentMethod['id']);
                $shortCodes[] = $switchableToPaymentMethodData['unzer']['short_code'];
            }
        }



        return $this->apiService->createPayPageNew(
            $checkoutData,
            $reference,
            $allPaymentMethods&&!empty($shortCodes) ? implode('|', $shortCodes) : ($paymentMethodData['unzer']['short_code'] ?? null)
        );
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

        $basketItemData = $this->getBasketItemData($basketItems, '');
        foreach ($basketItems as $k => $basketItem) {
            if (array_key_exists($basketItem['variationId'], $basketItemData)) {
                $basketItems[$k]['variation_data'] = $basketItemData[$basketItem['variationId']];
            } else {
                $basketItems[$k]['variation_data'] = [];
            }
        }
        $this->log(__CLASS__, __METHOD__, 'basket_items_data', '', ['items' => $basketItems]);

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

    private function getBasketItemData($basketItems = [], string $language = ''): array
    {
        if (empty($basketItems)) {
            return [];
        }

        $basketItemVariationIds = [];
        $basketVariationQuantities = [];

        foreach ($basketItems as $basketItem) {
            $basketItemVariationIds[] = $basketItem['variationId'];
        }

        /** @var ItemSearchService $itemSearchService */
        $itemSearchService = pluginApp(ItemSearchService::class);
        $items = $itemSearchService->getResults(
            BasketItems::getSearchFactory(
                [
                    'variationIds' => $basketItemVariationIds,
                    'language' => $language,
                ]
            )
        );

        $result = [];
        if (isset($items['documents']) && is_array($items['documents'])) {
            foreach ($items['documents'] as $item) {
                $variationId = $item['data']['variation']['id'];
                $result[$variationId] = $item;
            }
        }

        return $result;
    }

    public function scheduleGeneralErrorNotification(): void
    {
        $this->scheduleNotification(
            $this->getTranslation('Frontend.generalError')
        );
    }

    public function scheduleNotification($message, $type = 'error'): void
    {
        $notification = [
            'message' => $message,
            'code' => 0,
            'stackTrace' => [],
        ];
        $notifications[$type] = $notification;
        $this->setToSession('notifications', json_encode($notifications));
    }

    public function setToSession($key, $value): void
    {
        $session = pluginApp(FrontendSessionStorageFactoryContract::class);
        $session->getPlugin()->setValue($key, $value);
    }


}
