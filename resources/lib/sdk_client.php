<?php
//$class = new ReflectionClass('SdkRestApi');
//$methods = $class->getMethods();
//"methods": [ { "name": "__construct", "class": "SdkRestApi" }, { "name": "getParam", "class": "SdkRestApi" }, { "name": "run", "class": "SdkRestApi" }, { "name": "checkParams", "class": "SdkRestApi" }, { "name": "addError", "class": "SdkRestApi" }, { "name": "getBaseDir", "class": "SdkRestApi" }, { "name": "getScriptFile", "class": "SdkRestApi" }, { "name": "getDirectory", "class": "SdkRestApi" } ] }


use UnzerSDK\Constants\BasketItemTypes;
use UnzerSDK\Constants\ShippingTypes;
use UnzerSDK\Constants\WebhookEvents;
use UnzerSDK\Resources\Basket;
use UnzerSDK\Resources\Customer;
use UnzerSDK\Resources\EmbeddedResources\Address;
use UnzerSDK\Resources\EmbeddedResources\BasketItem;
use UnzerSDK\Resources\Metadata;
use UnzerSDK\Resources\PaymentTypes\Paypage;
use UnzerSDK\Resources\TransactionTypes\Charge;
use UnzerSDK\Unzer;

$errors = [];

class ApiHelperSdk
{
    const PLENTY_ADDRESS_OPTION_EMAIL = 5;
    private static Unzer $unzer;


    public function getUnzerObject(): Unzer
    {
        if (!isset(self::$unzer)) {
            $privateKey = SdkRestApi::getParam('privateKey');
            self::$unzer = new Unzer($privateKey);
        }

        return self::$unzer;
    }

    public function getPayment($paymentId): array
    {
        $payment = $this->getUnzerObject()->fetchPayment($paymentId);
        $returnValues = $payment->expose();
        $amount = $payment->getAmount();
        $returnValues['amount'] = [
            'total' => $amount->getTotal(),
            'charged' => $amount->getCharged(),
            'canceled' => $amount->getCanceled(),
            'remaining' => $amount->getRemaining(),
            'currency' => $amount->getCurrency(),
        ];
        $returnValues['customer'] = [
            'email'=> $payment->getCustomer()->getEmail(),
        ];
        $returnValues['state'] = $payment->getStateName();
        return $returnValues;
    }

    public function createWebhook(string $url): void
    {
        $this->getUnzerObject()->createWebhook($url, WebhookEvents::ALL);
    }

    public function getWebhooks()
    {
        $webhooks = $this->getUnzerObject()->fetchAllWebhooks();
        $return = [];
        /** @var \UnzerSDK\Resources\Webhook $webhook */
        foreach ($webhooks as $webhook) {
            $return[] = $webhook->expose();
        }
        return $return;
    }

    public function createPayPage($checkoutData, $returnUrl)
    {
        $basket = $this->getBasket($checkoutData);
        $customer = $this->getCustomer($checkoutData);
        $payPage = new Paypage($basket->getTotalValueGross(), $basket->getCurrencyCode(), $returnUrl);
//        $threatMetrixId = md5(HTTPS_SERVER) . '_' . $order->info['orders_id'];
//        $isCustomerRegistered = $this->isCustomerRegistered((int)$order->customer['id']);
//        $payPage
//            ->setAdditionalAttribute('riskData.threatMetrixId', $threatMetrixId)
//            ->setAdditionalAttribute('riskData.customerGroup', 'NEUTRAL')
//            ->setAdditionalAttribute('riskData.customerId', $customer->getCustomerId())
//            ->setAdditionalAttribute('riskData.confirmedAmount', $this->getCustomersTotalOrderAmount((int)$order->customer['id']))
//            ->setAdditionalAttribute('riskData.confirmedOrders', $this->getCustomersTotalNumberOfOrders((int)$order->customer['id']))
//            ->setAdditionalAttribute('riskData.registrationLevel', $isCustomerRegistered ? '1' : '0')
//            ->setAdditionalAttribute('riskData.registrationDate', $this->getCustomersRegistrationDate((int)$order->customer['id']));
//
//        if(!$isCustomerRegistered){
//            $payPage->setAdditionalAttribute('disabledCOF', 'card,paypal,sepa-direct-debit');
//        }
//
//        $payPage->setOrderId($order->info['orders_id']);
        $metaData = $this->getMetaData(); //TODO order id?

        $payPage = $this->getUnzerObject()->initPayPageCharge($payPage, $customer, $basket, $metaData);


        $returnValues = $payPage->expose();
        $returnValues['paymentId'] = $payPage->getPaymentId();
        $returnValues['amount'] = $payPage->getAmount();
        $returnValues['currency'] = $payPage->getCurrency();
        $returnValues['test'] = 1;

        return $returnValues;
    }

    protected function getMetaData($orderId = null): Metadata
    {

        $metaData = new Metadata();
        $metaData
            ->setShopType('plentyMarkets')
            ->setShopVersion('1.0.0') //TODO
            ->addMetadata('pluginType', 'plentyMarkets')
            ->addMetadata('pluginVersion', '0.1.0'); //TODO

        if ($orderId !== null) {
            $metaData->addMetadata('orderId', $orderId);
        }
        return $metaData;
    }

    public function getCustomer($checkoutData): Customer
    {
        $customerId = uniqid();
        try {
            $customer = $this->getUnzerObject()->fetchCustomerByExtCustomerId('plenty-' . $customerId);
        } catch (Exception $e) {
            // no worries, we cover this by creating a new customer
        }

        if (empty($customer)) {
            $customer = new Customer();
            $customer->setCustomerId('gx4-' . $customerId);
        }
        $billingAddress = $checkoutData['billingAddress'];

        $customer
            ->setFirstname($billingAddress['name2'] ?: '')
            ->setLastname($billingAddress['name3'] ?: '')
            ->setPhone('')
            ->setCompany($billingAddress['name1'] ?: '')
            ->setEmail(self::getOption($billingAddress['options'], self::PLENTY_ADDRESS_OPTION_EMAIL) ?: '');

        $this->setAddresses($customer, $checkoutData);


        if ($customer->getId()) {
            try {
                $this->getUnzerObject()->updateCustomer($customer);
            } catch (Exception $e) {
                global $errors;
                $errors[] = $e->getMessage();
            }
        }

        return $customer;
    }


    protected function setAddresses(Customer $customer, array $checkoutData)
    {
        $shippingType = ShippingTypes::EQUALS_BILLING;
        if ($checkoutData['shippingAddress']['id'] != $checkoutData['billingAddress']['id']) {
            $shippingType = ShippingTypes::DIFFERENT_ADDRESS;
        }

        $billingAddress = (new Address())
            ->setName($checkoutData['billingAddress']['name2'] . ' ' . $checkoutData['billingAddress']['name3'])
            ->setStreet($checkoutData['billingAddress']['address1'] . ' ' . $checkoutData['billingAddress']['address2'])
            ->setZip($checkoutData['billingAddress']['postalCode'])
            ->setCity($checkoutData['billingAddress']['town'])
            ->setCountry($checkoutData['billingCountry']['isoCode2']);

        if ($checkoutData['shippingAddress']) {
            $shippingAddress = (new Address())
                ->setName($checkoutData['shippingAddress']['name2'] . ' ' . $checkoutData['shippingAddress']['name3'])
                ->setStreet($checkoutData['shippingAddress']['address1'] . ' ' . $checkoutData['shippingAddress']['address2'])
                ->setZip($checkoutData['shippingAddress']['postalCode'])
                ->setCity($checkoutData['shippingAddress']['town'])
                ->setCountry($checkoutData['shippingCountry']['isoCode2'])
                ->setShippingType($shippingType);
        } else {
            $shippingAddress = $billingAddress;
            $shippingAddress->setShippingType(ShippingTypes::EQUALS_BILLING);
        }

        $customer
            ->setShippingAddress($shippingAddress)
            ->setBillingAddress($billingAddress);
    }


    public function getBasket($checkoutData): Basket
    {
        $basketData = $checkoutData['basket'];
        $basket = (new Basket())
            ->setTotalValueGross($basketData['basketAmount'])
            ->setOrderId(uniqid())
            ->setCurrencyCode($basketData['currency']);

        $basketItems = [];

        foreach ($checkoutData['basketItems'] as $basketItem) {
            $basketItem = (new BasketItem())
                ->setTitle(uniqid())//TODO
                ->setQuantity($basketItem['quantity'])
                ->setType(BasketItemTypes::GOODS)
                ->setAmountPerUnitGross($basketItem['price'])
                ->setVat($basketItem['vat']);
            $basketItems[] = $basketItem;
        }

        if ($basketData['shippingAmount'] > 0) {

            $shippingAmount = $basketData['shippingAmount'];
            $shippingAmountNet = $basketData['shippingAmountNet'];
            $shippingVatAbs = $shippingAmount - $shippingAmountNet;
            $shippingVat = $shippingVatAbs / $shippingAmountNet * 100;

            $basketItem = (new BasketItem())
                ->setTitle('Shipping') //TODO
                ->setQuantity(1)
                ->setType(BasketItemTypes::SHIPMENT)
                ->setAmountPerUnitGross($basketData['shippingAmount'])
                ->setVat($shippingVat);
            $basketItems[] = $basketItem;
        }

//        $q = "SELECT * FROM coupon_gv_redeem_track WHERE orders_id = " . (int)$basket->info['orders_id'];
//        $rs = xtc_db_query($q);
//        while ($r = xtc_db_fetch_array($rs)) {
//            $basketItem = (new BasketItem())
//                ->setTitle($r['coupon_code'])
//                ->setQuantity(1)
//                ->setType(BasketItemTypes::VOUCHER)
//                ->setAmountDiscountPerUnitGross(abs($r['amount']))
//                ->setVat(0);
//            $basketItems[] = $basketItem;
//        }

        $totalLeft = $basket->getTotalValueGross();
        foreach ($basketItems as $basketItem) {
            $totalLeft -= $basketItem->getAmountPerUnitGross() * $basketItem->getQuantity();
            $totalLeft += $basketItem->getAmountDiscountPerUnitGross() * $basketItem->getQuantity();
        }

        if (number_format($totalLeft, 2) !== '0.00') {
            if ($totalLeft < 0) {
                $basketItem = (new BasketItem())
                    ->setTitle('---')
                    ->setQuantity(1)
                    ->setType(BasketItemTypes::VOUCHER)
                    ->setAmountDiscountPerUnitGross(round($totalLeft * -1, 2))
                    ->setVat(0);
                $basketItems[] = $basketItem;
            } else {
                $basketItem = (new BasketItem())
                    ->setTitle('---')
                    ->setQuantity(1)
                    ->setType(BasketItemTypes::GOODS)
                    ->setAmountPerUnitGross(round($totalLeft, 2));
                $basketItems[] = $basketItem;
            }
        }
        $basket->setBasketItems($basketItems);

        return $basket;
    }


    public function refund(string $paymentId, float $amount)
    {
        return $this->normalizeCancellationArray(
            $this->getUnzerObject()->cancelPayment(
                $paymentId,
                $amount
            ));
    }

    protected function normalizeCancellationArray(array $cancellations)
    {
        $normalizedCancellations = [];
        /** @var \UnzerSDK\Resources\TransactionTypes\Cancellation $cancellation */
        foreach ($cancellations as $cancellation) {
            $cancellationData = $cancellation->expose();
            $cancellationData['id'] = $cancellation->getId();
            $cancellationData['amount'] = $cancellation->getAmount();
            $cancellationData['success'] = $cancellation->isSuccess();
            $cancellationData['error'] = $cancellation->isError();
            $cancellationData['pending'] = $cancellation->isPending();
            $normalizedCancellations[] = $cancellationData;
        }
        return $normalizedCancellations;
    }

    public function charge(?string $paymentId, float $amount)
    {
        $this->getUnzerObject()->performChargeOnPayment(
            $paymentId,
            new Charge($amount)
        );
    }

    public function getHeaders()
    {
        return ['x-amz-pay-Idempotency-Key' => uniqid()];
    }

    public static function getOption($options, $typeId)
    {
        foreach ($options as $option) {
            if ((int)$option['typeId'] === $typeId) {
                return $option['value'];
            }
        }
        return null;
    }

}

$return = [
    'response' => [],
];

try {
    $action = SdkRestApi::getParam('action');
    $return["action"] = $action;
    $apiHelper = new ApiHelperSdk();
    $startTime = microtime(true);
    switch ($action) {
        case 'getPayment':
            $return['response']['payment'] = $apiHelper->getPayment(SdkRestApi::getParam('id'));
            break;
        case 'createWebhook':
            try {
                $apiHelper->createWebhook(SdkRestApi::getParam('url'));
            } catch (Exception $e) {
                //catch existing webhooks
                $return = [
                    'exception' => [
                        'object' => $e,
                        'message' => $e->getMessage(),
                        'line' => $e->getLine(),
                        'file' => $e->getFile(),
                    ],
                ];
            }
            $return['response']['webhooks'] = $apiHelper->getWebhooks();
            break;
        case 'createPayPage':
            $return['response']['payPage'] = $apiHelper->createPayPage(SdkRestApi::getParam('checkoutData'), SdkRestApi::getParam('returnUrl'));
            break;
        case 'refund':
            $cancellations = $apiHelper->refund(SdkRestApi::getParam('paymentId'), SdkRestApi::getParam('amount'));
            $return['response']['cancellations'] = $cancellations;
            break;
        case 'charge':
            $apiHelper->charge(SdkRestApi::getParam('paymentId'), SdkRestApi::getParam('amount'));
            break;
    }
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    $return["call_duration"] = $duration;

} catch (Exception $e) {
    $return = [
        'exception' => [
            'object' => $e,
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ],
    ];
}

if (!empty($errors)) {
    $return['errorMessages'] = $errors;
}

return $return;