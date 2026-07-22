<?php

namespace UnzerPayment\Services;

use Exception;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Models\OrderItem;
use Plenty\Modules\Order\Models\OrderItemType;
use Plenty\Modules\Order\Property\Contracts\OrderPropertyRepositoryContract;
use Plenty\Modules\Order\Property\Models\OrderProperty;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use UnzerPayment\Models\Transaction;
use UnzerPayment\Models\UnzerData;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Repositories\UnzerDataRepository;
use UnzerPayment\Traits\LoggingTrait;

class OrderService
{
    use LoggingTrait;

    private const PAYMENT_DEDUPLICATION_KEY_PREFIX = 'paymentDeduplication-';
    private const PAYMENT_DEDUPLICATION_KEY_MAX_AGE_SECONDS = 3600;

    public function syncPaymentInformation(int $orderId, string $unzerPaymentId, $comment = ''): bool
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', [$orderId, $unzerPaymentId]);
        $order = $this->getOrder($orderId);
        if (empty($order)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'order not found', ['orderId' => $orderId]);
            return false;
        }

        $apiService = pluginApp(ApiService::class);
        $payment = $apiService->getUnzerPayment($unzerPaymentId);
        if (empty($payment)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'payment not found', ['unzerPaymentId' => $unzerPaymentId]);
            return false;
        }

        $transactionService = pluginApp(TransactionService::class);
        $transactionRepository = pluginApp(TransactionRepository::class);
        $transaction = $transactionRepository->getTransactionByUnzerPaymentId($unzerPaymentId);

        if (empty($transaction)) {
            $transaction = pluginApp(Transaction::class);
            $transaction->unzerPaymentId = $unzerPaymentId;
        }
        $transaction->orderId = $orderId;
        $transactionService->upsertTransaction($transaction);

        $paymentRepository = pluginApp(PaymentRepositoryContract::class);
        $existingPayments = (array)$paymentRepository->getPaymentsByOrderId($orderId);

        if (!$this->getPaymentObjectByTransactionId($existingPayments, $unzerPaymentId, false)) {
            //provisional posting for auth
            $paymentObject = $this->createPaymentObject(
                $payment['amount']['total'],
                Payment::STATUS_APPROVED,
                $unzerPaymentId,
                $order->methodOfPaymentId,
                $comment,
                null,
                Payment::PAYMENT_TYPE_CREDIT,
                Payment::TRANSACTION_TYPE_PROVISIONAL_POSTING,
                $payment['amount']['currency']
            );
            $this->assignPlentyPaymentToPlentyOrder($paymentObject, $order);
        }

        foreach ($payment['charges'] as $charge) {
            if (!$charge['isSuccess']) {
                continue;
            }
            $transactionId = $unzerPaymentId . '--' . $charge['id'];
            if (!$this->getPaymentObjectByTransactionId($existingPayments, $transactionId)) {
                $paymentObject = $this->createPaymentObject(
                    $charge['amount'],
                    Payment::STATUS_CAPTURED,
                    $transactionId,
                    $order->methodOfPaymentId,
                    $comment,
                    null,
                    Payment::PAYMENT_TYPE_CREDIT,
                    Payment::TRANSACTION_TYPE_BOOKED_POSTING,
                    $charge['currency'] ?? $payment['amount']['currency']
                );
                $this->assignPlentyPaymentToPlentyOrder($paymentObject, $order);
                $transaction->paymentId = $paymentObject->id;
                $transactionService->upsertTransaction($transaction);
            }
        }

        if (empty($payment['charges']) && !empty($payment['authorization']) && $payment['authorization']['isSuccess']) {
            $this->log(__CLASS__, __METHOD__, 'authorized', '', ['$payment' => $payment]);
            $this->setOrderStatusAuthorized($orderId);
        } else {
            $this->log(__CLASS__, __METHOD__, 'notAuthorized', '', ['$payment' => $payment]);
        }


        return true;
    }

    public function getPaymentObjectByTransactionId(array $paymentObjectArray, string $transactionId, bool $isBooked = true): ?Payment
    {
        $this->log(__CLASS__, __METHOD__, 'start', 'searching for payment by transaction ID', [
            'transactionId' => $transactionId,
            'isBooked' => $isBooked,
            'paymentCount' => count($paymentObjectArray),
        ]);

        /** @var Payment $paymentObject */
        foreach ($paymentObjectArray as $paymentObject) {
            $paymentObjectIsBooked = (int)$paymentObject->transactionType === Payment::TRANSACTION_TYPE_BOOKED_POSTING;
            if ($isBooked !== $paymentObjectIsBooked) {
                continue;
            }
            /** @var PaymentProperty $property */
            foreach ($paymentObject->properties as $property) {
                if ($property->typeId === PaymentProperty::TYPE_TRANSACTION_ID && $property->value === $transactionId) {
                    $this->log(__CLASS__, __METHOD__, 'found', 'payment found with matching transaction ID', [
                        'paymentId' => $paymentObject->id,
                        'transactionId' => $transactionId,
                    ]);
                    return $paymentObject;
                }
            }
        }

        $this->log(__CLASS__, __METHOD__, 'notFound', 'no payment found with matching transaction ID', [
            'transactionId' => $transactionId,
            'isBooked' => $isBooked,
        ]);
        return null;
    }

    public function setOrderStatusAuthorized($orderId): void
    {
        /** @var OrderRepositoryContract $orderRepository */
        $orderRepository = pluginApp(OrderRepositoryContract::class);

        if ($order = $this->getOrder($orderId)) {
            if ((float)$order->statusId > 3.001) {
                return;
            }
        }
        try {
            $this->log(__CLASS__, __METHOD__, '', '', ['order' => $orderId]);

            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);
            $authHelper->processUnguarded(
                function () use ($orderRepository, $orderId) {
                    return $orderRepository->setOrderStatus45((int)$orderId);
                }
            );
        } catch (\Exception $e) {
            $this->error(__CLASS__, __METHOD__, 'failed', '', [$orderId, $e, $e->getMessage()]);
        }
    }

    public function setOrderProperty(int $orderId, int $propertyType, $propertyValue)
    {
        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);

        /** @var OrderPropertyRepositoryContract $orderPropertyRepository */
        $orderPropertyRepository = pluginApp(OrderPropertyRepositoryContract::class);
        $authHelper->processUnguarded(
            function () use ($orderPropertyRepository, $orderId, $propertyType, $propertyValue) {
                try {
                    $orderProperty = $orderPropertyRepository->create([
                        'orderId' => $orderId,
                        'typeId' => $propertyType,
                        'value' => $propertyValue,
                    ]);
                    $this->log(__CLASS__, __METHOD__, 'setOrderPropertySuccess', '', [$orderProperty]);
                } catch (\Exception $e) {
                    $this->log(__CLASS__, __METHOD__, 'setOrderPropertyError', '', [$e->getCode(), $e->getMessage(), $e->getLine()], true);
                }

            });
    }

    public function setOrderExternalId(int $orderId, string $externalId)
    {
        /** @var AuthHelper $authHelper */
        $authHelper = pluginApp(AuthHelper::class);

        /** @var OrderPropertyRepositoryContract $orderPropertyRepository */
        $orderPropertyRepository = pluginApp(OrderPropertyRepositoryContract::class);
        $loggable = $this;
        $authHelper->processUnguarded(
            function () use ($orderPropertyRepository, $orderId, $externalId, $loggable) {
                try {
                    /** @var OrderProperty $existing */
                    $existing = $orderPropertyRepository->findByOrderId($orderId, OrderPropertyType::EXTERNAL_ORDER_ID);
                    $existingArray = $existing->toArray();
                    if (!empty($existingArray)) {
                        $loggable->log(__CLASS__, __METHOD__, 'existing', '', [$existingArray]);
                        return;
                    }
                    $orderProperty = $orderPropertyRepository->create([
                        'orderId' => $orderId,
                        'typeId' => OrderPropertyType::EXTERNAL_ORDER_ID,
                        'value' => $externalId,
                    ]);
                    $loggable->log(__CLASS__, __METHOD__, 'success', '', [$orderProperty]);
                } catch (\Exception $e) {
                    $loggable->log(__CLASS__, __METHOD__, 'error', '', [$e->getCode(), $e->getMessage(), $e->getLine()], true);
                }

            });
    }

    public function getOrderExternalId(Order $order)
    {
        $orderProperties = $order->properties;
        /** @var OrderProperty $property */
        foreach ($orderProperties as $property) {
            if ($property->typeId === OrderPropertyType::EXTERNAL_ORDER_ID) {
                return $property->value;
            }
        }
        return null;
    }

    public function createPaymentObject(
        $amount,
        $status,
        $transactionId,
        $paymentMethodId,
        $comment = '',
        $dateTime = null,
        $type = Payment::PAYMENT_TYPE_CREDIT,
        $transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING,
        $currency = 'EUR'
    ): Payment
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', [$amount, $status, $transactionId, $comment, $dateTime, $type, $transactionType, $currency]);
        if ($dateTime === null) {
            $dateTime = date('Y-m-d H:i:s');
        }

        $paymentRepository = pluginApp(PaymentRepositoryContract::class);
        $payment = pluginApp(Payment::class);

        $payment->mopId = $paymentMethodId;
        $payment->transactionType = $transactionType;
        $payment->type = $type;
        $payment->status = $status;
        $payment->currency = $currency;
        $payment->isSystemCurrency = ($currency === 'EUR');
        $payment->amount = $amount;
        $payment->receivedAt = $dateTime;
        if ($status != Payment::STATUS_CAPTURED && $status != Payment::STATUS_REFUNDED) {
            $payment->unaccountable = 1;
        } else {
            $payment->unaccountable = 0;
        }

        $paymentProperties = [];
        $paymentProperties[] = $this->createPaymentProperty(PaymentProperty::TYPE_BOOKING_TEXT, $transactionId . ' ' . $comment . ' ' . date('Y-m-d H:i:s'));
        $paymentProperties[] = $this->createPaymentProperty(PaymentProperty::TYPE_TRANSACTION_ID, (string)$transactionId);
        $paymentProperties[] = $this->createPaymentProperty(PaymentProperty::TYPE_REFERENCE_ID, (string)$transactionId);


        $payment->properties = $paymentProperties;
        $this->log(__CLASS__, __METHOD__, 'beforeCreate', '', [$payment]);
        try {
            $payment = $paymentRepository->createPayment($payment);
            $this->log(__CLASS__, __METHOD__, 'result', '', [$payment]);
        } catch (Exception $e) {
            $this->error(__CLASS__, __METHOD__, 'error', 'create payment failed', [$e, $e->getMessage()]);
        }
        return $payment;
    }

    /**
     * @param int $typeId
     * @param string $value
     *
     * @return PaymentProperty
     */
    private function createPaymentProperty(int $typeId, string $value): PaymentProperty
    {
        $paymentProperty = pluginApp(PaymentProperty::class);
        $paymentProperty->typeId = $typeId;
        $paymentProperty->value = $value;

        return $paymentProperty;
    }

    private function getPaymentProperty(Payment $payment, int $typeId): ?PaymentProperty
    {
        foreach ($payment->properties as $property) {
            if ($property->typeId === $typeId) {
                return $property;
            }
        }
        return null;
    }

    /**
     * @param Payment $payment
     * @param Order $order
     *
     * @return bool
     */
    public function assignPlentyPaymentToPlentyOrder(Payment $payment, Order $order): bool
    {
        $deduplicateKeys = [];
        $currentDeduplicateKey = null;
        $time = time();
        try {
            $paymentTransactionProperty = $this->getPaymentProperty($payment, PaymentProperty::TYPE_TRANSACTION_ID);
            if (!empty($paymentTransactionProperty)) {
                $deduplicateKeyBase = self::PAYMENT_DEDUPLICATION_KEY_PREFIX . $payment->transactionType . '-' . $payment->type . '-' . $paymentTransactionProperty->value;
                $currentDeduplicateKey = $deduplicateKeyBase . '-' . $time;
                // block everything +/- 2 seconds
                foreach ([-2, -1, 0, 1, 2] as $timeOffset) {
                    $deduplicateKeys[] = $deduplicateKeyBase . '-' . ($time + $timeOffset);

                }
            }
        } catch (\Throwable $exception) {
            $this->error(__CLASS__, __METHOD__, 'error_deduplicate_key', $exception->getMessage());
        }

        $this->log(__CLASS__, __METHOD__, 'start', '', ['order' => $order, 'payment' => $payment]);

        try {
            $existingUnzerDataEntries = [];
            if (!empty($deduplicateKeys)) {
                $database = pluginApp(DataBase::class);
                $existingUnzerDataEntries = $database->query(UnzerData::class)
                    ->whereIn('dataKey', $deduplicateKeys)
                    ->get();
            }
            if (empty($existingUnzerDataEntries)) {
                if (!empty($currentDeduplicateKey)) {
                    $this->persistDeduplicateKey($currentDeduplicateKey, $time);
                }
                $authHelper = pluginApp(AuthHelper::class);
                $paymentOrderRelationRepository = pluginApp(PaymentOrderRelationRepositoryContract::class);
                $return = $authHelper->processUnguarded(
                    function () use ($paymentOrderRelationRepository, $payment, $order) {
                        return $paymentOrderRelationRepository->createOrderRelation($payment, $order);
                    }
                );
                $this->log(__CLASS__, __METHOD__, 'success', '', [$return]);
            } else {
                $this->log(__CLASS__, __METHOD__, 'deduplicate', 'Prevented a duplicate payment entry', [
                    'payment' => $payment,
                    'order' => $order,
                    'deduplicateKeys' => $deduplicateKeys,
                ]);
            }

        } catch (Exception $e) {
            $this->log(__CLASS__, __METHOD__, 'error', 'assign payment to order failed', [$e, $e->getMessage()], true);
            return false;
        }

        return true;
    }

    private function persistDeduplicateKey(string $deduplicateKey, int $time): void
    {
        try {
            $unzerDataRepository = pluginApp(UnzerDataRepository::class);
            $unzerDataRepository->create([
                'dataKey' => $deduplicateKey,
                'dataValue' => ['value' => $time],
            ]);
        } catch (\Throwable $exception) {
            $this->error(__CLASS__, __METHOD__, 'error_deduplicate_key_persist', $exception->getMessage());
        }
    }

    public function cleanUpDeduplicateKeys(int $now): void
    {
        try {
            $database = pluginApp(DataBase::class);
            $deduplicateKeyEntries = $database->query(UnzerData::class)
                ->where('dataKey', 'like', self::PAYMENT_DEDUPLICATION_KEY_PREFIX . '%')
                ->get();
            $unzerDataRepository = pluginApp(UnzerDataRepository::class);
            /** @var UnzerData $deduplicateKeyEntry */
            foreach ($deduplicateKeyEntries as $deduplicateKeyEntry) {
                $createdAt = (int)($deduplicateKeyEntry->dataValue['value'] ?? 0);
                $this->log(__CLASS__, __METHOD__, 'dedup created at', '',[
                    'createdAt' => $createdAt,
                    'threshold' => $now - self::PAYMENT_DEDUPLICATION_KEY_MAX_AGE_SECONDS
                ]);
                if ($createdAt < $now - self::PAYMENT_DEDUPLICATION_KEY_MAX_AGE_SECONDS) {
                    $unzerDataRepository->delete($deduplicateKeyEntry);
                }
            }
        } catch (\Throwable $exception) {
            $this->error(__CLASS__, __METHOD__, 'error_deduplicate_key_cleanup', $exception->getMessage());
        }
    }

    /**
     * @param int $orderId
     *
     * @return ?Order
     */
    public function getOrder(int $orderId)
    {
        $authHelper = pluginApp(AuthHelper::class);
        $orderRepository = pluginApp(OrderRepositoryContract::class);

        return $authHelper->processUnguarded(
            function () use ($orderRepository, $orderId) {
                return $orderRepository->findOrderById($orderId);
            }
        );
    }

    public function getOrderAmountObjectByCurrency(Order $order, ?string $currency = null)
    {
        if (count($order->amounts) === 1) {
            return $order->amounts[0];
        }
        foreach ($order->amounts as $amount) {
            if ($amount->currency === $currency) {
                return $amount;
            }
        }
        // try our luck
        return $order->amounts[0];
    }

    public function getShippingItemObject(Order $order): ?OrderItem
    {
        /** @var OrderItem $orderItem */
        foreach ($order->orderItems as $orderItem) {
            if ($orderItem->typeId == OrderItemType::TYPE_SHIPPING_COSTS) {
                return $orderItem;
            }
        }
        return null;
    }

    public function getRegularItemObjects(Order $order): array
    {
        $return = [];
        /** @var OrderItem $orderItem */
        foreach ($order->orderItems as $orderItem) {
            if ($orderItem->typeId != OrderItemType::TYPE_SHIPPING_COSTS) {
                $return[] = $orderItem;
            }
        }
        return $return;
    }

    public function getOrderRelationValue($order, string $relationType)
    {
        $relations = $order->relations ?? [];
        $this->log(__CLASS__, __METHOD__, 'allRelations', '', ['$relations' => $relations, '$order' => $order]);
        foreach ($relations as $relation) {
            if (!is_object($relation)) {
                continue;
            }
            $this->log(__CLASS__, __METHOD__, 'relation', '', ['$relation' => $relation]);
            if ($relation->relation === $relationType) {
                return $relation->referenceId;
            }
        }
        return null;
    }
}