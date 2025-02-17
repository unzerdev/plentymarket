<?php

namespace UnzerPayment\Procedures;


use Exception;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Order\Models\OrderType;
use Plenty\Modules\Payment\Models\Payment;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\OrderService;
use UnzerPayment\Traits\LoggingTrait;

class RefundProcedure
{
    use LoggingTrait;

    public function run(EventProceduresTriggered $eventTriggered)
    {

        try {
            /** @var Order $order */
            $procedureOrderObject = $eventTriggered->getOrder();
            $this->log(__CLASS__, __METHOD__, 'start', '', [$procedureOrderObject]);
            $orderId = 0;
            $amount = 0;
            switch ($procedureOrderObject->typeId) {
                case OrderType::TYPE_CREDIT_NOTE:
                    $parentOrder = $procedureOrderObject->parentOrder;
                    $amount = $procedureOrderObject->amounts[0]->invoiceTotal - $procedureOrderObject->amounts[0]->giftCardAmount;

                    $this->log(__CLASS__, __METHOD__, 'credit_note_info', '', [
                        'orderReferences' => $procedureOrderObject->orderReferences,
                        'isObject' => is_object($procedureOrderObject->orderReferences),
                        'isArray' => is_array($procedureOrderObject->orderReferences),
                    ]);

                    if (isset($procedureOrderObject->orderReferences)) {
                        foreach ($procedureOrderObject->orderReferences as $reference) {
                            if ($reference->referenceType == 'parent') {
                                $orderId = (int)$reference->originOrderId;
                            }
                        }
                    }

                    if (empty($orderId) && $parentOrder instanceof Order && $parentOrder->typeId == 1) {
                        $orderId = (int)$parentOrder->id;
                    }
                    break;
                case OrderType::TYPE_SALES_ORDER:
                    $orderId = (int)$procedureOrderObject->id;
                    $amount = $procedureOrderObject->amounts[0]->invoiceTotal - $procedureOrderObject->amounts[0]->giftCardAmount;
                    break;
            }
            $this->log(__CLASS__, __METHOD__, 'info', '', ['orderId' => $orderId, 'procedureOrderObjectId' => $procedureOrderObject->id, 'amount' => $amount]);
            if (empty($orderId)) {
                throw new Exception('Amazon Pay Refund failed! The given order is invalid!');
            }
            $transactionRepository = pluginApp(TransactionRepository::class);
            $transaction = $transactionRepository->getTransactionByOrderId($orderId);

            if (empty($transaction)) {
                throw new Exception('No Unzer transaction found for order id: ' . $orderId);
            }

            $apiService = pluginApp(ApiService::class);
            $cancellations = $apiService->refund($transaction->unzerPaymentId, $amount);

            $orderService = pluginApp(OrderService::class);
            foreach($cancellations as $cancellation) {
                $this->log(__CLASS__, __METHOD__, 'cancellation', '', ['cancellation' => $cancellation]);
                $refundObject = $orderService->createPaymentObject(
                    $amount,
                    $cancellation['success']?Payment::STATUS_REFUNDED:Payment::STATUS_REFUSED,
                    $cancellation['id'],
                    'Event Procedure Refund',
                    null,
                    Payment::PAYMENT_TYPE_DEBIT,
                    $cancellation['success']?Payment::TRANSACTION_TYPE_BOOKED_POSTING:Payment::TRANSACTION_TYPE_PROVISIONAL_POSTING,
                    $transaction->currency
                );
                $orderService->assignPlentyPaymentToPlentyOrder($refundObject, $procedureOrderObject);
            }
        } catch (Exception $e) {
            $this->error(__CLASS__, __METHOD__, 'failed', $e->getMessage(), ['exception' => $e]);
        }

    }
}
