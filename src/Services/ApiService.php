<?php

namespace UnzerPayment\Services;

use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use UnzerPayment\Repositories\UnzerDataRepository;
use UnzerPayment\Traits\LoggingTrait;
use UnzerPayment\Traits\TranslationTrait;

class ApiService
{
    use LoggingTrait;
    use TranslationTrait;

    public const STATE_NAME_PENDING = 'pending';
    public const STATE_NAME_COMPLETED = 'completed';
    public const STATE_NAME_CANCELED = 'canceled';
    public const STATE_NAME_PARTLY = 'partly';
    public const STATE_NAME_PAYMENT_REVIEW = 'payment review';
    public const STATE_NAME_CHARGEBACK = 'chargeback';
    public const STATE_NAME_CREATE = 'create';

    private ?array $paymentTypes = null;


    private ConfigService $configService;
    private UnzerDataRepository $unzerDataRepository;

    public function __construct(
        ConfigService       $configService,
        UnzerDataRepository $unzerDataRepository,
    )
    {
        $this->configService = $configService;
        $this->unzerDataRepository = $unzerDataRepository;
    }

    public function call(string $action, array $parameters): array
    {
        if (!in_array($action, ['getAvailablePaymentTypes'], true)) {
            $this->log(__CLASS__, __METHOD__, 'start', '', [
                'action' => $action,
                'parameters' => $parameters,
            ]);
        }

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
        if (!in_array($action, ['getAvailablePaymentTypes'], true)) {
            $this->log(__CLASS__, __METHOD__, 'result', '', [
                'startTime' => $startTime,
                'endTime' => $endTime,
                'duration' => $duration,
                'action' => $action,
                'parameters' => ($action === 'createPayPage' ? ['too many for paypage'] : $parameters),
                'result' => $result,
            ]);
        }
        if ($action === 'createPayPage') {
            $this->log(__CLASS__, __METHOD__, 'parameters', '', [
                'parameters' => $parameters,
            ]);
        }

        return $result;
    }


    public function getUnzerPayment(string $paymentId): ?array
    {
        $response = $this->call('getPayment', [
            'id' => $paymentId,
        ]);
        $this->log(__CLASS__, __METHOD__, 'payment', '', [
            'payment' => $response['response']['payment'],
        ]);
        if (!empty($response['response']['payment']['paymentInstructions'])) {
            //translate
            $instructions = $response['response']['payment']['paymentInstructions'];
            foreach (['accountHolder', 'iban', 'bic', 'paymentDescriptor'] as $text) {
                $instructions = str_replace('__' . $text . '__', $this->getTranslation('Frontend.' . $text), $instructions);
            }
            $response['response']['payment']['paymentInstructions'] = $instructions;
        }

        return $response['response']['payment'] ?? null;
    }

    public function getPayPage(string $payPageId): ?array
    {
        $response = $this->call('getPayPage', [
            'id' => $payPageId,
        ]);
        return $response['response']['payPage'] ?? null;
    }

    public function getAvailablePaymentTypes(): ?array
    {
        if (empty($this->paymentTypes)) {
            $keyHash = md5($this->configService->getPrivateKey());
            try {
                $cachedPaymentTypes = $this->unzerDataRepository->getValue('availablePaymentTypes_' . $keyHash);
                $cachedPaymentTypesTime = $this->unzerDataRepository->getValue('availablePaymentTypesTime_' . $keyHash);
            } catch (\Throwable $exception) {
                $this->error(__CLASS__, __METHOD__, 'exceptionReading', '', ['msg' => $exception->getMessage()]);
            }
            if (!empty($cachedPaymentTypes) && !empty($cachedPaymentTypesTime) && $cachedPaymentTypesTime > (time() - 3600)) {
                $this->paymentTypes = $cachedPaymentTypes;
            } else {
                $this->log(__CLASS__, __METHOD__, 'notFromCache', '', ['types' => '']);
                $response = $this->call('getAvailablePaymentTypes', []);
                $this->paymentTypes = $response['response']['paymentTypes'] ?? [];
                try {
                    $this->unzerDataRepository->setValue('availablePaymentTypes_' . $keyHash, $this->paymentTypes);
                    $this->unzerDataRepository->setValue('availablePaymentTypesTime_' . $keyHash, time());
                } catch (\Throwable $exception) {
                    $this->error(__CLASS__, __METHOD__, 'exceptionWriting', '', ['msg' => $exception->getMessage()]);
                }
            }
        }
        return $this->paymentTypes;
    }

    public function createWebhook(string $url): ?array
    {
        $this->log(__CLASS__, __METHOD__, __LINE__);
        $response = $this->call('createWebhook', [
            'url' => $url,
        ]);
        return $response['response']['webhooks'] ?? null;
    }

    /**
     * Pay page for payment after order creation
     * @param array $checkoutData
     * @param string|null $reference
     * @param string|null $paymentTypeCode (might be pipe separated list)
     * @return array|null
     */
    public function createPayPageNew(array $checkoutData, ?string $reference = null, ?string $paymentTypeCode = null): ?array
    {
        $response = $this->call('createPayPage', [
            'checkoutData' => $checkoutData,
            'shopVersion' => $this->configService->getShopVersion(),
            'pluginVersion' => $this->configService->getPluginVersion(),
            'returnUrl' => $this->configService->getPayReturnUrl($reference, $checkoutData['orderId'] ?? null),
            'orderReference' => $reference,
            'paymentTypeCode' => $paymentTypeCode,
            'bookingMode' => $this->configService->getBookingMode($paymentTypeCode),
        ]);
        return $response['response']['payPage'] ?? null;
    }

    /**
     * Pay page for payment before order creation
     */
    public function createPayPage(array $checkoutData, ?string $reference = null, ?string $paymentTypeCode = null): ?array
    {
        $response = $this->call('createPayPage', [
            'checkoutData' => $checkoutData,
            'shopVersion' => $this->configService->getShopVersion(),
            'pluginVersion' => $this->configService->getPluginVersion(),
            'returnUrl' => $this->configService->getReturnUrl($reference),
            'orderReference' => $reference,
            'checkoutUrl' => $this->configService->getShopCheckoutUrl(),
            'paymentTypeCode' => $paymentTypeCode,
            'bookingMode' => $this->configService->getBookingMode($paymentTypeCode),
        ]);
        return $response['response']['payPage'] ?? null;
    }

    public function capture(string $paymentId, float $amount): ?array
    {
        $response = $this->call('charge', [
            'paymentId' => $paymentId,
            'amount' => $amount,
        ]);
        return $response['response']['charge'] ?? null;
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