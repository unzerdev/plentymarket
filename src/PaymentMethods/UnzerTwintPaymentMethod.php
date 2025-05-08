<?php

namespace UnzerPayment\PaymentMethods;

use UnzerPayment\Constants\Constants;

class UnzerTwintPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['twint']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['twint']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['twint']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['twint']['name'];
}
