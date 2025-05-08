<?php

namespace UnzerPayment\PaymentMethods;

use UnzerPayment\Constants\Constants;

class UnzerPostFinanceCardPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['post_finance_card']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['post_finance_card']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['post_finance_card']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['post_finance_card']['name'];
}
