<?php

namespace UnzerPayment\PaymentMethods;

use UnzerPayment\Constants\Constants;

class UnzerAlipayPaymentMethod extends AbstractUnzerPaymentMethod
{   const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['alipay']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['alipay']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['alipay']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['alipay']['name'];
}
