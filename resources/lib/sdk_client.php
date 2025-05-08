<?php
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
            $apiHelper->charge(
                SdkRestApi::getParam('paymentId'),
                SdkRestApi::getParam('amount')
            );
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
}

if(!empty(ApiHelperSdk::$warnings)){
    $return['warnings'] = ApiHelperSdk::$warnings;
}

return $return;