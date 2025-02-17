<?php

namespace UnzerPayment\Migrations;

use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;
use UnzerPayment\Models\Transaction;
use UnzerPayment\PaymentMethods\UnzerPaymentMethod;
//TODO remove
class CreateTransactionTable2
{
    public function run(Migrate $migrate)
    {
        $migrate->createTable(Transaction::class);
        $migrate->updateTable(Transaction::class);
        UnzerPaymentMethod::getPaymentMethodId();
    }
}