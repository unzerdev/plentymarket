<?php

namespace UnzerPayment\Services;

use Exception;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use UnzerPayment\Models\Transaction;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Traits\LoggingTrait;

class OrderService
{
    use LoggingTrait;

    public function syncPaymentInformation(int $orderId, string $unzerPaymentId, $comment = ''){
        $this->log(__CLASS__, __METHOD__, 'start', '', [$orderId, $unzerPaymentId]);
        $order = $this->getOrder($orderId);
        if(empty($order)){
            $this->log(__CLASS__, __METHOD__, 'error', 'order not found', ['orderId' => $orderId]);
            return false;
        }

        $apiService = pluginApp(ApiService::class);
        $payment = $apiService->getUnzerPayment($unzerPaymentId);
        if(empty($payment)){
            $this->log(__CLASS__, __METHOD__, 'error', 'payment not found', ['unzerPaymentId' => $unzerPaymentId]);
            return false;
        }

        $transactionService = pluginApp(TransactionService::class);
        $transactionRepository = pluginApp(TransactionRepository::class);
        $transaction = $transactionRepository->getTransactionByUnzerPaymentId($unzerPaymentId);

        if(empty($transaction)){
            $transaction = pluginApp(TransactionService::class);
            $transaction->unzerPaymentId = $unzerPaymentId;
        }
        $transaction->orderId = $orderId;
        $transactionService->upsertTransaction($transaction);

        $paymentMethodService = pluginApp(PaymentMethodService::class);
        $unzerPaymentMethodId = $paymentMethodService->getPaymentMethodId();
        $paymentRepository = pluginApp(PaymentRepositoryContract::class);

        $existingPayments = $paymentRepository->getPaymentsByOrderId($orderId);
        $doesPaymentObjectExist = false;
        $doesBookedPaymentObjectExist = false;

        /** @var Payment $existingPayment */
        foreach ($existingPayments as $existingPayment) {
            if($existingPayment->mopId == $unzerPaymentMethodId){
                if($existingPayment->transactionType == Payment::TRANSACTION_TYPE_BOOKED_POSTING){
                    $doesBookedPaymentObjectExist = true;
                }
                if($existingPayment->transactionType == Payment::TRANSACTION_TYPE_PROVISIONAL_POSTING){
                    $doesPaymentObjectExist = true;
                }
            }
        }

        if(!$doesPaymentObjectExist) {
            $paymentObject = $this->createPaymentObject(
                $payment['amount']['total'],
                Payment::STATUS_APPROVED,
                $unzerPaymentId,
                $comment,
                null,
                Payment::PAYMENT_TYPE_CREDIT,
                Payment::TRANSACTION_TYPE_PROVISIONAL_POSTING,
                $payment['amount']['currency']
            );
            $this->assignPlentyPaymentToPlentyOrder($paymentObject, $order);
        }

        if(!$doesBookedPaymentObjectExist && $payment['state'] === 'completed') {
            $paymentObject = $this->createPaymentObject(
                $payment['amount']['charged'],
                Payment::STATUS_CAPTURED,
                $unzerPaymentId,
                $comment,
                null,
                Payment::PAYMENT_TYPE_CREDIT,
                Payment::TRANSACTION_TYPE_BOOKED_POSTING,
                $payment['amount']['currency']
            );
            $this->assignPlentyPaymentToPlentyOrder($paymentObject, $order);
            $transaction->paymentId = $paymentObject->id;
            $transactionService->upsertTransaction($transaction);
        }
    }



    public function createPaymentObject($amount, $status, $transactionId, $comment = '', $dateTime = null, $type = Payment::PAYMENT_TYPE_CREDIT, $transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING, $currency = 'EUR'): Payment
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', [$amount, $status, $transactionId, $comment, $dateTime, $type, $transactionType, $currency]);
        if ($dateTime === null) {
            $dateTime = date('Y-m-d H:i:s');
        }
        $paymentMethodService = pluginApp(PaymentMethodService::class);
        $paymentRepository = pluginApp(PaymentRepositoryContract::class);
        $payment = pluginApp(Payment::class);

        $payment->mopId = $paymentMethodService->getPaymentMethodId();
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
        $paymentProperties[] = $this->createPaymentProperty(PaymentProperty::TYPE_BOOKING_TEXT, $transactionId . ' ' . $comment.' '.date('Y-m-d H:i:s'));
        $paymentProperties[] = $this->createPaymentProperty(PaymentProperty::TYPE_TRANSACTION_ID, (string)$transactionId);


        $payment->properties = $paymentProperties;
        $this->log(__CLASS__, __METHOD__, 'beforeCreate', '', [$payment]);
        try{
            $payment = $paymentRepository->createPayment($payment);
            $this->log(__CLASS__, __METHOD__, 'result', '', [$payment]);
        }catch (Exception $e) {
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

    /**
     * @param Payment $payment
     * @param Order $order
     *
     * @return bool
     */
    public function assignPlentyPaymentToPlentyOrder(Payment $payment, Order $order): bool
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', ['order' => $order, 'payment' => $payment]);

        try {
            $authHelper = pluginApp(AuthHelper::class);
            $paymentOrderRelationRepository = pluginApp(PaymentOrderRelationRepositoryContract::class);

            $return = $authHelper->processUnguarded(
                function () use ($paymentOrderRelationRepository, $payment, $order) {
                    return $paymentOrderRelationRepository->createOrderRelation($payment, $order);
                }
            );
            $this->log(__CLASS__, __METHOD__, 'success', '', [$return]);

        } catch (Exception $e) {
            $this->log(__CLASS__, __METHOD__, 'error', 'assign payment to order failed', [$e, $e->getMessage()], true);
            return false;
        }

        return true;
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


//
//    public function setOrderStatusAuthorized($orderId)
//    {
//        /** @var OrderRepositoryContract $orderRepository */
//        $orderRepository = pluginApp(OrderRepositoryContract::class);
//
//        if ($order = $this->getOrder($orderId)) {
//            if ((float)$order->statusId > 3.001) {
//                return;
//            }
//        }
//
//        /** @var ConfigHelper $configHelper */
//        $configHelper = pluginApp(ConfigHelper::class);
//        $newOrderStatus = $configHelper->getAuthorizedStatus();
//        if ($newOrderStatus === '4/5') {
//            try {
//                $this->log(__CLASS__, __METHOD__, 'auth_status_45', 'start intelligent stock', ['order' => $orderId]);
//
//                /** @var AuthHelper $authHelper */
//                $authHelper = pluginApp(AuthHelper::class);
//                $authHelper->processUnguarded(
//                    function () use ($orderRepository, $orderId) {
//                        return $orderRepository->setOrderStatus45((int)$orderId);
//                    }
//                );
//            } catch (\Exception $e) {
//                $this->log(__CLASS__, __METHOD__, 'auth_status_45_failed', 'set intelligent stock order status failed', [$e, $e->getMessage()], true);
//            }
//        } else {
//            $this->setOrderStatus($orderId, $newOrderStatus);
//        }
//    }
//
//    public function setOrderStatus($orderId, $status)
//    {
//        $this->log(__CLASS__, __METHOD__, 'start', 'try to set order status', ['order' => $orderId, 'status' => $status]);
//        if (!empty($status)) {
//            $order = ['statusId' => (float)$status];
//            $response = '';
//            try {
//                /** @var OrderRepositoryContract $orderRepository */
//                $orderRepository = pluginApp(OrderRepositoryContract::class);
//                /** @var AuthHelper $authHelper */
//                $authHelper = pluginApp(AuthHelper::class);
//                $response = $authHelper->processUnguarded(
//                    function () use ($orderRepository, $order, $orderId) {
//                        return $orderRepository->updateOrder($order, (int)$orderId);
//                    }
//                );
//            } catch (\Exception $e) {
//                $this->log(__CLASS__, __METHOD__, 'failed', 'set order status failed', [$e, $e->getMessage()], true);
//            }
//            $this->log(__CLASS__, __METHOD__, 'done', 'finished set order status', ['order' => $response, 'status' => $status]);
//        } else {
//            $this->log(__CLASS__, __METHOD__, 'empty_status', 'set order status cancelled because of empty status', null);
//        }
//
//    }
//
//    public function getShippingAddressId(Order $order)
//    {
//        /** @var AuthHelper $authHelper */
//        $authHelper = pluginApp(AuthHelper::class);
//        return $authHelper->processUnguarded(function () use ($order) {
//            $this->log(__CLASS__, __METHOD__, 'addressRelations ', '', [$order->addressRelations]);
//            $shippingAddressId = null;
//            $billingAddressId = null;
//
//            foreach ($order->addressRelations as $addressRelation) {
//                $this->log(__CLASS__, __METHOD__, 'addressRelation ', '', [$addressRelation, AddressRelationType::BILLING_ADDRESS, AddressRelationType::DELIVERY_ADDRESS]);
//                if ($addressRelation->typeId == AddressRelationType::BILLING_ADDRESS) {
//                    $billingAddressId = $addressRelation->addressId;
//                } elseif ($addressRelation->typeId == AddressRelationType::DELIVERY_ADDRESS) {
//                    $shippingAddressId = $addressRelation->addressId;
//                }
//            }
//            $this->log(__CLASS__, __METHOD__, 'addressRelationResult ', '', [$shippingAddressId, $billingAddressId]);
//            return $shippingAddressId ?: $billingAddressId;
//        });
//    }

}