<?php

namespace UnzerPayment\PaymentMethods;

use UnzerPayment\Constants\Constants;

class UnzerKlarnaPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['klarna']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['klarna']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['klarna']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['klarna']['name'];
}
