<?php

namespace UnzerPayment\Controllers;

use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use UnzerPayment\Constants\Constants;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\ConfigService;
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

    public function return()
    {
        $configService = pluginApp(ConfigService::class);
        $this->log(__CLASS__, __METHOD__, 'start');
        $reference = $this->request->get('reference');
        $this->log(__CLASS__, __METHOD__, 'reference', '', ['reference' => $reference]);
        if (empty($reference)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'no reference', ['request' => $this->request]);
            return $this->response->redirectTo($configService->getShopCheckoutUrl());
        }

        $transactionRepository = pluginApp(TransactionRepository::class);
        $transaction = $transactionRepository->getTransactionByReference($reference);
        $this->log(__CLASS__, __METHOD__, 'transaction', '', ['transaction' => $transaction]);
        if (empty($transaction)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'transaction not found', ['reference' => $reference]);
            return $this->response->redirectTo($configService->getShopCheckoutUrl());
        }

        $apiService = pluginApp(ApiService::class);
        $payPage = $apiService->getPayPage($transaction->unzerPaymentId);
        $this->log(__CLASS__, __METHOD__, 'payPage', '', ['payPage' => $payPage]);
        if (empty($payPage)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'payPage not found', ['unzerPaymentId' => $transaction->unzerPaymentId]);
            return $this->response->redirectTo($configService->getShopCheckoutUrl());
        }

        $paymentId = $payPage['payment']['paymentId'] ?? $payPage['paymentId'] ?? null;
        $this->log(__CLASS__, __METHOD__, 'paymentId', '', ['paymentId' => $paymentId]);
        if (empty($paymentId)) {
            $this->log(__CLASS__, __METHOD__, 'error', 'paymentId not found', ['payPage' => $payPage]);
            return $this->response->redirectTo($configService->getShopCheckoutUrl());
        }
        $transaction->unzerPaymentId = $paymentId;
        $transactionRepository->updateTransaction($transaction);

        $payment = $apiService->getUnzerPayment($paymentId);

        $this->log(__CLASS__, __METHOD__, 'payment', '', ['payment' => $payment]);
        if (empty($payment) || !in_array($payment['state'], ['completed', 'pending'])) {
            $this->log(__CLASS__, __METHOD__, 'error', 'payment not completed', ['payment' => $payment]);
            return $this->response->redirectTo($configService->getShopCheckoutUrl());
        }

        $sessionStorageRepository = pluginApp(SessionStorageRepositoryContract::class);
        $sessionStorageRepository->setSessionValue(Constants::SESSION_KEY_PAYMENT_ID, $paymentId);
        return $this->response->redirectTo($configService->getAbsoluteUrl('place-order'));
    }

}