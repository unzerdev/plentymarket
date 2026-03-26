<?php

namespace UnzerPayment\Services;


use UnzerPayment\Models\Transaction;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Traits\LoggingTrait;

class TransactionService
{
    use LoggingTrait;

    public const RELEVANT_TRANSACTION_FIELDS = [
        'reference',
        'paymentId',
        'orderId',
        'unzerShortId',
        'unzerPaypageId',
    ];
    protected TransactionRepository $transactionRepository;

    public function __construct(TransactionRepository $transactionRepository)
    {
        $this->transactionRepository = $transactionRepository;
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
        }else{
            if(empty($transaction->orderId) && !empty($unzerPayment['orderId'])){
                $transaction->orderId = $unzerPayment['orderId'];
            }
        }

        if ($plentyPaymentId) {
            $transaction->paymentId = $plentyPaymentId;
        }

        $this->transactionRepository->saveTransaction($transaction);

        if(!empty($transaction->unzerPaymentId)){
            $transaction = $this->harmonizeTransactionsByPaymentId($transaction->unzerPaymentId);
        }

        return $transaction;
    }


    protected function getTransactionObject(string $unzerPaymentId): Transaction
    {
        if ($transaction = $this->transactionRepository->getTransactionByUnzerPaymentId($unzerPaymentId)) {
            return $transaction;
        } else {
            $transaction = pluginApp(Transaction::class);
            $transaction->unzerPaymentId = $unzerPaymentId;
            $transaction->time = gmdate('Y-m-d H:i:s');
        }
        return $transaction;
    }

    public function upsertTransaction(
        Transaction $transaction
    )
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', ['transaction'=>$transaction]);
        $transactionRepository = pluginApp(TransactionRepository::class);

        if (!empty($transaction->id)) {
            $existingTransaction = $transactionRepository->getTransactionById($transaction->id);
        }

        if (empty($existingTransaction) && !empty($transaction->unzerPaymentId)) {
            $existingTransaction = $transactionRepository->getTransactionByUnzerPaymentId($transaction->unzerPaymentId);
        }

        if (empty($existingTransaction) && !empty($transaction->reference)) {
            $existingTransaction = $transactionRepository->getTransactionByReference($transaction->reference);
        }

        if (empty($existingTransaction) && !empty($transaction->orderId)) {
            $existingTransaction = $transactionRepository->getTransactionByOrderId($transaction->orderId);
        }

        if (empty($existingTransaction)) {
            $existingTransaction = pluginApp(Transaction::class);
        }

        $existingTransaction->unzerPaymentId = $transaction->unzerPaymentId;
        $existingTransaction->unzerShortId = $transaction->unzerShortId;
        $existingTransaction->time = $transaction->time;
        if (empty($existingTransaction->time)) {
            $existingTransaction->time = gmdate('Y-m-d H:i:s');
        }
        $existingTransaction->amount = $transaction->amount;
        $existingTransaction->orderId = $transaction->orderId;
        $existingTransaction->paymentId = $transaction->paymentId;
        $existingTransaction->currency = $transaction->currency;
        $existingTransaction->reference = $transaction->reference;

        $transactionRepository->saveTransaction($existingTransaction);
        if(!empty($existingTransaction->unzerPaymentId)){
            $this->harmonizeTransactionsByPaymentId($existingTransaction->unzerPaymentId);
        }


        $this->log(__CLASS__, __METHOD__, 'end', '', ['return' => $existingTransaction]);
        return $existingTransaction;
    }

    public function harmonizeTransactionsByPaymentId(string $unzerPaymentId): ?Transaction
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', ['$unzerPaymentId' => $unzerPaymentId]);
        $transactionRepository = pluginApp(TransactionRepository::class);
        $transactions = $transactionRepository->getTransactions([['unzerPaymentId', '=', $unzerPaymentId]]);

        if (empty($transactions)) {
            return null;
        }

        if (count($transactions) === 1) {
            return $transactions[0];
        }

        $firstTransaction = $transactions[0];

        $this->log(__CLASS__, __METHOD__, 'working', '', ['$transactions' => $transactions]);

        foreach ($transactions as $k => $transaction) {
            if ($k === 0) {
                continue;
            }
            $this->mergeTransactions($firstTransaction, $transaction);
        }

        if($transactionRepository->saveTransaction($firstTransaction)){
            foreach ($transactions as $transaction) {
                if ($transaction->id !== $firstTransaction->id) {
                    $transactionRepository->deleteTransaction($transaction);
                }
            }
        }
        $this->log(__CLASS__, __METHOD__, 'end', '', ['return' => $firstTransaction]);
        return $firstTransaction;
    }

    // the redundancy is needed for plenty ("dynamic property names are not allowed")
    protected function mergeTransactions(Transaction $firstTransaction, Transaction $transaction){
        if (!empty($transaction->reference)) {
            if (!empty($firstTransaction->reference)) {
                if ($transaction->reference !== $firstTransaction->reference) {
                    $this->log(__CLASS__, __METHOD__, 'inconsistent', 'reference', ['$firstTransaction' => $firstTransaction, '$transaction'=>$transaction], true);
                }
            } else {
                $firstTransaction->reference = $transaction->reference;
            }
        }

        if (!empty($transaction->paymentId)) {
            if (!empty($firstTransaction->paymentId)) {
                if ($transaction->paymentId !== $firstTransaction->paymentId) {
                    $this->log(__CLASS__, __METHOD__, 'inconsistent', 'paymentId', ['$firstTransaction' => $firstTransaction, '$transaction'=>$transaction], true);
                }
            } else {
                $firstTransaction->paymentId = $transaction->paymentId;
            }
        }

        if (!empty($transaction->orderId)) {
            if (!empty($firstTransaction->orderId)) {
                if ($transaction->orderId !== $firstTransaction->orderId) {
                    $this->log(__CLASS__, __METHOD__, 'inconsistent', 'orderId', ['$firstTransaction' => $firstTransaction, '$transaction'=>$transaction], true);
                }
            } else {
                $firstTransaction->orderId = $transaction->orderId;
            }
        }

        if (!empty($transaction->unzerShortId)) {
            if (!empty($firstTransaction->unzerShortId)) {
                if ($transaction->unzerShortId !== $firstTransaction->unzerShortId) {
                    $this->log(__CLASS__, __METHOD__, 'inconsistent', 'unzerShortId', ['$firstTransaction' => $firstTransaction, '$transaction'=>$transaction], true);
                }
            } else {
                $firstTransaction->unzerShortId = $transaction->unzerShortId;
            }
        }

        if (!empty($transaction->unzerPaypageId)) {
            if (!empty($firstTransaction->unzerPaypageId)) {
                if ($transaction->unzerPaypageId !== $firstTransaction->unzerPaypageId) {
                    $this->log(__CLASS__, __METHOD__, 'inconsistent', 'unzerPaypageId', ['$firstTransaction' => $firstTransaction, '$transaction'=>$transaction], true);
                }
            } else {
                $firstTransaction->unzerPaypageId = $transaction->unzerPaypageId;
            }
        }
    }

}
