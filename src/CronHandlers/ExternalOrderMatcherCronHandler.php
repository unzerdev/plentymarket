<?php
namespace UnzerPayment\CronHandlers;

use UnzerPayment\Services\ExternalOrderService;
use UnzerPayment\Traits\LoggingTrait;

class ExternalOrderMatcherCronHandler extends \Plenty\Modules\Cron\Contracts\CronHandler
{
    use LoggingTrait;


    public function handle()
    {
        $this->log(__CLASS__, __METHOD__, 'cron_started');
        pluginApp(ExternalOrderService::class)->process(43200);
    }


}
