# bKash Gateway

This is a nonmerchant gateway for Blesta that integrates with [bKash](https://www.bkash.com/) Tokenized Checkout, so
clients can pay BDT invoices from their bKash account.

## Install the Gateway

1. You can install the gateway via composer:

    ```
    composer require blesta/bkash
    ```

2. OR upload the source code to a /components/gateways/nonmerchant/bkash/ directory within
your Blesta installation path.

    For example:

    ```
    /var/www/html/blesta/components/gateways/nonmerchant/bkash/
    ```

3. Log in to your admin Blesta account and navigate to
> Settings > Payment Gateways

4. Find the bKash gateway and click the "Install" button to install it

5. You're done!

## Configure the Gateway

Enter the App Key, App Secret, API Username and API Password from your bKash merchant account, and enable the gateway
for BDT, the only currency bKash supports.

- Check "Enable Sandbox (Test Mode)" to test with bKash sandbox credentials. See the
  [bKash developer documentation](https://developer.bka.sh/docs/checkout-url-process-overview).
- Live payments need Live credentials, and Blesta must be reachable at a public HTTPS address, because bKash returns
  clients to Blesta's gateway callback URL.

To refund a payment, edit the transaction in Blesta, set its status to "Refunded" and check "Process this status
change with the payment gateway". bKash refunds payments up to 60 days old.

If a payment can't be started, confirmed or refunded, the bKash API responses are under Tools > Logs > Gateway.

### Blesta Compatibility

|Blesta Version|Gateway Version|
|--------------|--------------|
|>= v6.0.0|v1.2.0+|
