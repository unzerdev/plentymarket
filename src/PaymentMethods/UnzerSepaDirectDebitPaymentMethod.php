<?php

namespace UnzerPayment\PaymentMethods;

use UnzerPayment\Constants\Constants;

class UnzerSepaDirectDebitPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['sepa_direct_debit']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['sepa_direct_debit']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['sepa_direct_debit']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['sepa_direct_debit']['name'];
}
