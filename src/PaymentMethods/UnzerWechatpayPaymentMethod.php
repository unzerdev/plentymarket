<?php

namespace UnzerPayment\PaymentMethods;

use UnzerPayment\Constants\Constants;

class UnzerWechatpayPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = Constants::PAYMENT_METHODS['wechatpay']['short_code'];
    const UNZER_LONG_CODE =  Constants::PAYMENT_METHODS['wechatpay']['long_code'];
    const PAYMENT_METHOD_CODE =  Constants::PAYMENT_METHODS['wechatpay']['payment_method_code'];
    const PAYMENT_METHOD_NAME = Constants::PAYMENT_METHODS['wechatpay']['name'];
}
