<?php

namespace UnzerPayment\Procedures;

use Exception;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\OrderType;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\OrderService;
use UnzerPayment\Traits\LoggingTrait;

class CaptureProcedure
{
    use LoggingTrait;

    public function run(EventProceduresTriggered $eventTriggered)
    {
        $order = $eventTriggered->getOrder();
        $this->log(__CLASS__, __METHOD__, 'start', '', [$order]);
        if ($order->typeId == OrderType::TYPE_SALES_ORDER) {
            $orderId = (int)$order->id;
        } else {
            throw new Exception('Unzer Capture failed. The given order type is invalid: '.$order->id.' ('.$order->typeId.')');
        }

        $transactionRepository = pluginApp(TransactionRepository::class);
        $transaction = $transactionRepository->getTransactionByOrderId($orderId);

        if(empty($transaction)) {
            throw new Exception('No Unzer transaction found to capture for order ' . $orderId);
        }

        $apiService = pluginApp(ApiService::class);
        $amount = $order->amounts[0]->invoiceTotal - $order->amounts[0]->giftCardAmount;
        $apiService->capture($transaction->unzerPaymentId, $amount);

        $orderService = pluginApp(OrderService::class);
        $orderService->syncPaymentInformation($orderId, $transaction->unzerPaymentId);

    }
}