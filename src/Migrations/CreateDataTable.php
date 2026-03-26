<?php

namespace UnzerPayment\Migrations;

use Plenty\Modules\Plugin\DataBase\Contracts\Migrate;
use UnzerPayment\Models\UnzerData;

class CreateDataTable
{
    public function run(Migrate $migrate)
    {
        $migrate->createTable(UnzerData::class);
        $migrate->updateTable(UnzerData::class);
    }
}