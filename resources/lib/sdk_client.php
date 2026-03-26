<?php

use UnzerSDK\Exceptions\UnzerApiException;

require_once __DIR__ . '/ApiHelperSdk.php';

$return = [
    'response' => [],
];

try {
    $action = SdkRestApi::getParam('action');
    $return["action"] = $action;
    $apiHelper = new ApiHelperSdk();
    $startTime = microtime(true);
    switch ($action) {
        case 'getPayment':
            $return['response']['payment'] = $apiHelper->getPayment(SdkRestApi::getParam('id'));
            break;
        case 'getPayPage':
            $return['response']['payPage'] = $apiHelper->getPayPage(SdkRestApi::getParam('id'));
            break;
        case 'getAvailablePaymentTypes':
            $return['response']['paymentTypes'] = $apiHelper->getAvailablePaymentTypes();
            break;
        case 'createWebhook':
            try {
                $apiHelper->createWebhook(SdkRestApi::getParam('url'));
            } catch (Exception $e) {
                // Capture webhook exception but do not overwrite $return
                $return['exception'] = [
                    'object' => $e,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                ];
            }
            $return['response']['webhooks'] = $apiHelper->getWebhooks();
            break;
        case 'createPayPage':
            $return['response']['payPage'] = $apiHelper->createPayPage(
                SdkRestApi::getParam('checkoutData'),
                SdkRestApi::getParam('returnUrl'),
                SdkRestApi::getParam('checkoutUrl')??null,
                SdkRestApi::getParam('orderReference'),
                SdkRestApi::getParam('paymentTypeCode'),
                SdkRestApi::getParam('bookingMode'),
            );
            break;
        case 'refund':
            $cancellations = $apiHelper->refund(
                SdkRestApi::getParam('paymentId'),
                SdkRestApi::getParam('amount')
            );
            $return['response']['cancellations'] = $cancellations;
            break;
        case 'charge':
            $return['response']['startLog'] = 1;
            $charge = $apiHelper->charge(
                SdkRestApi::getParam('paymentId'),
                SdkRestApi::getParam('amount')
            );
            $return['response']['afterLog'] = 1;
            $return['response']['charge'] = $charge->expose();
            break;
    }
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    $return["call_duration"] = $duration;
} catch (Throwable $e) {
    // Capture all other exceptions
    $return['exception'] = [
        'object' => $e,
        'message' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => $e->getFile(),
    ];

    if($e instanceof UnzerApiException){
        $return['exception']['code'] = $e->getCode();
        $return['exception']['errorId'] = $e->getErrorId();
        $return['exception']['clientMessage'] = $e->getClientMessage();
        $return['exception']['merchantMessage'] = $e->getMerchantMessage();
        $return['exception']['trace'] = $e->getTraceAsString();
    }
}

if(!empty(ApiHelperSdk::$warnings)){
    $return['warnings'] = ApiHelperSdk::$warnings;
}

return $return;