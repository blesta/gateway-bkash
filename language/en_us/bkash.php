<?php
// Gateway name and description
$lang['Bkash.name'] = 'bKash';
$lang['Bkash.description'] = 'Pay BDT invoices using bKash Tokenized Checkout.';

// Errors
$lang['Bkash.!error.app_key.empty'] = 'Enter the bKash App Key.';
$lang['Bkash.!error.app_secret.empty'] = 'Enter the bKash App Secret.';
$lang['Bkash.!error.username.empty'] = 'Enter the bKash API username.';
$lang['Bkash.!error.password.empty'] = 'Enter the bKash API password.';
$lang['Bkash.!error.sandbox.valid'] = 'Sandbox mode must be either enabled or disabled.';
$lang['Bkash.!error.refund'] = 'bKash could not refund the payment: %1$s'; // %1$s is the error message from bKash

// Settings
$lang['Bkash.app_key'] = 'App Key';
$lang['Bkash.app_secret'] = 'App Secret';
$lang['Bkash.username'] = 'API Username';
$lang['Bkash.password'] = 'API Password';
$lang['Bkash.sandbox'] = 'Enable Sandbox (Test Mode)';
$lang['Bkash.settings.mode_note'] = 'Uncheck to accept live payments, which require Live API credentials and a public HTTPS callback URL.';

// Process
$lang['Bkash.buildprocess.submit'] = 'Continue to bKash';
$lang['Bkash.error.gateway'] = 'We could not start or verify the bKash payment. Please try again or contact support.';
$lang['Bkash.error.currency'] = 'bKash payments are available only for BDT invoices.';
$lang['Bkash.error.invalid_amount'] = 'The invoice payment amount is invalid.';
$lang['Bkash.error.cancelled'] = 'Payment was cancelled. No charges were made to your account. Your invoice remains unpaid—please try again or choose another payment method.';

// Refund
$lang['Bkash.refund.reason'] = 'Refund';
