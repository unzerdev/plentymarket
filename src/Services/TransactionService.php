<?php

namespace UnzerPayment\Services;


use UnzerPayment\Models\Transaction;
use UnzerPayment\Repositories\TransactionRepository;
use UnzerPayment\Traits\LoggingTrait;

class TransactionService
{
    use LoggingTrait;

    public function upsertTransaction(
        Transaction $transaction
    )
    {
        $this->log(__CLASS__, __METHOD__, 'start', '', [$transaction]);
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
        $existingTransaction->amount = $transaction->amount;
        $existingTransaction->orderId = $transaction->orderId;
        $existingTransaction->paymentId = $transaction->paymentId;
        $existingTransaction->currency = $transaction->currency;
        $existingTransaction->reference = $transaction->reference;

        $transactionRepository->saveTransaction($existingTransaction);

        $this->log(__CLASS__, __METHOD__, 'end', '', ['return' => $existingTransaction]);
        return $existingTransaction;
    }

}
