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

    public function return(){
        $this->log(__CLASS__, __METHOD__, 'start');
        $reference = $this->request->get('reference');
        if(empty($reference)){
            return 'no reference'; //TODO
        }

        $transactionRepository = pluginApp(TransactionRepository::class);
        $transaction = $transactionRepository->getTransactionByReference($reference);
        if(empty($transaction)){
            return 'no transaction'; //TODO
        }

        $apiService = pluginApp(ApiService::class);
        $response = $apiService->getUnzerPayment($transaction->unzerPaymentId);

        if(empty($response) || !in_array($response['state'], ['completed', 'pending'])){
            return 'wrong state'; //TODO
        }

        /** @var SessionStorageRepositoryContract $sessionStorageRepository */
        $sessionStorageRepository = pluginApp(SessionStorageRepositoryContract::class);
        $sessionStorageRepository->setSessionValue(Constants::SESSION_KEY_PAYMENT_ID, $transaction->unzerPaymentId);


        $configService = pluginApp(ConfigService::class);
        return $this->response->redirectTo($configService->getAbsoluteUrl('place-order'));
    }

}