<?php

namespace UnzerPayment\Providers;

use Plenty\Modules\Basket\Contracts\BasketItemRepositoryContract;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemRemove;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemUpdate;
use Plenty\Modules\Cron\Services\CronContainer;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\Frontend\Contracts\Checkout;
use Plenty\Modules\Frontend\Events\FrontendCustomerAddressChanged;
use Plenty\Modules\Frontend\Events\FrontendLanguageChanged;
use Plenty\Modules\Frontend\Events\FrontendShippingCountryChanged;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\ServiceProvider as ServiceProviderParent;
use UnzerPayment\Constants\Constants;
use UnzerPayment\Contracts\TransactionRepositoryContract;
use UnzerPayment\CronHandlers\ExternalOrderMatcherCronHandler;
use UnzerPayment\Models\Transaction;
use UnzerPayment\PaymentMethods\UnzerPaymentMethod;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\CheckoutService;
use UnzerPayment\Services\ConfigService;
use UnzerPayment\Services\OrderService;
use UnzerPayment\Services\TransactionService;
use UnzerPayment\Traits\LoggingTrait;

class ServiceProvider extends ServiceProviderParent
{
    use LoggingTrait;

    const PLUGIN_NAME = 'UnzerPayment';

    public function boot(
        Dispatcher               $eventDispatcher,
        PaymentMethodContainer   $payContainer,
        EventProceduresService   $eventProceduresService,
        CronContainer            $cronContainer,
        BasketRepositoryContract $basketRepository
    )
    {
        $this->registerPaymentMethods($payContainer);
        $this->registerPaymentRendering($eventDispatcher);
        $this->registerPaymentExecute($eventDispatcher, $basketRepository);
        $this->registerCronjobs($cronContainer);
        $this->registerEventProcedures($eventProceduresService);

    }

    public function register()
    {
        $this->getApplication()->register(RouteServiceProvider::class);
        $this->getApplication()->bind(TransactionRepositoryContract::class, TransactionRepository::class);
    }

    protected function registerCronjobs(CronContainer $cronContainer): void
    {
        $cronContainer->add(CronContainer::EVERY_FIVE_MINUTES, ExternalOrderMatcherCronHandler::class);
    }

    protected function registerEventProcedures(EventProceduresService $eventProceduresService): void
    {
        $eventProceduresService->registerProcedure(
            Constants::PLUGIN_KEY,
            ProcedureEntry::PROCEDURE_GROUP_ORDER,
            [
                'de' => 'Unzer: Vollständiger Zahlungseinzug',
                'en' => 'Unzer: Complete capture',
            ],
            '\UnzerPayment\Procedures\CaptureProcedure@run'
        );

        $eventProceduresService->registerProcedure(
            Constants::PLUGIN_KEY,
            ProcedureEntry::PROCEDURE_GROUP_ORDER,
            [
                'de' => 'Unzer: Rückzahlung',
                'en' => 'Unzer: Refund',
            ],
            '\UnzerPayment\Procedures\RefundProcedure@run'
        );
    }

    protected function registerPaymentMethods(PaymentMethodContainer $payContainer): void
    {

        $payContainer->register(
            Constants::PLUGIN_KEY . '::' . UnzerPaymentMethod::PAYMENT_METHOD_CODE,
            UnzerPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class,
                AfterBasketItemUpdate::class,
                AfterBasketItemRemove::class,
                FrontendLanguageChanged::class,
                FrontendShippingCountryChanged::class,
                FrontendCustomerAddressChanged::class,
            ]
        );

    }


    /**
     * @param Dispatcher $eventDispatcher
     */
    protected function registerPaymentRendering(
        Dispatcher $eventDispatcher
    )
    {
        $eventDispatcher->listen(
            GetPaymentMethodContent::class,
            function (GetPaymentMethodContent $event) {
                $paymentMethodId = (int)$event->getMop();
                if ($paymentMethodId !== UnzerPaymentMethod::getPaymentMethodId()) {
                    return;
                }

                $plentyCheckout = pluginApp(Checkout::class);
                $checkoutService = pluginApp(CheckoutService::class);
                $basketRepository = pluginApp(BasketRepositoryContract::class);
                $basketItemRepository = pluginApp(BasketItemRepositoryContract::class);
                $configService = pluginApp(ConfigService::class);

                $basketItems = [];
                foreach ($basketItemRepository->all() as $basketItem) {
                    $basketItems[] = $basketItem->toArray();
                }
                $reference = 'tmp-unzer-checkout-' . uniqid();
                $payPage = $checkoutService->createUnzerPayPageFromBasket($basketRepository->load(), $basketItems, $plentyCheckout, $reference);
                $threatMetrixUrl = 'https://h.online-metrix.net/fp/tags.js?org_id='; //TODO
                $checkoutPageUrl = $configService->getShopCheckoutUrl();
                $returnUrl = $configService->getReturnUrl($reference);
                $locale = 'de';
                if ($payPage) {
                    $transactionService = pluginApp(TransactionService::class);
                    $transaction = pluginApp(Transaction::class);
                    $transaction->unzerPaymentId = $payPage['paymentId'];
                    $transaction->amount = $payPage['amount'];
                    $transaction->currency = $payPage['currency'];
                    $transaction->reference = $reference;
                    $transactionService->upsertTransaction($transaction);

                    $html = '
<link rel="stylesheet" href="https://static.unzer.com/v1/unzer.css"/>
<script type="text/javascript" src="https://static.unzer.com/v1/checkout.js"></script>
<script type="text/javascript" src="' . $threatMetrixUrl . '" async></script>
<span id="unzer-checkout-dummy"></span>"
<script>
    const unzerCheckoutInit = ()=>{
        if(typeof window.checkout === "undefined"){
            setTimeout(unzerCheckoutInit, 200);
            return;
        }
        const unzerCheckout = new window.checkout(\'' . $payPage['id'] . '\', { locale: \'' . $locale . '\' });
        unzerCheckout.init().then(function () {
            unzerCheckout.open();
            unzerCheckout.abort(function () {
                location.href = \'' . $checkoutPageUrl . '\';
            });
    
            unzerCheckout.success(function (data) {
                // handle success event.
                window.location.href = \'' . $returnUrl . '\';
            });
    
            unzerCheckout.error(function (error) {
                location.href = \'' . $checkoutPageUrl . '\';
            });
        });
        
        if(jQuery){
            jQuery(\'#unzer-checkout-dummy\').closest(\'.modal\').hide();
            jQuery(\'.modal-backdrop\').hide();
        }
        
    };
    unzerCheckoutInit();    
</script>';


                } else {
                    $html = 'no paypage';
                }

                $event->setValue($html);
                $event->setType(GetPaymentMethodContent::RETURN_TYPE_HTML);
            }
        );
    }

    /**
     * @param Dispatcher $dispatcher
     * @param BasketRepositoryContract $basketRepository
     */
    protected function registerPaymentExecute(Dispatcher $dispatcher, BasketRepositoryContract $basketRepository)
    {
        $dispatcher->listen(ExecutePayment::class, function (ExecutePayment $event) use ($basketRepository) {
            $paymentMethodId = (int)$event->getMop();
            if ($paymentMethodId === UnzerPaymentMethod::getPaymentMethodId()) {
                /** @var SessionStorageRepositoryContract $sessionStorageRepository */
                $sessionStorageRepository = pluginApp(SessionStorageRepositoryContract::class);
                $unzerPaymentId = $sessionStorageRepository->getSessionValue(Constants::SESSION_KEY_PAYMENT_ID);

                if (empty($unzerPaymentId)) {
                    $event->setType('error');
                    $event->setValue('No payment id found'); //TODO
                    return;
                }

                $apiService = pluginApp(ApiService::class);
                $payment = $apiService->getUnzerPayment($unzerPaymentId);
                if (empty($payment)) {
                    $event->setType('error');
                    $event->setValue('No payment found'); //TODO
                    $sessionStorageRepository->setSessionValue(Constants::SESSION_KEY_PAYMENT_ID, null);
                    return;
                }
                if ($payment['state'] !== 'completed') {
                    $event->setType('error');
                    $event->setValue('Payment not completed: ' . $payment['state']); //TODO
                    $sessionStorageRepository->setSessionValue(Constants::SESSION_KEY_PAYMENT_ID, null);
                    return;
                }

                $orderService = pluginApp(OrderService::class);
                $orderService->syncPaymentInformation((int)$event->getOrderId(), $unzerPaymentId);

                $event->setType('success');
                $event->setValue('The payment has been executed successfully!');
            }

        });
    }
}
