<?php

namespace UnzerPayment\PaymentMethods;

class UnzerOpenbankingPisPaymentMethod extends AbstractUnzerPaymentMethod
{
    const UNZER_SHORT_CODE = 'obp';
    const UNZER_LONG_CODE = 'openbanking_pis';
    const PAYMENT_METHOD_CODE = 'UNZER_OPENBANKING_PIS';
    const PAYMENT_METHOD_NAME = 'Unzer Direct Bank Transfer';
    const ALLOWED_COUNTRIES = ['DE', 'AT', 'DK', 'GB', 'SE', 'NO', 'FR', 'PL'];
}
