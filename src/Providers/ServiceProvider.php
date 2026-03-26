<?php

namespace UnzerPayment\Providers;

use AmazonPayCheckout\Helpers\ConfigHelper;
use AmazonPayCheckout\Providers\DataProviderJavascript;
use Ceres\Helper\LayoutContainer;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemRemove;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemUpdate;
use Plenty\Modules\Cron\Services\CronContainer;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\Frontend\Events\FrontendCustomerAddressChanged;
use Plenty\Modules\Frontend\Events\FrontendLanguageChanged;
use Plenty\Modules\Frontend\Events\FrontendShippingCountryChanged;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Modules\Webshop\Contracts\SessionStorageRepositoryContract;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Plugin\ServiceProvider as ServiceProviderParent;
use Plenty\Plugin\Templates\Twig;
use UnzerPayment\Constants\Constants;
use UnzerPayment\Contracts\TransactionRepositoryContract;
use UnzerPayment\CronHandlers\ExternalOrderMatcherCronHandler;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Services\ApiService;
use UnzerPayment\Services\ConfigService;
use UnzerPayment\Services\OrderService;
use UnzerPayment\Services\PaymentMethodService;
use UnzerPayment\Traits\LoggingTrait;
use UnzerPayment\Traits\TranslationTrait;

class ServiceProvider extends ServiceProviderParent
{
    use LoggingTrait;
    use TranslationTrait;

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
        $paymentMethods = Constants::PAYMENT_METHODS;
        uasort($paymentMethods, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
        foreach ($paymentMethods as $paymentMethod) {
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
                $event->setType(GetPaymentMethodContent::RETURN_TYPE_CONTINUE);
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
            $this->log(__CLASS__, __METHOD__, 'execPay_start', '', ['order' => $event->getOrderId()]);

            $configService = pluginApp(ConfigService::class);
            $orderService = pluginApp(OrderService::class);
            $order = $orderService->getOrder($event->getOrderId());
            $event->setType('redirectUrl');
            $event->setValue($configService->getPayUrl($event->getOrderId(), 'x'));
        });
    }

    private function registerContainerContent(Dispatcher $eventDispatcher)
    {
        $eventDispatcher->listen('Ceres.LayoutContainer.OrderConfirmation.AdditionalPaymentInformation',
            function (LayoutContainer $container, $order) {
                $dataProvider = pluginApp(DataProviderConfirmationPage::class);
                $container->addContent($dataProvider->call($order));
            });

        $eventDispatcher->listen('Ceres.LayoutContainer.OrderConfirmation.AdditionalPaymentInformation',
            function (LayoutContainer $container, $order) {
                $dataProvider = pluginApp(DataProviderReinitializeButton::class);
                $result = $dataProvider->call($order);
                $container->addContent($result);
            });
        $eventDispatcher->listen('Ceres.LayoutContainer.Script.AfterScriptsLoaded',
            function (LayoutContainer $container) {

                $container->addContent('
                <script>
                    document.addEventListener(\'DOMContentLoaded\', ()=>{
                        if(document.getElementById(\'unzer-hide-payment-method-change-link-marker\')){
                            document.head.insertAdjacentHTML("beforeend","<style>.page-confirmation .payment-link-style{display:none}</style>");
                        }
                    });                    
                </script>
                ');
            });
    }
}
