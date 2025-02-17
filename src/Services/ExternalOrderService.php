<?php

namespace UnzerPayment\Services;


use Plenty\Modules\Account\Address\Models\AddressOption;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Models\OrderType;
use Plenty\Modules\Order\Property\Models\OrderProperty;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use UnzerPayment\Constants\Constants;
use UnzerPayment\Models\Transaction;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Traits\LoggingTrait;

class ExternalOrderService
{
    use LoggingTrait;

    protected ConfigService $configService;
    private TransactionRepository $transactionRepository;
    private ApiService $apiService;

    public function __construct(ConfigService $configService, TransactionRepository $transactionRepository, ApiService $apiService)
    {
        $this->configService = $configService;
        $this->transactionRepository = $transactionRepository;
        $this->apiService = $apiService;
    }

    public function process($maxTimeBack = 86400, $maxStatusId = 5): void
    {
        if (!$this->configService->isExternalOrderMatchingActive()) {
            $this->log(__CLASS__, __METHOD__, 'off', '', ['configValue' => $this->configService->getConfigurationValue('useExternalOrderMatching')]);
            return;
        }
        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);
        $authHelper->processUnguarded(function () use ($maxTimeBack, $maxStatusId) {

            $paymentMethodService = pluginApp(PaymentMethodService::class);
            $paymentMethodId = $paymentMethodService->getPaymentMethodId();

            $orderRepository = pluginApp(OrderRepositoryContract::class);
            $orderRepository->setFilters(
                [
                    'createdAtFrom' => date('c', time() - $maxTimeBack),
                    'statusIdTo' => $maxStatusId,
                    'orderTypes' => [OrderType::TYPE_SALES_ORDER],
                    'methodOfPaymentId' => $paymentMethodId, //probably not working
                ]
            );
            $page = 1;
            while ($orderResponse = $orderRepository->searchOrders($page, 100, ['payments', 'addresses'])) {
                /** @var Order[] $orders */
                $orders = $orderResponse->getResult();
                $this->log(__CLASS__, __METHOD__, 'orders', '', [$orders]);
                foreach ($orders as $order) {
                    $orderPaymentMethodId = null;
                    foreach ($order['properties'] as $orderProperty) {
                        if ((int)$orderProperty['typeId'] === OrderPropertyType::PAYMENT_METHOD) {
                            $orderPaymentMethodId = (int)$orderProperty['value'];
                            break;
                        }
                    }
                    $this->log(__CLASS__, __METHOD__, 'orderPaymentMethodId', '', ['orderPaymentMethodId' => $orderPaymentMethodId, 'paymentMethodId' => $paymentMethodId]);
                    if ($orderPaymentMethodId === $paymentMethodId && (int)$order['typeId'] === OrderType::TYPE_SALES_ORDER) {
                        $this->processOrder($order);
                    }

                }
                if ($orderResponse->isLastPage()) {
                    break;
                }
                $page++;
            }

        });
    }

    //would need auth helper if public
    protected function processOrder($order): void
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', [$order]);

        $orderTransaction = $this->transactionRepository->getTransactionByOrderId((int)$order['id']);
        if (!empty($orderTransaction)) {
            //already matched
            return;
        }

        $unzerPaymentId = $this->getUnzerPaymentIdFromOrder($order);

        if ($unzerPaymentId) {
            $existingChargePermissionTransaction = $this->transactionRepository->getTransactionByUnzerPaymentId($unzerPaymentId);
            if ($existingChargePermissionTransaction && $existingChargePermissionTransaction->orderId) {
                $this->log(__CLASS__, __METHOD__, 'alreadyMatched', '', ['unzerPaymentId' => $unzerPaymentId, 'transaction' => $existingChargePermissionTransaction, 'order' => $order]);
                return;
            }
            $this->executeMatching((int)$order['id'], $unzerPaymentId);
        }
    }

    public function getUnzerPaymentIdFromOrder($order): ?string
    {
        $candidates = [];
        $orderAmount = $order['amounts'][0]['invoiceTotal'] - $order['amounts'][0]['giftCardAmount'];

        /** @var OrderProperty $property */
        foreach ($order['properties'] as $property) {
            $candidates[] = (string)$property['value'];
        }
        $this->log(__CLASS__, __METHOD__, 'externalMatching_stringCandidates', '', [$candidates]);
        foreach ($candidates as $candidate) {
            if ($unzerPaymentId = $this->findUnzerPaymentIdInString($candidate)) {
                $unzerPayment = $this->apiService->getUnzerPayment($unzerPaymentId);
                $unzerPaymentAmount = 0; //TODO
                if (number_format($unzerPaymentAmount, 2) === number_format($orderAmount, 2)) {
                    $this->log(__CLASS__, __METHOD__, 'stringBasedMatch', '', [
                        'unzerPayment' => $unzerPayment,
                        'order' => $order,
                        'unzerPaymentAmount' => $unzerPaymentAmount,
                        'orderAmount' => $orderAmount,
                    ]);
                    return $unzerPaymentId;
                } else {
                    $this->log(__CLASS__, __METHOD__, 'amountMismatch', '', [
                        'chargePermission' => $unzerPayment,
                        'order' => $order,
                        'unzerPaymentAmount' => $unzerPaymentAmount,
                        'orderAmount' => $orderAmount,
                    ]);
                }
            }
        }

        $transactionCandidates = $this->transactionRepository->getTransactionsByAmountAndTime($orderAmount, $order['createdAt']);

        $transactionCandidates = array_filter($transactionCandidates, function (Transaction $transaction) {
            return empty($transaction->orderId);
        });

        $orderEmailAddresses = $this->getEmailAddressesFromOrder($order);
        $this->log(__CLASS__, __METHOD__, 'hotCandidates', '', ['candidates' => $transactionCandidates, 'orderEmailAddresses' => $orderEmailAddresses]);

        /** @var Transaction[] $transactionCandidates */
        $transactionCandidates = array_values(
            array_filter($transactionCandidates, function (Transaction $transaction) use ($orderEmailAddresses, $order) {
                $unzerPayment = $this->apiService->getUnzerPayment($transaction->unzerPaymentId);
                return in_array(strtolower($unzerPayment['customer']['email']), $orderEmailAddresses); // || $this->doAddressesMatch($order, $unzerPayment);
            })
        );
        $this->log(__CLASS__, __METHOD__, 'finalCandidates', '', [$transactionCandidates]);
        if (count($transactionCandidates) !== 1) {
            $this->log(__CLASS__, __METHOD__, 'failed', '', ['candidates' => $transactionCandidates, 'order' => $order]);
            return null;
        }
        return $transactionCandidates[0]->unzerPaymentId;
    }

    protected function findUnzerPaymentIdInString(string $string){
        if (preg_match(Constants::UNZER_PAYMENT_ID_PATTERN, $string, $matches)) {
            return $matches[0];
        }
    }


//    protected function doAddressesMatch($order, $chargePermission): bool
//    {
//        $chargePermissionAddress = $chargePermission->shippingAddress;
//        $chargePermissionAddressString = $chargePermissionAddress->name . ' ' . $chargePermissionAddress->city . ' ' . $chargePermissionAddress->postalCode;
//        $orderAddress = $this->getShippingAddressArray($order);
//
//        $orderAddressString = $orderAddress['name2'] . ' ' . $orderAddress['name3'] . ' ' . $orderAddress['town'] . ' ' . $orderAddress['postalCode'];
//
//        $regex = '/[^a-z0-9 ]/i';
//        $chargePermissionAddressString = preg_replace($regex, '', $chargePermissionAddressString);
//        $orderAddressString = preg_replace($regex, '', $orderAddressString);
//
//        $chargePermissionAddressParts = explode(' ', $chargePermissionAddressString);
//        $orderAddressParts = explode(' ', $orderAddressString);
//
//        $diff1 = array_diff($chargePermissionAddressParts, $orderAddressParts);
//        $diff2 = array_diff($orderAddressParts, $chargePermissionAddressParts);
//
//        $differenceNumber = count($diff1) + count($diff2);
//        $originalNumber = count($chargePermissionAddressParts) + count($orderAddressParts);
//        $differencePercentage = $differenceNumber / $originalNumber;
//        $this->log(__CLASS__, __METHOD__, 'addressMatch', '', [
//            'chargePermissionAddress' => $chargePermissionAddress,
//            'orderAddress' => $orderAddress,
//            'chargePermissionAddressString' => $chargePermissionAddressString,
//            'orderAddressString' => $orderAddressString,
//            'diff1' => $diff1,
//            'diff2' => $diff2,
//            'differenceNumber' => $differenceNumber,
//            'originalNumber' => $originalNumber,
//            'differencePercentage' => $differencePercentage,
//        ]);
//        return $differencePercentage <= 0.25;
//    }

//    protected function getShippingAddressArray($order): ?array
//    {
//        $shippingAddress = null;
//        foreach ($order['addressRelations'] as $addressRelation) {
//            if ($addressRelation['typeId'] == AddressRelationType::DELIVERY_ADDRESS) {
//                $shippingAddressId = (int)$addressRelation['addressId'];
//                break;
//            }
//        }
//
//        if (empty($shippingAddressId)) {
//            return null;
//        }
//
//        foreach ($order['addresses'] as $address) {
//            if ((int)$address['id'] === $shippingAddressId) {
//                $shippingAddress = $address;
//                break;
//            }
//        }
//        return $shippingAddress;
//    }

    protected function getEmailAddressesFromOrder($order): array
    {
        if (empty($order['addresses'])) {
            return [];
        }
        $emailAddresses = [];
        foreach ($order['addresses'] as $address) {
            foreach ($address['options'] as $option) {
                if ((int)$option['typeId'] === (int)AddressOption::TYPE_EMAIL) {
                    $emailAddresses[] = strtolower($option['value']);
                }
            }
        }
        return $emailAddresses;
    }


    //would need auth helper if public
    protected function executeMatching(int $orderId, string $unzerPaymentId): void
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', ['orderId' => $orderId]);
        $orderService = pluginApp(OrderService::class);
        $order = $orderService->getOrder($orderId);

        if(empty($order)) {
            $this->log(__CLASS__, __METHOD__, 'orderNotFound', '', ['orderId' => $orderId]);
            return;
        }

        $this->log(__CLASS__, __METHOD__, 'data', '', [$order, $unzerPaymentId]);
        $unzerPayment = $this->apiService->getUnzerPayment($unzerPaymentId);
        $this->transactionRepository->persistUnzerPayment($unzerPayment, $orderId);

        $orderService->syncPaymentInformation($orderId, $unzerPaymentId, 'Matched from external order');

        $this->log(__CLASS__, __METHOD__, 'end');
    }

}
