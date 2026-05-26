<?php
declare(strict_types=1);

use UnzerSDK\Constants\BasketItemTypes;
use UnzerSDK\Constants\ShippingTypes;
use UnzerSDK\Constants\TransactionTypes;
use UnzerSDK\Constants\WebhookEvents;
use UnzerSDK\Resources\Basket;
use UnzerSDK\Resources\Customer;
use UnzerSDK\Resources\EmbeddedResources\Address;
use UnzerSDK\Resources\EmbeddedResources\BasketItem;
use UnzerSDK\Resources\EmbeddedResources\CompanyInfo;
use UnzerSDK\Resources\EmbeddedResources\Paypage\PaymentMethodConfig;
use UnzerSDK\Resources\EmbeddedResources\Paypage\PaymentMethodsConfigs;
use UnzerSDK\Resources\EmbeddedResources\Paypage\Resources;
use UnzerSDK\Resources\EmbeddedResources\Paypage\Urls;
use UnzerSDK\Resources\Metadata;
use UnzerSDK\Resources\PaymentTypes\Card;
use UnzerSDK\Resources\PaymentTypes\Paypal;
use UnzerSDK\Resources\PaymentTypes\SepaDirectDebit;
use UnzerSDK\Resources\TransactionTypes\Authorization;
use UnzerSDK\Resources\TransactionTypes\Cancellation;
use UnzerSDK\Resources\TransactionTypes\Charge;
use UnzerSDK\Resources\V2\Paypage;
use UnzerSDK\Resources\Webhook;
use UnzerSDK\Services\ResourceService;
use UnzerSDK\Unzer;

class ApiHelperSdk
{
    public const PLENTY_ADDRESS_OPTION_EMAIL = 5;
    /** @var Unzer|null */
    private static ?Unzer $unzer = null;

    public static array $warnings = [];


    public function getUnzerObject(): Unzer
    {
        if (self::$unzer === null) {
            $privateKey = SdkRestApi::getParam('privateKey');
            self::$unzer = new Unzer($privateKey);
        }
        return self::$unzer;
    }

    public function getPayment(string $paymentId): array
    {
        $payment = $this->getUnzerObject()->fetchPayment($paymentId);
        $returnValues = $payment->expose();
        $returnValues['orderId'] = $payment->getOrderId();
        $paymentType = $payment->getPaymentType();
        $returnValues['paymentType'] = [
            'id' => $paymentType->getId(),
            'attributes' => $paymentType->getAdditionalAttributes(),
        ];
        $returnValues['basketOrderId'] = $payment->getBasket()?->getOrderId();
        $amount = $payment->getAmount();
        $returnValues['amount'] = [
            'total' => $amount->getTotal(),
            'charged' => $amount->getCharged(),
            'canceled' => $amount->getCanceled(),
            'remaining' => $amount->getRemaining(),
            'currency' => $amount->getCurrency(),
        ];

        $returnValues['charges'] = [];
        foreach ($payment->getCharges() as $charge) {
            $returnValues['charges'][] = [
                'amount' => $charge->getAmount(),
                'currency' => $charge->getCurrency() ?? $payment->getCurrency(),
                'isSuccess' => $charge->isSuccess(),
                'id' => $charge->getId(),
            ];
        }
        try {
            if ($authorization = $payment->getAuthorization()) {
                $returnValues['authorization'] = [
                    'id' => $authorization->getId(),
                    'isSuccess' => $authorization->isSuccess(),
                    'isPending' => $authorization->isPending(),
                    'amount' => $authorization->getAmount(),
                    'currency' => $authorization->getCurrency(),
                ];
            }

        } catch (Throwable $e) {
            self::$warnings[] = 'Exception in getAuthorization: ' . $e->getMessage();
        }
        $returnValues['refunds'] = [];
        foreach ($payment->getRefunds() as $refund) {
            $returnValues['refunds'][] = [
                'amount' => $refund->getAmount(),
                'currency' => $refund->getCurrency() ?? $payment->getCurrency(),
                'isSuccess' => $refund->isSuccess(),
                'id' => $refund->getId(),
            ];
        }

        $customer = $payment->getCustomer();
        $returnValues['customer'] = [
            'email' => $customer ? $customer->getEmail() : '',
        ];
        $returnValues['state'] = $payment->getStateName();

        try {
            /** @var Authorization $transaction */
            $transaction = $payment->getInitialTransaction();
            if ($transaction && $transaction->getBic() && $transaction->getIban() && $transaction->getHolder() && $transaction->getDescriptor()) {
                $returnValues['paymentInstructions'] = sprintf("%s: %s \n%s: %s \n%s: %s \n%s: %s\n",
                    '__accountHolder__',
                    $transaction->getHolder(),
                    '__iban__',
                    $transaction->getIban(),
                    '__bic__',
                    $transaction->getBic(),
                    '__paymentDescriptor__',
                    $transaction->getDescriptor()
                );
            }
        } catch (Throwable $e) {
            self::$warnings[] = 'Exception in getPayment/getInitialTransaction: ' . $e->getMessage();
        }


        return $returnValues;
    }

    public function createWebhook(string $url): void
    {
        $this->getUnzerObject()->createWebhook($url, WebhookEvents::ALL);
    }

    public function getWebhooks(): array
    {
        $webhooks = $this->getUnzerObject()->fetchAllWebhooks();
        $result = [];
        /** @var Webhook $webhook */
        foreach ($webhooks as $webhook) {
            $result[] = $webhook->expose();
        }
        return $result;
    }

    /**
     * @param array $checkoutData
     * @param string $returnUrl
     * @param string|null $cancelUrl
     * @param string $orderReference
     * @param string|null $paymentTypeCode (might be pip separated list)
     * @param $bookingMode
     * @return array
     */
    public function createPayPage(array $checkoutData, string $returnUrl, ?string $cancelUrl, string $orderReference, ?string $paymentTypeCode = null, $bookingMode = 'charge'): array
    {
        $basket = $this->getBasket($checkoutData, $orderReference);
        $customer = $this->getCustomer($checkoutData);
        $metaData = $this->getMetaData();

        if (empty($bookingMode) || !in_array($bookingMode, [TransactionTypes::CHARGE, TransactionTypes::AUTHORIZATION], true)) {
            $bookingMode = TransactionTypes::CHARGE;
        }
        $payPage = (new Paypage($basket->getTotalValueGross(), $basket->getCurrencyCode(), $bookingMode))
            ->setType('embedded')
            ->setOrderId((string)($checkoutData['orderId'] ?? $basket->getOrderId()))
            ->setCheckoutType('payment_only')
            ->setUrls(
                (new Urls())
                    ->setReturnSuccess($returnUrl)
                    ->setReturnFailure($returnUrl)
                    ->setReturnPending($returnUrl)
                    ->setReturnCancel($returnUrl)
            );

        $isCustomerLoggedIn = !empty($checkoutData['basket']['customerId']);

        if ($paymentTypeCode) {
            $paymentTypeCodeList = array_filter(array_map('trim', explode('|', $paymentTypeCode)));
            $config = new PaymentMethodsConfigs();
            $config->setDefault((new PaymentMethodConfig())->setEnabled(false));
            foreach ($paymentTypeCodeList as $paymentTypeCodeListItem) {
                $paymentType = ResourceService::getTypeInstanceFromIdString('s-' . $paymentTypeCodeListItem . '-0');
                $selectedPaymentMethodConfig = (new PaymentMethodConfig())->setEnabled(true);
                $classNameOfSelectedPaymentMethod = get_class($paymentType);
                if (stripos($classNameOfSelectedPaymentMethod, 'OpenbankingPis') !== false) {
                    $classNameOfSelectedPaymentMethod = strtolower($classNameOfSelectedPaymentMethod);
                }
                if ($isCustomerLoggedIn && in_array($classNameOfSelectedPaymentMethod, [Card::class, SepaDirectDebit::class, Paypal::class], true)) {
                    $selectedPaymentMethodConfig->setCredentialOnFile(true);
                }
                $config->addMethodConfig($classNameOfSelectedPaymentMethod, $selectedPaymentMethodConfig);
            }
            $payPage->setPaymentMethodsConfigs($config);
        }

        $payPage->setResources(
            new Resources(
                $customer->getId(),
                $basket->getId(),
                $metaData->getId()
            )
        );

        $payPage = $this->getUnzerObject()->createPaypage($payPage);
        $returnValues = $payPage->expose();
        $returnValues['payPageId'] = $payPage->getId();
        $returnValues['amount'] = $payPage->getAmount();
        $returnValues['currency'] = $payPage->getCurrency();
        $returnValues['customer'] = $customer->expose();


        return $returnValues;
    }

    protected function getMetaData(): Metadata
    {
        $metaData = new Metadata();
        $metaData
            ->setShopType('plentyMarkets')
            ->setShopVersion(SdkRestApi::getParam('shopVersion'))
            ->addMetadata('pluginType', 'unzerdev/plentymarkets')
            ->addMetadata('pluginVersion', SdkRestApi::getParam('pluginVersion'));

        return $this->getUnzerObject()->createMetaData($metaData);
    }

    public function getCustomer(array $checkoutData): Customer
    {
        $customerId = $checkoutData['basket']['customerId'] ?? uniqid();
        $externalId = 'plenty-c-' . $customerId;
        $customer = null;

        try {
            $customer = $this->getUnzerObject()->fetchCustomerByExtCustomerId($externalId);
        } catch (Exception) {
            // No existing customer found; proceed to create a new one.
        }

        if ($customer === null) {
            $customer = new Customer();
            $customer->setCustomerId($externalId);
        }

        $billingAddress = $checkoutData['billingAddress'] ?? [];
        $companyName = $billingAddress['name1'] ?? '';
        $customer
            ->setFirstname($billingAddress['name2'] ?? '')
            ->setLastname($billingAddress['name3'] ?? '')
            ->setPhone('')
            ->setLanguage('en') // TODO
            ->setCompany($companyName)
            ->setEmail(self::getOption($billingAddress['options'] ?? [], self::PLENTY_ADDRESS_OPTION_EMAIL) ?? '');

        if (!empty($companyName)) {
            $companyInfo = $customer->getCompanyInfo();
            if (empty($companyInfo) || empty($companyInfo->getCompanyType())) {
                $companyInfo = (new CompanyInfo())
                    ->setCompanyType('Company Type')
                    ->setRegistrationType('not_registered')
                    ->setFunction('OWNER')
                    ->setCommercialSector('OTHER');
                $customer->setCompanyInfo($companyInfo);

            }
        }

        $this->setAddresses($customer, $checkoutData);

        try {
            if ($customer->getId()) {
                $this->getUnzerObject()->updateCustomer($customer);
            } else {
                $customer = $this->getUnzerObject()->createCustomer($customer);
            }
        } catch (Exception $e) {
            self::$warnings[] = 'Exception in getCustomer: ' . $e->getMessage();
        }

        return $customer;
    }


    protected function setAddresses(Customer $customer, array $checkoutData): void
    {
        $billing = $checkoutData['billingAddress'] ?? [];
        $shipping = $checkoutData['shippingAddress'] ?? $billing;
        $shippingType = ShippingTypes::EQUALS_BILLING;

        if (isset($billing['id'], $shipping['id']) && $billing['id'] !== $shipping['id']) {
            $shippingType = ShippingTypes::DIFFERENT_ADDRESS;
        }

        $billingAddress = (new Address())
            ->setName(trim(($billing['name2'] ?? '') . ' ' . ($billing['name3'] ?? '')))
            ->setStreet(trim(($billing['address1'] ?? '') . ' ' . ($billing['address2'] ?? '')))
            ->setZip($billing['postalCode'] ?? '')
            ->setCity($billing['town'] ?? '')
            ->setCountry($checkoutData['billingCountry']['isoCode2'] ?? '');

        $shippingAddress = (new Address())
            ->setName(trim(($shipping['name2'] ?? '') . ' ' . ($shipping['name3'] ?? '')))
            ->setStreet(trim(($shipping['address1'] ?? '') . ' ' . ($shipping['address2'] ?? '')))
            ->setZip($shipping['postalCode'] ?? '')
            ->setCity($shipping['town'] ?? '')
            ->setCountry($checkoutData['shippingCountry']['isoCode2'] ?? '')
            ->setShippingType($shippingType);

        $customer->setBillingAddress($billingAddress)
            ->setShippingAddress($shippingAddress);
    }


    public function getBasket(array $checkoutData, string $orderReference): Basket
    {
        $basketData = $checkoutData['basket'] ?? [];
        $isNet = (bool)$basketData['isNet'];
        $totalValue = $isNet ? ($basketData['basketAmountNet'] ?? 0) : ($basketData['basketAmount'] ?? 0);
        $basket = (new Basket())
            ->setTotalValueGross((float)$totalValue)
            ->setOrderId($orderReference)
            ->setCurrencyCode($basketData['currency'] ?? '');

        $this->addBasketItems( $basket, $checkoutData, $isNet);

        return $this->getUnzerObject()->createBasket($basket);
    }


    public function refund(string $paymentId, float $amount): array
    {
        try {
            $cancellations = $this->getUnzerObject()->cancelPayment($paymentId, $amount);
        } catch (Exception $e) {
            static::$warnings[] = 'Cancellation first attempt failed: ' . $e->getMessage();
            try {
                $cancellation = new Cancellation($amount);
                $createdCancellation = $this->getUnzerObject()->cancelChargedPayment($paymentId, $cancellation);
                $cancellations = [$createdCancellation];
            } catch (Exception $e) {
                static::$warnings[] = 'Cancellation second attempt failed: ' . $e->getMessage();
                try {
                    $cancellation = new Cancellation($amount);
                    $createdCancellation = $this->getUnzerObject()->cancelAuthorizedPayment($paymentId, $cancellation);
                    $cancellations = [$createdCancellation];
                } catch (Exception $e) {
                    static::$warnings[] = 'Cancellation third attempt failed: ' . $e->getMessage();
                }
            }

        }
        return $this->normalizeCancellationArray($cancellations ?? []);
    }

    protected function normalizeCancellationArray(array $cancellations): array
    {
        $normalized = [];
        /** @var Cancellation $cancellation */
        foreach ($cancellations as $cancellation) {
            $data = $cancellation->expose();
            $data['id'] = $cancellation->getId();
            $data['amount'] = $cancellation->getAmount();
            $data['success'] = $cancellation->isSuccess();
            $data['error'] = $cancellation->isError();
            $data['pending'] = $cancellation->isPending();
            try {
                $data['parentId'] = $cancellation->getParentResource()->getId();
                $data['parentIsCharge'] = $cancellation->getParentResource() instanceof Charge;
            } catch (Exception $e) {
                self::$warnings[] = '$cancellation->getParentResource() failed: ' . $e->getMessage();
            }
            $normalized[] = $data;
        }
        return $normalized;
    }

    public function charge(?string $paymentId, float $amount): Charge
    {
        return $this->getUnzerObject()->performChargeOnPayment($paymentId, new Charge($amount));
    }

    public static function getOption(array $options, int $typeId): ?string
    {
        foreach ($options as $option) {
            if ((int)$option['typeId'] === $typeId) {
                return $option['value'];
            }
        }
        return null;
    }

    public function getPayPage(string $payPageId): array
    {
        $payPage = $this->getUnzerObject()->fetchPaypageV2($payPageId);
        $payment = null;
        if (!empty($payPage->getPayments()[0])) {
            $payment = $payPage->getPayments()[0];
        }
        return $payPage->expose() + [
                'payment' => $payment?->expose(),
                'paymentId' => $payment?->getPaymentId(),
                'paymentCount' => is_array($payPage->getPayments()) ? count($payPage->getPayments()) : 0,
            ];
    }

    public function getAvailablePaymentTypes(): array
    {
        $response = $this->getUnzerObject()->fetchKeypair(true);
        $paymentTypes = $response->getAvailablePaymentTypes();
        $result = [];
        foreach ($paymentTypes as $paymentType) {
            $result[] = [
                'type' => $paymentType?->type ?? '',
                'allowCustomerTypes' => $paymentType?->allowCustomerTypes ?? null,
                'currency' => $paymentType?->supports[0]?->currency ?? null,
            ];
        }
        return $result;
    }

    private function addBasketItems($basket, $checkoutData, bool $isNet):void
    {
        $basketItems = [];
        foreach ($checkoutData['basketItems'] as $itemData) {
            $itemPrice = $isNet ? ($itemData['priceNet'] ?? 0) : ($itemData['price'] ?? 0);
            $itemVatAbs = $isNet ? 0 : ($itemData['vat'] ?? 0);
            $itemPriceNet = $itemPrice - $itemVatAbs;
            $itemVat = ($itemPriceNet > 0) ? ($itemVatAbs / $itemPriceNet) * 100 : 0;
            $name = $itemData['name'] ?? $itemData['variation_data']['data']['texts']['name1'] ?? $itemData['variationId'] . '_' . uniqid();
            $item = (new BasketItem())
                ->setTitle($name)
                ->setQuantity((int)($itemData['quantity'] ?? 1))
                ->setType($itemPrice > 0 ? BasketItemTypes::GOODS : BasketItemTypes::VOUCHER)
                ->setAmountPerUnitGross(round(abs((float)$itemPrice), 2))
                ->setVat(round((float)$itemVat, 2));
            $basketItems[] = $item;
        }

        // Process shipping costs if present.
        if (!empty($basketData['shippingAmount']) && (float)$basketData['shippingAmount'] > 0) {
            $shippingAmount = round((float)$basketData['shippingAmount'], 2);
            $shippingAmountNet = round((float)($basketData['shippingAmountNet'] ?? 0), 2);
            $shippingVatAbs = $shippingAmount - $shippingAmountNet;
            $shippingVat = ($shippingAmountNet > 0) ? ($shippingVatAbs / $shippingAmountNet) * 100 : 0;

            if ($isNet) {
                $shippingVat = 0;
                $shippingAmount = $shippingAmountNet;
            }

            $shippingItem = (new BasketItem())
                ->setTitle('Shipping')
                ->setQuantity(1)
                ->setType(BasketItemTypes::SHIPMENT)
                ->setAmountPerUnitGross($shippingAmount)
                ->setVat($shippingVat);
            $basketItems[] = $shippingItem;
        }

        $totalLeft = $basket->getTotalValueGross();
        foreach ($basketItems as $basketItem) {
            $totalLeft -= $basketItem->getAmountPerUnitGross() * $basketItem->getQuantity();
            $totalLeft += $basketItem->getAmountDiscountPerUnitGross() * $basketItem->getQuantity();
        }

        if (number_format($totalLeft, 2) !== '0.00') {
            if ($totalLeft < 0) {
                $adjustmentItem = (new BasketItem())
                    ->setTitle('---')
                    ->setQuantity(1)
                    ->setType(BasketItemTypes::VOUCHER)
                    ->setAmountDiscountPerUnitGross(round(abs($totalLeft), 2))
                    ->setVat(0);
            } else {
                $adjustmentItem = (new BasketItem())
                    ->setTitle('---')
                    ->setQuantity(1)
                    ->setType(BasketItemTypes::GOODS)
                    ->setAmountPerUnitGross(round($totalLeft, 2));
            }
            $basketItems[] = $adjustmentItem;
        }
        $basket->setBasketItems($basketItems);
    }

}
