<?php

namespace UnzerPayment\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\ConfigService;
use UnzerPayment\Traits\LoggingTrait;

class WebhookController extends Controller
{
    const REGISTERED_EVENTS = [
        'charge.canceled',
        'authorize.canceled',
        'authorize.succeeded',
        'charge.succeeded',
        'payment.chargeback',
    ];

    use LoggingTrait;
    private Response $response;
    private Request $request;

    public function __construct(Response $response, Request $request)
    {
        parent::__construct();
        $this->response = $response;
        $this->request = $request;
    }

    public function webhook(): string
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', ['content'=>$this->request->getContent()]);
        $data = json_decode($this->request->getContent(), true);


        if (empty($data)) {
            $this->log(__CLASS__, __METHOD__, 'empty');
            return 'empty webhook';
        }

        if (!in_array($data['event'], self::REGISTERED_EVENTS, true)) {
            $this->log(__CLASS__, __METHOD__, 'not_relevant');
            return 'not relevant';
        }

        $this->log(__CLASS__, __METHOD__, 'data', '', ['webhook_data' => $data]);
        if (empty($data['paymentId'])) {
            $this->error(__CLASS__, __METHOD__, 'no_payment_id', 'no payment id in webhook event', ['webhook_data' => $data]);
            return 'no payment id in webhook event';
        }
        $apiService = pluginApp(ApiService::class);
        $payment = $apiService->getUnzerPayment($data['paymentId']);

        $transactionRepository = pluginApp(TransactionRepository::class);
        $transaction = $transactionRepository->getTransactionByUnzerPaymentId($data['paymentId']);
        if (empty($transaction)) {
            $transactionRepository->persistUnzerPayment($payment);
        }

//
//        $orderId = $this->orderHelper->getOrderIdFromPaymentId($data['paymentId']);
//        if (empty($orderId)) {
//            $this->logger->warning('no order id for payment id in webhook event', ['webhook_data' => $data]);
//            return $this->getJsonResponse(false, ['msg' => 'no order id for payment id in webhook event']);
//        }
//
//        switch ($data['event']) {
//            case WebhookEvents::CHARGE_CANCELED:
//            case WebhookEvents::AUTHORIZE_CANCELED:
//                $this->handleCancel($data['paymentId'], $orderId);
//                break;
//            case WebhookEvents::AUTHORIZE_SUCCEEDED:
//                $this->handleAuthorizeSucceeded($data['paymentId'], $orderId);
//                break;
//            case WebhookEvents::CHARGE_SUCCEEDED:
//                $this->handleChargeSucceeded($data['paymentId'], $orderId);
//                break;
//            case WebhookEvents::PAYMENT_CHARGEBACK:
//                $this->handleChargeback($data['paymentId'], $orderId);
//                break;
//        }
//        return $this->getJsonResponse(true, ['msg' => 'webhook processed']);
//        $apiService = pluginApp(ApiService::class);
//        $payment = $apiService->getUnzerPayment($this->request->get('paymentId'));

        return 'done';
    }

    public function register(): string
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', ['content'=>$this->request->getContent()]);

        $configService = pluginApp(ConfigService::class);
        $webhookUrl = $configService->getWebhookUrl();
        if(empty($webhookUrl)) {
            $this->error(__CLASS__, __METHOD__, 'no_webhook_url', 'no webhook url configured');
            return 'no webhook url configured';
        }
        $webhooks = pluginApp(ApiService::class)->createWebhook($webhookUrl);
        return json_encode($webhooks, JSON_PRETTY_PRINT);
    }
}