<?php

namespace UnzerPayment\Models;

use Plenty\Modules\Plugin\DataBase\Contracts\Model;

/**
 * Class UnzerData
 *
 * @property int $id
 * @property string $dataKey
 * @property array $dataValue
 * @Index(columns={"dataKey"}, name="UniqueDataKey", isUnique="true")
 */
class UnzerData extends Model
{
    public $id              = 0;
    public $dataKey = '';
    public $dataValue = [];

    /**
     * @return string
     */
    public function getTableName(): string
    {
        return 'UnzerPayment::UnzerData';
    }
}