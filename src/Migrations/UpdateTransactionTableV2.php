<?php

namespace UnzerPayment\Migrations;

use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;
use UnzerPayment\Models\Transaction;
use UnzerPayment\PaymentMethods\UnzerPaymentMethod;

class UpdateTransactionTableV2
{
    public function run(Migrate $migrate)
    {
        $migrate->updateTable(Transaction::class);
        UnzerPaymentMethod::getPaymentMethodId();
    }
}