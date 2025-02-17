<?php

namespace UnzerPayment\Repositories;

use Exception;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use UnzerPayment\Contracts\TransactionRepositoryContract;
use UnzerPayment\Models\Transaction;
use UnzerPayment\Traits\LoggingTrait;

class TransactionRepository implements TransactionRepositoryContract
{
    use LoggingTrait;

    /**
     * @param array $data
     *
     * @return Transaction
     */
    public function createTransaction(array $data)
    {
        $transaction = pluginApp(Transaction::class);
        $transaction->unzerPaymentId = (string)$data["unzerPaymentId"];
        $transaction->unzerShortId = (string)$data["unzerShortId"];
        $transaction->time = (string)$data["time"];
        $transaction->amount = (float)$data["amount"];
        $transaction->orderId = (int)$data["orderId"];
        $transaction->paymentId = (int)$data["paymentId"];
        $transaction->currency = (string)$data["currency"];

        return $this->saveTransaction($transaction);
    }

    /**
     * @param Transaction $transaction
     *
     * @return Transaction|null
     */
    public function saveTransaction(Transaction $transaction)
    {
        $database = pluginApp(DataBase::class);
        try {
            return $database->save($transaction);
        } catch (Exception $e) {
            $this->error(__CLASS__, __METHOD__, 'error', '', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param array $criteria
     *
     * @return Transaction[]
     */
    public function getTransactions(array $criteria)
    {
        $database = pluginApp(DataBase::class);
        $stmt = $database->query(Transaction::class);

        foreach ($criteria as $c) {
            $stmt->where($c[0], $c[1], $c[2]);
        }

        $result = $stmt->get();
        $this->log(__CLASS__, __METHOD__, 'result', '', ['criteria' => $criteria, 'result' => $result]);
        return $result;
    }

    public function getTransaction(array $criteria): ?Transaction
    {
        $transactions = $this->getTransactions($criteria);
        return !empty($transactions) ? $transactions[0] : null;
    }

    public function updateTransaction(Transaction $transaction)
    {
        return $this->saveTransaction($transaction);
    }

    public function getTransactionById(int $id): ?Transaction
    {
        return $this->getTransaction([['id', '=', $id]]);
    }

    public function getTransactionByOrderId(int $orderId): ?Transaction
    {
        return $this->getTransaction([['orderId', '=', $orderId]]);
    }

    public function getTransactionByUnzerPaymentId(string $unzerPaymentId): ?Transaction
    {
        return $this->getTransaction([['unzerPaymentId', '=', $unzerPaymentId]]);
    }

    public function getTransactionByReference(string $reference): ?Transaction
    {
        return $this->getTransaction([['reference', '=', $reference]]);
    }


    public function getTransactionsByAmountAndTime(float $amount, string $time, int $tolerance = 43200): array
    {
        $timeFrom = date('Y-m-d H:i:s', strtotime($time) - $tolerance);
        $timeTo = date('Y-m-d H:i:s', strtotime($time) + $tolerance);
        $transactions = $this->getTransactions([
            ['amount', '>=', $amount - 0.01],
            ['amount', '<=', $amount + 0.01],
            ['time', '>=', $timeFrom],
            ['time', '<=', $timeTo],
        ]);

        $return = [];
        $amountString = number_format($amount, 2);
        if ($transactions) {
            foreach ($transactions as $transaction) {
                if (number_format($transaction->amount, 2) === $amountString) {
                    $return[] = $transaction;
                }
            }
        }
        return $return;
    }

    public function persistUnzerPayment($unzerPayment, $orderId = null, $plentyPaymentId = null): ?Transaction
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', [
            'unzerPayment' => $unzerPayment,
            'orderId' => $orderId,
            'plentyPaymentId' => $plentyPaymentId,
        ]);

        $transaction = $this->getTransactionObject($unzerPayment['id']);
        $transaction->amount = $unzerPayment['amount']['total'];
        $transaction->currency = $unzerPayment['amount']['currency'];


        if ($orderId) {
            $transaction->orderId = $orderId;
        }

        if ($plentyPaymentId) {
            $transaction->paymentId = $plentyPaymentId;
        }

        $this->saveTransaction($transaction);

        return $transaction;
    }

    public function getTransactionObject(string $unzerPaymentId): Transaction
    {
        if ($transaction = $this->getTransactionByUnzerPaymentId($unzerPaymentId)) {
            return $transaction;
        } else {
            $transaction = pluginApp(Transaction::class);
            $transaction->unzerPaymentId = $unzerPaymentId;
            $transaction->time = gmdate('Y-m-d H:i:s');
        }
        return $transaction;
    }

}