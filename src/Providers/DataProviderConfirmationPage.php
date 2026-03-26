<?php

namespace UnzerPayment\Providers;


use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use UnzerPayment\Contracts\TransactionRepositoryContract;
use UnzerPayment\PaymentMethods\UnzerPaylaterInstallmentPaymentMethod;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\OrderService;
use UnzerPayment\Traits\LoggingTrait;

class DataProviderConfirmationPage
{
    use LoggingTrait;

    public function call($order)
    {
        $orderId = null;
        if (is_object($order) && !empty($order->id)) {
            $orderId = (int)$order->id;
        } elseif (is_array($order) && !empty($order['id'])) {
            $orderId = (int)$order['id'];
        }

        if (empty($orderId)) {
            $this->log(__CLASS__, __METHOD__, 'notOrder', '', ['order' => $order]);
            return '';
        }

        $orderService = pluginApp(OrderService::class);
        $order = $orderService->getOrder($orderId);
        if((int)$order->methodOfPaymentId === UnzerPaylaterInstallmentPaymentMethod::getPaymentMethodId()){
            return '';
        }

        $transactionRepository = pluginApp(TransactionRepositoryContract::class);
        $transaction = $transactionRepository->getTransactionByOrderId($orderId);

        if (empty($transaction)) {
            $this->log(__CLASS__, __METHOD__, 'noTransaction', '', ['order' => $order]);
            return '';
        }

        if (empty($transaction->unzerPaymentId)) {
            $this->log(__CLASS__, __METHOD__, 'noPaymentId', '', ['transaction' => $transaction]);
            return '';
        }

        $apiService = pluginApp(ApiService::class);
        $payment = $apiService->getUnzerPayment($transaction->unzerPaymentId);
        $this->log(__CLASS__, __METHOD__, 'payment', '', ['payment' => $payment]);

        if (empty($payment['paymentInstructions'])) {
            return '';
        }
        return '<div class="unzer-payment-instructions"><p>' . nl2br($payment['paymentInstructions']) . '</p></div>';
    }
}