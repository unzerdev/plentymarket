<?php

namespace UnzerPayment\Services;

use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use UnzerPayment\Traits\LoggingTrait;

class ApiService
{
    use LoggingTrait;

    public const STATE_NAME_PENDING = 'pending';
    public const STATE_NAME_COMPLETED = 'completed';
    public const STATE_NAME_CANCELED = 'canceled';
    public const STATE_NAME_PARTLY = 'partly';
    public const STATE_NAME_PAYMENT_REVIEW = 'payment review';
    public const STATE_NAME_CHARGEBACK = 'chargeback';
    public const STATE_NAME_CREATE = 'create';


    private ConfigService $configService;
    private ApiService $apiService;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
    }

    public function call(string $action, array $parameters): array
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', [
            'action' => $action,
            'parameters' => $parameters,
        ]);

        $sdkClient = pluginApp(LibraryCallContract::class);
        $startTime = microtime(true);
        $result = (array)$sdkClient->call(
            'UnzerPayment::sdk_client',
            array_merge(
                [
                    'privateKey' => $this->configService->getPrivateKey(),
                    'action' => $action,
                ]
                , $parameters
            )
        );
        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        $this->log(__CLASS__, __METHOD__, 'result', '', [
            'startTime' => $startTime,
            'endTime' => $endTime,
            'duration' => $duration,
            'action' => $action,
            'parameters' => $parameters,
            'result' => $result,
        ]);


        return $result;
    }


    public function getUnzerPayment(string $paymentId): ?array
    {
        $response = $this->call('getPayment', [
            'id' => $paymentId,
        ]);
        return $response['response']['payment'] ?? null;
    }

    public function createWebhook(string $url): ?array
    {
        $this->log(__CLASS__, __METHOD__, __LINE__);
        $response = $this->call('createWebhook', [
            'url' => $url,
        ]);
        return $response['response']['webhooks'] ?? null;
    }

    public function createPayPage(array $checkoutData, ?string $reference = null): ?array
    {
        $response = $this->call('createPayPage', [
            'checkoutData' => $checkoutData,
            'returnUrl' => $this->configService->getReturnUrl($reference),
        ]);
        $this->log(__CLASS__, __METHOD__, 'response', '', ['payPage' => $response['response']['payPage']]);
        return $response['response']['payPage'] ?? null;
    }

    public function capture(string $paymentId, float $amount): ?array
    {
        $response = $this->call('charge', [
            'paymentId' => $paymentId,
            'amount' => $amount,
        ]);
        return $response['response']['payment'] ?? null;
    }

    public function refund(string $paymentId, float $amount): ?array
    {
        $response = $this->call('refund', [
            'paymentId' => $paymentId,
            'amount' => $amount,
        ]);
        return $response['response']['cancellations'] ?? null;
    }
}
