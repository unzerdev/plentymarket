<?php

namespace UnzerPayment\Migrations;

use UnzerPayment\Models\Transaction;
use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;
use UnzerPayment\PaymentMethods\UnzerPaymentMethod;

class CreateTransactionTable
{
    public function run(Migrate $migrate)
    {
        $migrate->createTable(Transaction::class);
        $migrate->updateTable(Transaction::class);
        UnzerPaymentMethod::getPaymentMethodId();
    }
}