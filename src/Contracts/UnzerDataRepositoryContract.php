<?php

namespace UnzerPayment\Contracts;

use UnzerPayment\Models\UnzerData;

interface UnzerDataRepositoryContract
{
    /**
     * Add a new data item
     *
     * @param array $data
     *
     * @return UnzerData
     */
    public function create(array $data);

    /**
     * List all data items
     *
     * @param array $criteria
     *
     * @return UnzerData[]
     */
    public function get(array $criteria);

    /**
     * Update data item
     *
     * @param UnzerData $unzerData
     *
     * @return UnzerData
     */
    public function update(UnzerData $unzerData);

    /**
     * Save data item
     *
     * @param UnzerData $unzerData
     *
     * @return UnzerData
     */
    public function save(UnzerData $unzerData);

    public function getValue(string $dataKey);

    public function setValue(string $dataKey, $value):void;


}