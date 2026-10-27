<?php
/**
 * bKash Tokenized Checkout non-merchant gateway.
 *
 * Clients approve the payment on bKash. When bKash returns them to the callback URL, validate() executes the
 * payment with bKash and checks its status and amount before Blesta records it.
 *
 * @package blesta
 * @subpackage blesta.components.gateways.nonmerchant.bkash
 * @link https://developer.bka.sh/docs/checkout-url-process-overview bKash API Documentation
 */
class Bkash extends NonmerchantGateway
{
    /**
     * @var string The bKash sandbox API URL
     */
    private const SANDBOX_BASE_URL =
        'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout';

    /**
     * @var string The bKash live API URL
     */
    private const LIVE_BASE_URL =
        'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout';

    /**
     * @var string The table tracking each payment attempt
     */
    private const ATTEMPT_TABLE = 'bkash_payment_attempts';

    /**
     * @var int Seconds a payment attempt stays valid, matching the bKash checkout session
     */
    private const ATTEMPT_TTL_SECONDS = 1800;

    /**
     * @var int Maximum seconds to cache a bKash token, which lasts an hour
     */
    private const TOKEN_TTL_SECONDS = 3000;

    /**
     * @var int Seconds before an attempt stuck mid-payment may be retried
     */
    private const CALLBACK_RETRY_DELAY_SECONDS = 300;

    /**
     * @var array An array of meta data for this gateway
     */
    private $meta = [];

    /**
     * Construct a new non-merchant gateway.
     */
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('bkash', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Creates the payment attempts table.
     */
    public function install()
    {
        $this->createAttemptTable();
    }

    /**
     * Creates the payment attempts table if it doesn't exist yet.
     *
     * @param string $current_version The version currently installed
     */
    public function upgrade($current_version)
    {
        $this->createAttemptTable();
    }

    /**
     * Drops the payment attempts table with the last instance, since all companies share it.
     * Completed payments remain as Blesta transactions (paymentID as the transaction ID, trxID as the reference ID).
     *
     * @param int $gateway_id The ID of the gateway being uninstalled
     * @param bool $last_instance True if this is the last instance of this gateway across all companies
     */
    public function uninstall($gateway_id, $last_instance)
    {
        if ($last_instance) {
            $record = new Record();
            $record->drop(self::ATTEMPT_TABLE, true);
        }
    }

    /**
     * Sets the currency code to be used for all subsequent payments.
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = strtoupper((string) $currency);
    }

    /**
     * Sets meta data for all subsequent payments.
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta ?? [];
    }

    /**
     * Returns an array of all fields to encrypt when storing in the database.
     *
     * @return array An array of the field names to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return ['app_secret', 'password'];
    }

    /**
     * Returns the currencies bKash supports.
     *
     * @return array A list of ISO 4217 currency codes
     */
    public function getCurrencies()
    {
        return ['BDT'];
    }

    /**
     * Create and return the view content required to modify the settings of this gateway.
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView(
            'settings',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('meta', $meta ?? []);

        return $this->view->fetch();
    }

    /**
     * Validates the given meta (settings) data to be updated for this gateway.
     *
     * @param array $meta An array of meta (settings) data to be updated for this gateway
     * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure
     */
    public function editSettings(array $meta)
    {
        $rules = [];
        foreach (['app_key', 'app_secret', 'username', 'password'] as $field) {
            $rules[$field] = [
                'valid' => [
                    'rule' => function ($value) use ($field) {
                        return is_string($value) && trim($value) !== '';
                    },
                    'message' => Language::_('Bkash.!error.' . $field . '.empty', true)
                ]
            ];
        }
        // The sandbox checkbox isn't submitted when unchecked
        if (empty($meta['sandbox'])) {
            $meta['sandbox'] = 'false';
        }
        $rules['sandbox'] = [
            'valid' => [
                'rule' => ['in_array', ['true', 'false']],
                'message' => Language::_('Bkash.!error.sandbox.valid', true)
            ]
        ];

        $this->Input->setRules($rules);
        $this->Input->validates($meta);

        return $meta;
    }

    /**
     * Records a payment attempt, creates the payment at bKash and returns the button that sends the client there.
     * Invoice amounts may total less than the amount; the rest is added to the client's credit.
     *
     * @param array $contact_info Contact details for the paying client (includes 'client_id')
     * @param float|string $amount Total amount to be charged for this transaction
     * @param array|null $invoice_amounts List of individual invoice allocations to be paid
     * @param array|null $options Additional payment options (description, return_url, recur, etc.)
     * @return string Rendered HTML markup containing the checkout redirect button or error alert
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        if ($this->currency !== 'BDT') {
            return $this->errorPage('Bkash.error.currency');
        }

        // Work in integer paisa to avoid floating-point rounding
        $amount_paisa = $this->toPaisa($amount);
        $allocations = $this->normalizeInvoiceAmounts($invoice_amounts ?? []);
        if ($amount_paisa === null || $amount_paisa <= 0 || $allocations === null) {
            return $this->errorPage('Bkash.error.invalid_amount');
        }

        // Invoice amounts may not exceed the amount; the rest is added to the client's credit
        $allocation_total = 0;
        foreach ($allocations as $allocation) {
            $allocation_total += $this->toPaisa($allocation['amount']);
        }
        if ($allocation_total > $amount_paisa) {
            return $this->errorPage('Bkash.error.invalid_amount');
        }

        $this->deleteExpiredAttempts();
        try {
            $attempt = $this->createPaymentAttempt([
                'company_id' => (int) Configure::get('Blesta.company_id'),
                'gateway_id' => (int) $this->gateway_id,
                'client_id' => (int) ($contact_info['client_id'] ?? 0),
                'amount' => $this->formatPaisa($amount_paisa),
                'invoice_amounts' => $allocations,
                'merchant_invoice_number' => 'BL-' . bin2hex(random_bytes(12))
            ]);
        } catch (Throwable $e) {
            $this->log('payment-attempt', 'Could not create payment attempt.', 'output', false);
            return $this->errorPage('Bkash.error.gateway');
        }

        try {
            $callback_url = $this->buildCallbackUrl($attempt);
        } catch (Throwable $e) {
            $this->markAttemptFailed($attempt['id'], 'callback_url_unavailable');
            $this->log('payment-attempt', 'Could not construct bKash callback URL.', 'output', false);
            return $this->errorPage('Bkash.error.gateway');
        }

        $token = $this->getAccessToken();
        if (!$token) {
            $this->markAttemptFailed($attempt['id'], 'token_unavailable');
            return $this->errorPage('Bkash.error.gateway');
        }

        // Mode 0011 is a one-time payment without a stored agreement
        $response = $this->request('/create', [
            'mode' => '0011',
            'payerReference' => 'C' . $attempt['client_id'],
            'callbackURL' => $callback_url,
            'amount' => $attempt['amount'],
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $attempt['merchant_invoice_number']
        ], $token);

        // Only send the client to a bKash checkout URL
        if (!$this->isSuccessfulApiResponse($response)
            || empty($response->paymentID)
            || !$this->isAllowedCheckoutUrl($response->bkashURL ?? null)
        ) {
            $checkout_url = $response->bkashURL ?? null;
            $checkout_url_parts = is_string($checkout_url) ? parse_url($checkout_url) : false;
            $this->log('payment-attempt', json_encode([
                'status_code' => $response->statusCode ?? null,
                'payment_id_present' => !empty($response->paymentID),
                'checkout_url_allowed' => $this->isAllowedCheckoutUrl($checkout_url),
                'checkout_url_scheme' => $checkout_url_parts['scheme'] ?? null,
                'checkout_url_host' => $checkout_url_parts['host'] ?? null
            ]), 'output', false);
            $this->markAttemptFailed($attempt['id'], 'create_failed');
            return $this->errorPage('Bkash.error.gateway');
        }

        try {
            $this->bindPaymentId($attempt['id'], (string) $response->paymentID);
        } catch (Throwable $e) {
            $this->markAttemptFailed($attempt['id'], 'payment_id_storage_failed');
            $this->log('payment-attempt', 'Could not save bKash payment ID.', 'output', false);
            return $this->errorPage('Bkash.error.gateway');
        }

        $this->view = $this->makeView(
            'process',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );
        Loader::loadHelpers($this, ['Html']);
        $this->view->set('checkout_url', $response->bkashURL);

        return $this->view->fetch();
    }

    /**
     * Validates the callback bKash returns the client to. Executes the payment with bKash and verifies its status,
     * currency and amount before returning it to Blesta.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request (unused)
     * @return array|null The transaction data (see transactionResponse()), or null on failure or cancellation
     */
    public function validate(array $get, array $post)
    {
        $attempt_key = (string) ($get['attempt'] ?? '');
        $signature = (string) ($get['sig'] ?? '');
        $payment_id = (string) ($get['paymentID'] ?? '');

        if (!$this->validCallbackSignature($attempt_key, $signature)) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        $attempt = $this->getAttempt($attempt_key);
        if (!$attempt
            || (int) $attempt->company_id !== (int) Configure::get('Blesta.company_id')
            || (int) $attempt->gateway_id !== (int) $this->gateway_id
            || strtotime($attempt->expires_at . ' UTC') < time()
            || ($payment_id !== '' && !hash_equals((string) $attempt->bkash_payment_id, $payment_id))
        ) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // A repeated callback returns the recorded payment instead of executing it again
        if ($attempt->state === 'completed') {
            return $this->transactionResponse($attempt, [], 'approved');
        }
        // A paid attempt Blesta hasn't recorded may be retried once the retry delay has passed
        if ($attempt->state === 'verified') {
            if ($this->getExistingBlestaTransaction($attempt)) {
                return $this->transactionResponse($attempt, [], 'approved');
            }
            if (strtotime($attempt->verified_at . ' UTC') > time() - self::CALLBACK_RETRY_DELAY_SECONDS) {
                $this->Input->setErrors($this->getCommonError('general'));
                return null;
            }
        }
        // So may an attempt stuck mid-payment, e.g. after a timeout
        if ($attempt->state === 'executing') {
            if (strtotime($attempt->updated_at . ' UTC') > time() - self::CALLBACK_RETRY_DELAY_SECONDS) {
                $this->Input->setErrors($this->getCommonError('general'));
                return null;
            }
            $this->resetAttemptForRetry($attempt->id);
        }

        // bKash returns status=cancel or status=failure when the client cancels or the payment fails
        if (strtolower((string) ($get['status'] ?? '')) !== 'success' || $payment_id === '') {
            $this->markAttemptFailed($attempt->id, 'customer_cancelled_or_failed');
            $this->Input->setErrors([
                'general' => [
                    'invalid' => Language::_('Bkash.error.cancelled', true)
                ]
            ]);
            return null;
        }

        // Only one request may execute the payment
        if (!$this->claimAttempt($attempt->id)) {
            $attempt = $this->getAttempt($attempt_key);
            if ($attempt
                && in_array($attempt->state, ['verified', 'completed'], true)
                && $this->getExistingBlestaTransaction($attempt)
            ) {
                return $this->transactionResponse($attempt, [], 'approved');
            }
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            $this->markAttemptFailed($attempt->id, 'token_unavailable');
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        // If /execute doesn't confirm the payment (e.g. a timeout), ask bKash for its status instead
        $response = $this->request('/execute', ['paymentID' => $payment_id], $token);
        if (!$this->isCompletedPayment($response, $attempt)) {
            $response = $this->request('/payment/status', ['paymentID' => $payment_id], $token);
        }

        // Let the callback be retried after a connection failure
        if ($response === null) {
            $this->resetAttemptForRetry($attempt->id);
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        if (!$this->isCompletedPayment($response, $attempt)) {
            $this->markAttemptFailed($attempt->id, 'verification_failed');
            return $this->transactionResponse($attempt, [], 'declined');
        }

        // A bKash payment can only be credited once
        if ($this->providerTransactionExists((string) $response->trxID, $attempt->id)) {
            $this->markAttemptFailed($attempt->id, 'duplicate_provider_transaction');
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        try {
            $first_completion = $this->markAttemptVerified(
                $attempt->id,
                $payment_id,
                (string) $response->trxID
            );
        } catch (Throwable $e) {
            $this->log('payment-attempt', 'Could not finalize verified bKash payment.', 'output', false);
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }
        $attempt = $this->getAttempt($attempt_key);
        if (!$attempt) {
            $this->Input->setErrors($this->getCommonError('general'));
            return null;
        }

        return $this->transactionResponse(
            $attempt,
            $first_completion ? $this->decodeInvoiceAmounts($attempt->invoice_amounts) : [],
            'approved',
            (string) $response->trxID
        );
    }

    /**
     * Returns the data for the client's return page. Never executes a payment; it only marks a payment that Blesta
     * has recorded as completed.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request (unused)
     * @return array|null The transaction data, an empty array while the payment is unconfirmed, or null if cancelled
     */
    public function success(array $get, array $post)
    {
        $attempt_key = (string) ($get['attempt'] ?? '');
        $signature = (string) ($get['sig'] ?? '');
        if (!$this->validCallbackSignature($attempt_key, $signature)) {
            return [];
        }

        $attempt = $this->getAttempt($attempt_key);
        if (!$attempt
            || (int) $attempt->company_id !== (int) Configure::get('Blesta.company_id')
            || (int) $attempt->gateway_id !== (int) $this->gateway_id
            || !hash_equals((string) $attempt->bkash_payment_id, (string) ($get['paymentID'] ?? ''))
        ) {
            return [];
        }

        // Only show the cancel notice: validate() recorded the failure, and the status isn't signed
        if (strtolower((string) ($get['status'] ?? '')) !== 'success') {
            $this->Input->setErrors([
                'general' => [
                    'invalid' => Language::_('Bkash.error.cancelled', true)
                ]
            ]);
            return null;
        }

        // Wait until validate() has verified the payment
        if (!in_array($attempt->state, ['verified', 'completed'], true)) {
            return [];
        }

        $transaction = $this->getExistingBlestaTransaction($attempt);
        if (!$transaction
            || $transaction->status !== 'approved'
            || !$this->expectedInvoicesApplied($attempt, $transaction)
        ) {
            return [];
        }

        if ($attempt->state === 'verified') {
            $this->markAttemptCompleted($attempt->id);
            $attempt = $this->getAttempt($attempt_key);
        }

        return $this->transactionResponse($attempt, [], 'approved', (string) $attempt->bkash_trx_id);
    }

    /**
     * Creates the payment attempts table if it doesn't exist.
     * Attempt states: created -> redirected -> executing -> verified -> completed, or failed.
     */
    private function createAttemptTable()
    {
        $record = new Record();
        $record->query(
            'CREATE TABLE IF NOT EXISTS `' . self::ATTEMPT_TABLE . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `gateway_id` INT UNSIGNED NOT NULL,
                `client_id` INT UNSIGNED NOT NULL,
                `attempt_key` CHAR(64) NOT NULL,
                `bkash_payment_id` VARCHAR(100) NULL,
                `bkash_trx_id` VARCHAR(100) NULL,
                `merchant_invoice_number` VARCHAR(100) NOT NULL,
                `amount` DECIMAL(14,2) NOT NULL,
                `currency` CHAR(3) NOT NULL,
                `invoice_amounts` TEXT NOT NULL,
                `state` VARCHAR(16) NOT NULL DEFAULT \'created\',
                `failure_reason` VARCHAR(100) NULL,
                `verified_at` DATETIME NULL,
                `completed_at` DATETIME NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_bkash_attempt_key` (`attempt_key`),
                UNIQUE KEY `uq_bkash_payment_id` (`bkash_payment_id`),
                UNIQUE KEY `uq_bkash_trx_id` (`bkash_trx_id`),
                KEY `idx_bkash_attempt_state_expiry` (`state`, `expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Records a new payment attempt in the 'created' state.
     *
     * @param array $data The company_id, gateway_id, client_id, amount, invoice_amounts and merchant_invoice_number
     * @return array The attempt's id, attempt_key, client_id, amount and merchant_invoice_number
     * @throws RuntimeException If the company, gateway or client is missing
     */
    private function createPaymentAttempt(array $data)
    {
        if ($data['company_id'] <= 0 || $data['gateway_id'] <= 0 || $data['client_id'] <= 0) {
            throw new RuntimeException('The payment context is incomplete.');
        }

        $attempt_key = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d H:i:s');
        $record = new Record();
        $record->insert(self::ATTEMPT_TABLE, [
            'company_id' => $data['company_id'],
            'gateway_id' => $data['gateway_id'],
            'client_id' => $data['client_id'],
            'attempt_key' => $attempt_key,
            'merchant_invoice_number' => $data['merchant_invoice_number'],
            'amount' => $data['amount'],
            'currency' => 'BDT',
            'invoice_amounts' => json_encode($data['invoice_amounts'], JSON_THROW_ON_ERROR),
            'state' => 'created',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::ATTEMPT_TTL_SECONDS),
            'created_at' => $now,
            'updated_at' => $now
        ]);

        return [
            'id' => (int) $record->lastInsertId(),
            'attempt_key' => $attempt_key,
            'client_id' => $data['client_id'],
            'amount' => $data['amount'],
            'merchant_invoice_number' => $data['merchant_invoice_number']
        ];
    }

    /**
     * Deletes attempts that expired without being paid, e.g. a payment page that was viewed but never paid.
     * Paid attempts, attempts with a bKash trxID and attempts stuck in 'executing' are kept.
     * A failed clean-up never blocks the payment being started.
     */
    private function deleteExpiredAttempts()
    {
        try {
            $record = new Record();
            $record->from(self::ATTEMPT_TABLE)
                ->where('state', 'in', ['created', 'redirected', 'failed'])
                ->where('expires_at', '<', gmdate('Y-m-d H:i:s'))
                ->where('bkash_trx_id', '=', null)
                ->delete();
        } catch (Throwable $e) {
            $this->log('payment-attempt', 'Could not delete expired payment attempts.', 'output', false);
        }
    }

    /**
     * Stores the bKash paymentID on a 'created' attempt and moves it to 'redirected'.
     *
     * @param int $attempt_id The ID of the attempt
     * @param string $payment_id The bKash paymentID
     * @throws RuntimeException If the attempt is no longer in the 'created' state
     */
    private function bindPaymentId($attempt_id, $payment_id)
    {
        $record = new Record();
        $statement = $record->where('id', '=', $attempt_id)
            ->where('state', '=', 'created')
            ->update(self::ATTEMPT_TABLE, [
                'bkash_payment_id' => $payment_id,
                'state' => 'redirected',
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);

        if ($record->affectedRows($statement) !== 1) {
            throw new RuntimeException('The payment attempt could not be updated.');
        }
    }

    /**
     * Fetches an attempt by its key.
     *
     * @param string $attempt_key The 64-character hex attempt key
     * @return stdClass|false|null The attempt, or a falsey value if the key is invalid or unknown
     */
    private function getAttempt($attempt_key)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $attempt_key)) {
            return null;
        }
        $record = new Record();

        return $record->select()->from(self::ATTEMPT_TABLE)
            ->where('attempt_key', '=', $attempt_key)
            ->fetch();
    }

    /**
     * Atomically moves a 'redirected' or 'verified' attempt to 'executing', so only one request executes the payment.
     *
     * @param int $attempt_id The ID of the attempt
     * @return bool True if this request claimed the attempt
     */
    private function claimAttempt($attempt_id)
    {
        $record = new Record();
        $statement = $record->where('id', '=', $attempt_id)
            ->where('state', 'in', ['redirected', 'verified'])
            ->update(self::ATTEMPT_TABLE, [
                'state' => 'executing',
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);

        return $record->affectedRows($statement) === 1;
    }

    /**
     * Moves an 'executing' attempt to 'verified' and stores the bKash trxID.
     *
     * @param int $attempt_id The ID of the attempt
     * @param string $payment_id The bKash paymentID expected on the attempt
     * @param string $trx_id The bKash trxID
     * @return bool True if the attempt was updated
     */
    private function markAttemptVerified($attempt_id, $payment_id, $trx_id)
    {
        $record = new Record();
        $statement = $record->where('id', '=', $attempt_id)
            ->where('state', '=', 'executing')
            ->where('bkash_payment_id', '=', $payment_id)
            ->update(self::ATTEMPT_TABLE, [
                'bkash_trx_id' => $trx_id,
                'state' => 'verified',
                'verified_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);

        if ($record->affectedRows($statement) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Moves a 'verified' attempt to 'completed' once Blesta has applied the payment.
     *
     * @param int $attempt_id The ID of the attempt
     */
    private function markAttemptCompleted($attempt_id)
    {
        $record = new Record();
        $record->where('id', '=', $attempt_id)
            ->where('state', '=', 'verified')
            ->update(self::ATTEMPT_TABLE, [
                'state' => 'completed',
                'completed_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);
    }

    /**
     * Fetches the Blesta transaction recorded for an attempt.
     *
     * @param stdClass $attempt The attempt
     * @return stdClass|false The transaction, or false if none was recorded
     */
    private function getExistingBlestaTransaction($attempt)
    {
        Loader::loadModels($this, ['Transactions']);

        return $this->Transactions->getByTransactionId(
            (string) $attempt->bkash_payment_id,
            (int) $attempt->client_id,
            (int) $attempt->gateway_id
        );
    }

    /**
     * Checks that Blesta applied the transaction to exactly the invoice amounts stored on the attempt.
     *
     * @param stdClass $attempt The attempt
     * @param stdClass $transaction The Blesta transaction
     * @return bool True if the applied amounts match
     */
    private function expectedInvoicesApplied($attempt, $transaction)
    {
        $expected = [];
        foreach ($this->decodeInvoiceAmounts($attempt->invoice_amounts) as $invoice) {
            $paisa = $this->toPaisa($invoice['amount'] ?? null);
            if ($paisa === null) {
                return false;
            }
            $invoice_id = (int) ($invoice['id'] ?? 0);
            $expected[$invoice_id] = ($expected[$invoice_id] ?? 0) + $paisa;
        }

        $applied = [];
        foreach ($this->Transactions->getApplied($transaction->id) as $row) {
            $paisa = $this->toPaisa($row->applied_amount ?? null);
            if ($paisa === null) {
                return false;
            }
            $invoice_id = (int) $row->invoice_id;
            $applied[$invoice_id] = ($applied[$invoice_id] ?? 0) + $paisa;
        }
        ksort($expected);
        ksort($applied);

        return $expected === $applied;
    }

    /**
     * Marks an attempt failed. Paid attempts ('verified' or 'completed') are never changed, e.g. by a callback with
     * an edited status.
     *
     * @param int $attempt_id The ID of the attempt
     * @param string $reason The failure reason code, e.g. 'customer_cancelled_or_failed'
     */
    private function markAttemptFailed($attempt_id, $reason)
    {
        $record = new Record();
        $record->where('id', '=', $attempt_id)
            ->where('state', 'notin', ['verified', 'completed'])
            ->update(self::ATTEMPT_TABLE, [
                'state' => 'failed',
                'failure_reason' => substr($reason, 0, 100),
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);
    }

    /**
     * Moves an 'executing' attempt back to 'redirected' so the callback can be retried.
     *
     * @param int $attempt_id The ID of the attempt
     */
    private function resetAttemptForRetry($attempt_id)
    {
        $record = new Record();
        $record->where('id', '=', $attempt_id)
            ->where('state', '=', 'executing')
            ->update(self::ATTEMPT_TABLE, [
                'state' => 'redirected',
                'updated_at' => gmdate('Y-m-d H:i:s')
            ]);
    }

    /**
     * Checks whether a bKash trxID is already stored on another attempt, so one payment can't be credited twice.
     *
     * @param string $trx_id The bKash trxID
     * @param int $attempt_id The ID of the attempt to exclude
     * @return bool True if another attempt has the trxID
     */
    private function providerTransactionExists($trx_id, $attempt_id)
    {
        $record = new Record();
        $existing = $record->select(['id'])->from(self::ATTEMPT_TABLE)
            ->where('bkash_trx_id', '=', $trx_id)
            ->where('id', '!=', $attempt_id)
            ->fetch();

        return (bool) $existing;
    }

    /**
     * Returns a bKash token, cached until shortly before it expires.
     *
     * @return string|null The token, or null if bKash did not issue one
     */
    private function getAccessToken()
    {
        $cache = \Blesta\Core\Cache\CacheFactory::get();
        $cache_key = hash('sha256', ($this->isSandbox() ? 'sandbox:' : 'live:') . $this->metaValue('app_key'));
        $cached = $cache->read($cache_key, 'bkash-token');
        if (is_array($cached)
            && !empty($cached['token'])
            && !empty($cached['expires_at'])
            && (int) $cached['expires_at'] > time()
        ) {
            return $cached['token'];
        }

        $response = $this->request(
            '/token/grant',
            [
                'app_key' => $this->metaValue('app_key'),
                'app_secret' => $this->metaValue('app_secret')
            ],
            null,
            [
                'username: ' . $this->metaValue('username'),
                'password: ' . $this->metaValue('password')
            ]
        );
        if (!$this->isSuccessfulApiResponse($response) || empty($response->id_token)) {
            return null;
        }

        $expires_in = (int) ($response->expires_in ?? $response->expiresIn ?? self::TOKEN_TTL_SECONDS);
        $ttl = max(60, min(self::TOKEN_TTL_SECONDS, $expires_in - 60));
        $token = (string) $response->id_token;
        $cache->write($cache_key, [
            'token' => $token,
            'expires_at' => time() + $ttl
        ], $ttl, 'bkash-token');

        return $token;
    }

    /**
     * Sends a request to the bKash API and logs it with the credentials redacted.
     *
     * @param string $endpoint The API path, e.g. '/create'
     * @param array $payload The JSON body
     * @param string|null $token The bKash token, if the endpoint requires one
     * @param array $extra_headers Additional HTTP headers
     * @return stdClass|null The decoded response, or null on a connection or JSON error
     */
    private function request($endpoint, array $payload, $token = null, array $extra_headers = [])
    {
        $url = $this->baseUrl() . $endpoint;
        $headers = array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $extra_headers
        );
        if ($token !== null) {
            $headers[] = 'Authorization: ' . $token;
            $headers[] = 'X-APP-Key: ' . $this->metaValue('app_key');
        }

        // Never log credentials
        $log_payload = $payload;
        foreach (['app_secret', 'password'] as $secret_field) {
            if (isset($log_payload[$secret_field])) {
                $log_payload[$secret_field] = '[redacted]';
            }
        }
        $this->log($url, json_encode($log_payload), 'input', true);

        $curl = curl_init($url);
        if ($curl === false) {
            $this->log($url, 'Unable to initialize HTTPS request.', 'output', false);
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
        ]);
        $raw = curl_exec($curl);
        $curl_error = curl_error($curl);
        $http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($raw === false) {
            $this->log($url, 'HTTPS request failed: ' . $curl_error, 'output', false);
            return null;
        }

        $response = json_decode($raw);
        $valid_json = json_last_error() === JSON_ERROR_NONE && is_object($response);
        $log_response = [
            'http_status' => $http_code,
            'status_code' => ($response->statusCode ?? null),
            'transaction_status' => ($response->transactionStatus ?? null),
            'payment_id' => ($response->paymentID ?? null),
            'transaction_id' => ($response->trxID ?? null),
            'currency' => ($response->currency ?? null),
            'amount' => ($response->amount ?? null)
        ];
        $this->log(
            $url,
            json_encode($log_response),
            'output',
            $valid_json && $http_code >= 200 && $http_code < 300
        );

        return $valid_json ? $response : null;
    }

    /**
     * Checks whether a bKash response has the success status code '0000'.
     *
     * @param mixed $response The decoded response
     * @return bool True if the request succeeded
     */
    private function isSuccessfulApiResponse($response)
    {
        return is_object($response) && (string) ($response->statusCode ?? '') === '0000';
    }

    /**
     * Checks that bKash reports the payment completed, in BDT, with a trxID, for exactly the attempt's amount.
     *
     * @param mixed $response The decoded /execute or /payment/status response
     * @param stdClass $attempt The attempt
     * @return bool True if the payment is complete
     */
    private function isCompletedPayment($response, $attempt)
    {
        if (!$this->isSuccessfulApiResponse($response)
            || (string) ($response->transactionStatus ?? '') !== 'Completed'
            || strtoupper((string) ($response->currency ?? '')) !== 'BDT'
            || empty($response->trxID)
        ) {
            return false;
        }

        $actual_paisa = $this->toPaisa($response->amount ?? null);
        $expected_paisa = $this->toPaisa($attempt->amount);

        return $actual_paisa !== null
            && $expected_paisa !== null
            && $actual_paisa === $expected_paisa;
    }

    /**
     * Builds the transaction data returned to Blesta, with the bKash paymentID as the transaction ID and the trxID
     * as the reference ID.
     *
     * @param stdClass $attempt The attempt
     * @param array $invoices The invoices to apply the payment to
     * @param string $status The transaction status, e.g. 'approved'
     * @param string|null $trx_id The bKash trxID, defaults to the attempt's
     * @return array The transaction data
     */
    private function transactionResponse($attempt, array $invoices, $status, $trx_id = null)
    {
        return [
            'client_id' => (int) $attempt->client_id,
            'amount' => (string) $attempt->amount,
            'currency' => 'BDT',
            'status' => $status,
            'transaction_id' => (string) $attempt->bkash_payment_id,
            'reference_id' => $trx_id ?: (string) $attempt->bkash_trx_id,
            'invoices' => $invoices
        ];
    }

    /**
     * Builds the signed callback URL bKash returns the client to.
     *
     * @param array $attempt The attempt, including its attempt_key
     * @return string The callback URL
     * @throws RuntimeException If the callback URL is missing, or not a public HTTPS URL in live mode
     */
    private function buildCallbackUrl(array $attempt)
    {
        $base_url = rtrim((string) Configure::get('Blesta.gw_callback_url'), '/');
        if ($base_url === '' || (int) Configure::get('Blesta.company_id') <= 0) {
            throw new RuntimeException('The gateway callback URL is not configured.');
        }

        $callback_parts = parse_url($base_url);
        $callback_host = strtolower((string) ($callback_parts['host'] ?? ''));
        if (!$this->isSandbox()
            && (
                ($callback_parts['scheme'] ?? '') !== 'https'
                || $callback_host === ''
                || in_array($callback_host, ['localhost', '127.0.0.1', '::1'], true)
                || preg_match('/\.(localhost|local|test)$/', $callback_host)
                || filter_var($callback_host, FILTER_VALIDATE_IP)
            )
        ) {
            throw new RuntimeException('Live bKash payments require a public HTTPS callback URL.');
        }

        $callback_url = $base_url . '/' . (int) Configure::get('Blesta.company_id') . '/bkash/';
        $signature = hash_hmac(
            'sha256',
            $attempt['attempt_key'],
            hash('sha256', $this->metaValue('app_secret'), true)
        );

        return $callback_url . '?' . http_build_query([
            'attempt' => $attempt['attempt_key'],
            'sig' => $signature
        ]);
    }

    /**
     * Checks the callback URL's signature of the attempt key.
     *
     * @param string $attempt_key The attempt key
     * @param string $signature The signature
     * @return bool True if the signature is valid
     */
    private function validCallbackSignature($attempt_key, $signature)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $attempt_key)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)
        ) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $attempt_key,
            hash('sha256', $this->metaValue('app_secret'), true)
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Checks that the checkout URL from bKash is HTTPS on a bKash host, so clients are only sent to bKash.
     *
     * @param string|null $url The checkout URL
     * @return bool True if the URL is allowed
     */
    private function isAllowedCheckoutUrl($url)
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        $expected_host = parse_url($this->baseUrl(), PHP_URL_HOST);
        $allowed_hosts = [strtolower((string) $expected_host)];
        if ($this->isSandbox()) {
            $allowed_hosts[] = 'sandbox.payment.bkash.com';
        } else {
            $allowed_hosts[] = 'payment.bkash.com';
        }

        return ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), $allowed_hosts, true);
    }

    /**
     * Returns the base bKash API URL depending on current Sandbox setting.
     *
     * @return string The sandbox or production base URL
     */
    private function baseUrl()
    {
        return $this->isSandbox() ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;
    }

    /**
     * Determines whether the gateway is running in Sandbox (test) mode.
     *
     * @return bool True if sandbox mode is active, false for live production
     */
    private function isSandbox()
    {
        return $this->metaValue('sandbox') === 'true';
    }

    /**
     * Safely reads a string value from the gateway metadata settings.
     *
     * @param string $key The configuration key to look up
     * @return string The configuration value, or empty string if not set
     */
    private function metaValue($key)
    {
        return isset($this->meta[$key]) && is_scalar($this->meta[$key])
            ? (string) $this->meta[$key]
            : '';
    }

    /**
     * Converts invoice amounts to a list of ['id' => int, 'amount' => '0.00'], skipping invoices with nothing to pay,
     * e.g. already covered by the client's credit.
     *
     * @param array $invoice_amounts Invoice amounts as [id => amount], or a list of ['id' or 'invoice_id', 'amount']
     * @return array|null The invoice amounts, or null if any is invalid
     */
    private function normalizeInvoiceAmounts(array $invoice_amounts)
    {
        $normalized = [];
        foreach ($invoice_amounts as $invoice_id => $allocation) {
            if (is_array($allocation)) {
                $id = $allocation['id'] ?? $allocation['invoice_id'] ?? null;
                $amount = $allocation['amount'] ?? null;
            } else {
                $id = $invoice_id;
                $amount = $allocation;
            }
            if (!is_numeric($id) || (int) $id <= 0 || $this->toPaisa($amount) === null) {
                return null;
            }
            if ($this->toPaisa($amount) === 0) {
                continue;
            }
            $normalized[] = [
                'id' => (int) $id,
                'amount' => $this->formatPaisa($this->toPaisa($amount))
            ];
        }

        return $normalized;
    }

    /**
     * Decodes a JSON-encoded invoice_amounts string from the database.
     *
     * @param string|null $invoice_amounts JSON string from bkash_payment_attempts table
     * @return array Parsed invoice array, or empty array on failure
     */
    private function decodeInvoiceAmounts($invoice_amounts)
    {
        $decoded = json_decode((string) $invoice_amounts, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Converts an amount to integer paisa without floating-point rounding.
     *
     * @param mixed $amount The amount, e.g. '150.50'
     * @return int|null The amount in paisa, or null unless it is a non-negative amount with at most 2 decimal places
     */
    private function toPaisa($amount)
    {
        if (!is_numeric($amount) || !preg_match('/^\d+(?:\.\d+)?$/', (string) $amount)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');
        if (strlen($whole) > 12 || trim(substr($fraction, 2), '0') !== '') {
            return null;
        }

        return ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    /**
     * Formats integer paisa as an amount with 2 decimal places, e.g. 15050 as '150.50'.
     *
     * @param int $amount The amount in paisa
     * @return string The amount
     */
    private function formatPaisa($amount)
    {
        $amount = (int) $amount;

        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Renders a client-facing error view with a localized error message.
     *
     * @param string $language_key Language definition key (e.g. 'Bkash.error.gateway')
     * @return string Rendered error HTML markup
     */
    private function errorPage($language_key)
    {
        $this->view = $this->makeView(
            'process',
            'default',
            str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)
        );
        Loader::loadHelpers($this, ['Html']);
        $this->view->set('error_message', Language::_($language_key, true));

        return $this->view->fetch();
    }
}
