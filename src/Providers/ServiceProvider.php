<?php

namespace UnzerPayment\Providers;

use Ceres\Helper\LayoutContainer;
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
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\CheckoutService;
use UnzerPayment\Services\ConfigService;
use UnzerPayment\Services\OrderService;
use UnzerPayment\Services\PaymentMethodService;
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
        $this->registerContainerContent($eventDispatcher);

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
        foreach (Constants::PAYMENT_METHODS as $paymentMethod) {
            if ($paymentMethod['status'] !== 1) {
                continue;
            }
            $payContainer->register(
                Constants::PLUGIN_KEY . '::' . $paymentMethod['payment_method_code'],
                'UnzerPayment\\PaymentMethods\\' . $paymentMethod['class_name'],
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

                $paymentMethodService = pluginApp(PaymentMethodService::class);
                if (!$paymentMethodService->isUnzerPaymentMethod((int)$event->getMop())) {
                    return;
                }
                $paymentMethodData = $paymentMethodService->getPaymentMethodData((int)$event->getMop());

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
                $payPage = $checkoutService->createUnzerPayPageFromBasket($basketRepository->load(), $basketItems, $plentyCheckout, $paymentMethodData, $reference);

                $publicKey = $configService->getPublicKey();

                $checkoutPageUrl = $configService->getShopCheckoutUrl();
                $returnUrl = $configService->getReturnUrl($reference);
                $locale = $configService->getLocale();
                if ($payPage) {
                    $transactionService = pluginApp(TransactionService::class);
                    $transaction = pluginApp(Transaction::class);
                    $transaction->unzerPaymentId = $payPage['id'];
                    $transaction->amount = $payPage['amount'];
                    $transaction->currency = $payPage['currency'];
                    $transaction->reference = $reference;
                    $transactionService->upsertTransaction($transaction);

                    $html = '
<style>
.modal-content{
width:0 !important;
height: 0 !important;
}
</style>

<!-- temporary style -->
<style>
#unzer-payment, #unzer-pay-page{
    position: fixed;
    top:0;
    left:0;
    bottom:0;
    right:0;
    z-index:900;
}
</style>
<script type="module" src="https://static-v2.unzer.com/v2/ui-components/index.js"></script>
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
            location.href = "' . $checkoutPageUrl . '";
        });

        checkoutElement.success(function () {
            window.location.href = "' . $returnUrl . '";
        });

        checkoutElement.error(function (error) {
            console.log(error);
            location.href = "' . $checkoutPageUrl . '";
        });

        checkoutElement.open();
    });
</script>';
                } else {
                    $html = 'ERROR: No Pay Page';
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
            $paymentMethodService = pluginApp(PaymentMethodService::class);
            if (!$paymentMethodService->isUnzerPaymentMethod((int)$event->getMop())) {
                return;
            }

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
            if (!in_array($payment['state'], ['completed', 'pending'], true)) {
                $event->setType('error');
                $event->setValue('Payment not completed: ' . $payment['state']); //TODO
                $sessionStorageRepository->setSessionValue(Constants::SESSION_KEY_PAYMENT_ID, null);
                return;
            }

            $orderService = pluginApp(OrderService::class);
            $orderService->syncPaymentInformation((int)$event->getOrderId(), $unzerPaymentId);

            $event->setType('success');
            $event->setValue('The payment has been executed successfully!');

        });
    }

    private function registerContainerContent(Dispatcher $eventDispatcher)
    {
        $eventDispatcher->listen('Ceres.LayoutContainer.OrderConfirmation.AdditionalPaymentInformation',
            function (LayoutContainer $container, $order) {
                $dataProvider = pluginApp(DataProviderConfirmationPage::class);
                $container->addContent($dataProvider->call($order));
            });
    }
}
