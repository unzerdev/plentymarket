<?php

namespace UnzerPayment\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * Class Transaction
 *
 * @property int $id
 * @property string $unzerPaymentId
 * @property string $unzerShortId
 * @property string $time
 * @property float $amount
 * @property int $orderId
 * @property int $paymentId
 * @property string $currency
 * @property string $reference
 */
class Transaction extends Model
{
    public $id = 0;
    public $unzerPaymentId = '';
    public $unzerShortId = '';
    public $time = '';
    public $amount = 0.0;

    public $currency = '';
    public $orderId = 0;
    public $paymentId = 0;
    public $reference = '';

    /**
     * @return string
     */
    public function getTableName(): string
    {
        return 'UnzerPayment::Transaction';
    }
}