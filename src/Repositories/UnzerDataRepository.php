<?php

namespace UnzerPayment\Repositories;

use Exception;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use UnzerPayment\Contracts\UnzerDataRepositoryContract;
use UnzerPayment\Models\UnzerData;
use UnzerPayment\Traits\LoggingTrait;

class UnzerDataRepository implements UnzerDataRepositoryContract
{
    use LoggingTrait;

    /**
     * @param array $data
     *
     * @return UnzerData
     */
    public function create(array $data)
    {
        $unzerData = pluginApp(UnzerData::class);
        $unzerData->dataKey = $data['dataKey'];
        $unzerData->dataValue = $data['dataValue'];

        return $this->save($unzerData);
    }

    /**
     * @param UnzerData $unzerData
     *
     * @return UnzerData|null
     */
    public function save(UnzerData $unzerData)
    {
        $database = pluginApp(DataBase::class);
        try {
            return $database->save($unzerData);
        } catch (Exception $e) {
            $this->error(__CLASS__, __METHOD__, 'error', '', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function delete(UnzerData $unzerData)
    {
        $database = pluginApp(DataBase::class);
        return $database->delete($unzerData);
    }

    /**
     * @param array $criteria
     *
     * @return UnzerData[]
     */
    public function get(array $criteria)
    {
        $database = pluginApp(DataBase::class);
        $stmt = $database->query(UnzerData::class);

        foreach ($criteria as $c) {
            $stmt->where($c[0], $c[1], $c[2]);
        }

        $result = $stmt->get();
        return $result;
    }

    public function update(UnzerData $unzerData)
    {
        return $this->save($unzerData);
    }

    public function getValue(string $dataKey)
    {
        $results = $this->get([['dataKey', '=', $dataKey]]);
        if (!empty($results) && isset($results[0])) {
            $dataValue = $results[0]->dataValue;
            return $dataValue['value'];
        }

        return null;
    }

    public function setValue(string $dataKey, $value): void
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', ['dataKey' => $dataKey, 'value' => $value]);
        $results = $this->get([['dataKey', '=', $dataKey]]);

        if (!empty($results) && isset($results[0])) {
            $unzerData = $results[0];
            $unzerData->dataValue = ['value' => $value];
            $this->log(__CLASS__, __METHOD__, 'update', '', ['unzerData' => $unzerData]);
            $this->update($unzerData);
        } else {
            $this->log(__CLASS__, __METHOD__, 'create');
            $this->create([
                'dataKey' => $dataKey,
                'dataValue' => ['value' => $value],
            ]);
        }
    }
}