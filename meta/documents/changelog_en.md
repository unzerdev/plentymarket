# Release notes for Unzer Payments

## 1.3.0
* Support for new voucher handling logic
* Improved checkout flow
* Introduced waiting for order creation logic, to improve checkout and avoid lost sales
* Better webhook handling to avoid order 200% paid message

## 1.2.0
__This is a breaking change - remember to test and create backup before updating your LIVE environment.__
* New: Restructure checkout/order event for better order handling
* New: Booking mode for Apple Pay, Google Pay, Paypal and Credit Card
* New: Payment method list for new installs, has new design
* New: iDEAL name and logo change
* Fix: B2B Invoice UI comp. improvements
* Fix: Basket fix to use product name as Title and not ID
* Fix: Improvements for Installment and Invoice when customer is B2B
* Fix: Better support for new key pair logic

## 1.1.2
- Updated payment icons

## 1.1.1
- Correct payment handling and UI in administration interface
- Cancelled Payment Return Handling
- External ID matching
- Country restrictions for payment methods

## 1.1.0
- Upgrade to Pay Page v2

## 1.0.3
- Extended payment objects so that hash is always unique

## 1.0.2
- Added short description

## 1.0.1
- Changes according to plugin review

## 1.0.0
- First release of the plugin.

This version includes all necessary features to get you started with E-Commerce, including plentyMarkets ERP platform.
Please see User guide for detailed feature list.
