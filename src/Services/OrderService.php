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
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Traits\LoggingTrait;

class OrderService
{
    use LoggingTrait;

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
            $transaction = pluginApp(TransactionService::class);
            $transaction->unzerPaymentId = $unzerPaymentId;
        }
        $transaction->orderId = $orderId;
        $transactionService->upsertTransaction($transaction);

        $paymentMethodService = pluginApp(PaymentMethodService::class);

        $paymentRepository = pluginApp(PaymentRepositoryContract::class);

        $existingPayments = $paymentRepository->getPaymentsByOrderId($orderId);
        $doesPaymentObjectExist = false;
        $doesBookedPaymentObjectExist = false;

        /** @var Payment $existingPayment */
        foreach ($existingPayments as $existingPayment) {
            if ($paymentMethodService->isUnzerPaymentMethod((int)$existingPayment->mopId)) {
                if ($existingPayment->transactionType == Payment::TRANSACTION_TYPE_BOOKED_POSTING) {
                    $doesBookedPaymentObjectExist = true;
                }
                if ($existingPayment->transactionType == Payment::TRANSACTION_TYPE_PROVISIONAL_POSTING) {
                    $doesPaymentObjectExist = true;
                }
            }
        }

        if (!$doesPaymentObjectExist) {
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

        if (!$doesBookedPaymentObjectExist && $payment['state'] === 'completed') {
            $paymentObject = $this->createPaymentObject(
                $payment['amount']['charged'],
                Payment::STATUS_CAPTURED,
                $unzerPaymentId,
                $order->methodOfPaymentId,
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
        return true;
    }


    public function createPaymentObject($amount, $status, $transactionId, $paymentMethodId, $comment = '', $dateTime = null, $type = Payment::PAYMENT_TYPE_CREDIT, $transactionType = Payment::TRANSACTION_TYPE_BOOKED_POSTING, $currency = 'EUR'): Payment
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
}