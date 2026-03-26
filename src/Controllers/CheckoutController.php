<?php

namespace UnzerPayment\Controllers;

use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Templates\Twig;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use UnzerPayment\Constants\Constants;
use UnzerPayment\Models\Transaction;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\CheckoutService;
use UnzerPayment\Services\ConfigService;
use UnzerPayment\Services\OrderService;
use UnzerPayment\Services\PaymentMethodService;
use UnzerPayment\Services\TransactionService;
use UnzerPayment\Traits\LoggingTrait;

class CheckoutController extends Controller
{
    use LoggingTrait;

    private Response $response;
    private Request $request;

    public function __construct(Response $response, Request $request)
    {
        parent::__construct();
        $this->response = $response;
        $this->request = $request;
    }

    public function payPage(Twig $twig)
    {
        $orderId = $this->request->get('orderId');
        $allMethods = $this->request->get('allMethods');
        $orderAccessKey = $this->request->get('orderAccessKey');
        /** @var SessionStorageRepositoryContract $sessionStorageRepository */
        $sessionStorageRepository = pluginApp(SessionStorageRepositoryContract::class);
        $sessionOrder = $sessionStorageRepository->getSessionValue(SessionStorageRepositoryContract::LAST_ACCESSED_ORDER);

        if ((int)$sessionOrder['orderId'] === (int)$orderId) {
            $orderAccessKey = $sessionOrder['accessKey'];
        }
        $this->log(__CLASS__, __METHOD__, 'orderdata', '', ['orderId' => $orderId, 'orderAccessKey' => $orderAccessKey]);
        $orderRepository = pluginApp(OrderRepositoryContract::class);
        $order = $orderRepository->findOrderByAccessKey($orderId, $orderAccessKey);
        $reference = 'uzr-order-' . $orderId . '-' . date('YmdHi');
        $checkoutService = pluginApp(CheckoutService::class);
        $payPage = $checkoutService->createUnzerPayPageFromOrder($order, $reference, $allMethods);

        $configService = pluginApp(ConfigService::class);

        $publicKey = $configService->getPublicKey();
        //$cancelUrl = $allMethods?$configService->getPayReturnUrl():$configService->getPayUrl($orderId, $orderAccessKey, true);
        $returnUrl = $configService->getPayReturnUrl($reference, $order->id);
        $cancelUrl = $errorUrl = $configService->getCheckoutErrorUrl();
        $locale = $configService->getLocale();
        if ($payPage) {
            $transactionService = pluginApp(TransactionService::class);
            $transaction = pluginApp(Transaction::class);
            $transaction->unzerShortId = $payPage['id'];
            $transaction->unzerPaypageId = $payPage['id'];
            $transaction->amount = $payPage['amount'];
            $transaction->currency = $payPage['currency'];
            $transaction->reference = $reference;
            $transaction->orderId = $orderId;
            $transactionService->upsertTransaction($transaction);

            $html = '
<unzer-payment publicKey="' . $publicKey . '" locale="' . $locale . '" id="unzer-payment">
    <unzer-pay-page payPageId="' . $payPage['id'] . '" id="unzer-pay-page"></unzer-pay-page>
</unzer-payment>
<script>
    Promise.all([
        customElements.whenDefined("unzer-payment"),
        customElements.whenDefined("unzer-pay-page"),
    ]).then(() => {
        const checkoutElement = document.getElementById("unzer-pay-page");

        checkoutElement.abort(function () {
            location.href = "' . $cancelUrl . '";
        });

        checkoutElement.success(function () {
            window.location.href = "' . $returnUrl . '";
        });

        checkoutElement.error(function (error) {
            location.href = "' . $errorUrl . '";
        });

        checkoutElement.open();
    });
</script>';
            //render
            return $twig->render('UnzerPayment::content.unzer-pay', ['output' => $html]);

        }else{
            $this->log(__CLASS__, __METHOD__, 'error', 'payPage not created', []);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopConfirmationUrl());
        }

    }

    /**
     * return endpoint for payment before after creation
     */
    public function payReturn()
    {
        $orderId = (int)$this->request->get('orderId');
        $reference = $this->request->get('reference');

        $apiService = pluginApp(ApiService::class);
        $configService = pluginApp(ConfigService::class);
        $checkoutService = pluginApp(CheckoutService::class);
        $transactionRepository = pluginApp(TransactionRepository::class);
        $transactionService = pluginApp(TransactionService::class);
        $paymentMethodService = pluginApp(PaymentMethodService::class);
        $orderService = pluginApp(OrderService::class);

        $this->log(__CLASS__, __METHOD__, 'parameters', '', ['reference' => $reference, 'orderId' => $orderId]);
        if (empty($reference) && empty($orderId)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'no reference or order', ['request' => $this->request]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopConfirmationUrl());
        }

        $transaction = $transactionRepository->getTransactionByReference($reference);
        $this->log(__CLASS__, __METHOD__, 'transaction', '', ['transaction' => $transaction]);
        if (empty($transaction)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'transaction not found', ['reference' => $reference]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopConfirmationUrl());
        }

        $payPage = $apiService->getPayPage($transaction->unzerShortId ?: $transaction->unzerPaypageId);
        $this->log(__CLASS__, __METHOD__, 'payPage', '', ['payPage' => $payPage]);
        if (empty($payPage)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'payPage not found', ['unzerShortId' => $transaction->unzerShortId]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopConfirmationUrl());
        }

        $paymentId = $payPage['payment']['paymentId'] ?? $payPage['paymentId'] ?? null;
        $this->log(__CLASS__, __METHOD__, 'paymentId', '', ['paymentId' => $paymentId]);
        if (empty($paymentId)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'paymentId not found', ['payPage' => $payPage]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopConfirmationUrl());
        }

        $transaction->unzerPaymentId = $paymentId;
        $transactionRepository->updateTransaction($transaction);
        $transactionService->harmonizeTransactionsByPaymentId($paymentId);
        $payment = $apiService->getUnzerPayment($paymentId);

        $this->log(__CLASS__, __METHOD__, 'payment', '', ['payment' => $payment]);
        if (empty($payment) || !in_array($payment['state'], ['completed', 'pending'])) {
            $this->log(__CLASS__, __METHOD__, 'error', 'payment not completed', ['payment' => $payment]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopConfirmationUrl());
        }

        if ($transaction->orderId != $orderId) {
            $this->error(__CLASS__, __METHOD__, 'error', 'order id mismatch', ['orderId' => $orderId, 'transaction' => $transaction]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopConfirmationUrl());
        }

        $order = $orderService->getOrder($orderId);
        $orderPaymentMethodId = (int)$order->methodOfPaymentId;

        $currentPaymentData = $paymentMethodService->getPaymentMethodDataFromUnzerPaymentTypeId($payment['paymentType']['id']);
        $this->log(__CLASS__, __METHOD__, 'currentPaymentData', '', ['$currentPaymentData' => $currentPaymentData]);
        $currentPaymentMethodId = (int)$paymentMethodService->getExistingPaymentMethodId($currentPaymentData['payment_method_code']);
        $this->log(__CLASS__, __METHOD__, 'paymentMethodIds', '', ['$orderPaymentMethodId' => $orderPaymentMethodId, '$currentPaymentMethodId'=>$currentPaymentMethodId]);

        if($orderPaymentMethodId !== $currentPaymentMethodId && !empty($currentPaymentMethodId)){
            $orderService->setOrderProperty($orderId, OrderPropertyType::PAYMENT_METHOD, $currentPaymentMethodId);
        }



        $orderService->syncPaymentInformation((int)$orderId, $paymentId);
        $this->log(__CLASS__, __METHOD__, 'setOrderExternalId', '', ['payment' => $payment]);
        if ($transaction->reference) {
            $orderService->setOrderExternalId((int)$orderId, (string)$transaction->reference);
        }



        return $this->response->redirectTo($configService->getShopConfirmationUrl());
    }


    /**
     * return endpoint for payment before order creation
     */
    public function return()
    {
        $configService = pluginApp(ConfigService::class);
        $checkoutService = pluginApp(CheckoutService::class);

        $this->log(__CLASS__, __METHOD__, 'start');
        $reference = $this->request->get('reference');
        $this->log(__CLASS__, __METHOD__, 'reference', '', ['reference' => $reference]);
        if (empty($reference)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'no reference', ['request' => $this->request]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopCheckoutUrl());
        }

        $transactionRepository = pluginApp(TransactionRepository::class);
        $transaction = $transactionRepository->getTransactionByReference($reference);
        $this->log(__CLASS__, __METHOD__, 'transaction', '', ['transaction' => $transaction]);
        if (empty($transaction)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'transaction not found', ['reference' => $reference]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopCheckoutUrl());
        }

        $apiService = pluginApp(ApiService::class);
        $payPage = $apiService->getPayPage($transaction->unzerShortId ?: $transaction->unzerPaypageId);
        $this->log(__CLASS__, __METHOD__, 'payPage', '', ['payPage' => $payPage]);
        if (empty($payPage)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'payPage not found', ['unzerShortId' => $transaction->unzerShortId]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopCheckoutUrl());
        }

        $paymentId = $payPage['payment']['paymentId'] ?? $payPage['paymentId'] ?? null;
        $this->log(__CLASS__, __METHOD__, 'paymentId', '', ['paymentId' => $paymentId]);
        if (empty($paymentId)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'paymentId not found', ['payPage' => $payPage]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopCheckoutUrl());
        }
        $transaction->unzerPaymentId = $paymentId;
        $transactionRepository->updateTransaction($transaction);

        $transactionService = pluginApp(TransactionService::class);
        $transactionService->harmonizeTransactionsByPaymentId($paymentId);


        $payment = $apiService->getUnzerPayment($paymentId);

        $this->log(__CLASS__, __METHOD__, 'payment', '', ['payment' => $payment]);
        if (empty($payment) || !in_array($payment['state'], ['completed', 'pending'])) {
            $this->log(__CLASS__, __METHOD__, 'error', 'payment not completed', ['payment' => $payment]);
            $checkoutService->scheduleGeneralErrorNotification();
            return $this->response->redirectTo($configService->getShopCheckoutUrl());
        }

        $sessionStorageRepository = pluginApp(SessionStorageRepositoryContract::class);
        $sessionStorageRepository->setSessionValue(Constants::SESSION_KEY_PAYMENT_ID, $paymentId);
        return $this->response->redirectTo($configService->getAbsoluteUrl('place-order'));
    }

    public function checkoutError(): BaseResponse
    {
        $configService = pluginApp(ConfigService::class);
        $checkoutService = pluginApp(CheckoutService::class);
        $checkoutService->scheduleGeneralErrorNotification();
        return $this->response->redirectTo($configService->getShopConfirmationUrl());
    }

    public function cancel(): BaseResponse
    {
        $configService = pluginApp(ConfigService::class);
        $checkoutService = pluginApp(CheckoutService::class);
        $checkoutService->scheduleGeneralErrorNotification();
        return $this->response->redirectTo($configService->getShopCheckoutUrl());
    }
}
