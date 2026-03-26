<?php

namespace UnzerPayment\PaymentMethods;

use UnzerPayment\Constants\Constants;

class UnzerWeroPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['wero']['short_code'];
    const UNZER_LONG_CODE = Constants::PAYMENT_METHODS['wero']['long_code'];
    const PAYMENT_METHOD_CODE = Constants::PAYMENT_METHODS['wero']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['wero']['name'];
    const ALLOWED_COUNTRIES = [
        'DE',
    ];
}
