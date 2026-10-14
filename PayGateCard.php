<?php

namespace Paymenter\Extensions\Gateways\PayGateCard;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * PayGate.to card payments for Paymenter: Visa, Mastercard, Apple Pay, Google Pay and more on
 * PayGate.to's hosted checkout, paid out instantly in USDC (Polygon) to your own wallet.
 *
 * Install: upload the repository ZIP in Admin > Extensions > Upload Extension (or copy the files
 * to extensions/Gateways/PayGateCard/), then add the gateway under Admin > Gateways.
 */
#[ExtensionMeta(
    name: 'PayGate.to Card Payments',
    description: 'Visa, Mastercard, Apple Pay and Google Pay with instant USDC payouts to your own wallet. No KYC.',
    version: '1.0.0',
    author: 'PayGate.to',
    url: 'https://paygate.to/instant-payment-gateway/paymenter/',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgLTQgNjYgNTIiPjxnIHRyYW5zZm9ybT0icm90YXRlKC0yMCAzMyAyMSkiPjxyZWN0IHg9IjE3IiB5PSI1IiB3aWR0aD0iNDEiIGhlaWdodD0iMjYiIHJ4PSI1LjUiIGZpbGw9IiMzYTVhOTciIHN0cm9rZT0iIzBmMTcyMCIgc3Ryb2tlLXdpZHRoPSIyLjQiLz48cmVjdCB4PSIxNyIgeT0iMTEiIHdpZHRoPSI0MSIgaGVpZ2h0PSI1LjQiIGZpbGw9IiMwZjE3MjAiLz48L2c+PHJlY3QgeD0iNSIgeT0iMTUiIHdpZHRoPSI0OCIgaGVpZ2h0PSIzMCIgcng9IjYiIGZpbGw9IiM2OGM0ZGQiIHN0cm9rZT0iIzBmMTcyMCIgc3Ryb2tlLXdpZHRoPSIyLjYiLz48cmVjdCB4PSIxMiIgeT0iMjIiIHdpZHRoPSIxMSIgaGVpZ2h0PSI4LjYiIHJ4PSIyIiBmaWxsPSIjZjRiMjNlIiBzdHJva2U9IiMwZjE3MjAiIHN0cm9rZS13aWR0aD0iMS41Ii8+PHJlY3QgeD0iMTIiIHk9IjM1LjUiIHdpZHRoPSIyOCIgaGVpZ2h0PSIzLjEiIHJ4PSIxLjU1IiBmaWxsPSIjZWFmOGZjIi8+PC9zdmc+'
)]
class PayGateCard extends Gateway
{
    public const MODULE_VERSION = '1.0.0';
    private const NAME = 'PayGateCard';
    private const CALLBACK_ROUTE = 'extensions.gateways.paygatecard.callback';

    /** Native coins a card payout can arrive in => their PayGate.to coin path (priced via info.php). */
    private const NATIVE_PAYOUTS = ['polygon_pol' => 'polygon/pol', 'eth' => 'eth', 'bep20_bnb' => 'bep20/bnb'];

    public function boot()
    {
        Route::get('/extensions/gateways/paygatecard/callback', [self::class, 'callback'])->name(self::CALLBACK_ROUTE);
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'wallet',
                'label' => 'USDC (Polygon) wallet for payouts',
                'type' => 'text',
                'description' => 'A self-custodial wallet you control, starting with 0x. Every payment is forwarded to it instantly.',
                'required' => true,
            ],
            [
                'name' => 'provider',
                'label' => 'Provider (optional)',
                'type' => 'text',
                'description' => 'Leave empty for the hosted page with every provider that works for the customer (recommended), or enter one provider id such as wert or stripe.',
                'required' => false,
            ],
            [
                'name' => 'tolerance',
                'label' => 'Fees tolerance',
                'type' => 'select',
                'description' => 'Share of the invoice total (in USD) that must arrive for the invoice to be paid. A much lower amount means the customer edited the payment link.',
                'options' => ['0.60' => '60% (default)', '0.70' => '70%', '0.80' => '80%', '0.90' => '90%', '0' => 'Disabled: accept any amount'],
                'default' => '0.60',
                'required' => false,
            ],
            [
                'name' => 'api_domain',
                'label' => 'Dedicated API endpoint (optional)',
                'type' => 'text',
                'description' => 'Your free DDoS-protected endpoint, for example api-yourshop-1a2b3c.paygate.to. Empty = api.paygate.to.',
                'required' => false,
            ],
            [
                'name' => 'checkout_domain',
                'label' => 'Checkout domain (optional)',
                'type' => 'text',
                'description' => 'Your white-label checkout domain. Empty = checkout.paygate.to.',
                'required' => false,
            ],
            [
                'name' => 'theme',
                'label' => 'Checkout colour (optional)',
                'type' => 'text',
                'description' => 'Hex code or CSS colour name for the hosted page, for example #7c3aed.',
                'required' => false,
            ],
        ];
    }

    /**
     * Creates this invoice's PayGate.to wallet and returns the payment page URL (Paymenter redirects to it).
     */
    public function pay(Invoice $invoice, $total)
    {
        $wallet = trim((string) $this->config('wallet'));
        $email = (string) ($invoice->user->email ?? '');
        if ($wallet === '' || $total <= 0) {
            throw new \App\Exceptions\DisplayException(__('This payment method is not available right now.'));
        }
        if (self::isCreditTopUp($invoice)) {
            // Card providers take their fee from the payment, so the full credit would never arrive.
            throw new \App\Exceptions\DisplayException(__('Account credit cannot be added by card. Please choose another payment method.'));
        }

        $created = $this->pgRequest('GET', 'https://' . $this->pgApiHost() . '/control/wallet.php?address=' . rawurlencode($wallet) . '&callback=' . rawurlencode(self::pgCallbackUrl((int) $invoice->id)));
        if ($created === null || !isset($created['address_in']) || !is_string($created['address_in']) || $created['address_in'] === '') {
            throw new \App\Exceptions\DisplayException(__('Payment could not be started. Please try again in a moment.'));
        }

        $provider = trim((string) $this->config('provider'));
        $query = 'address=' . self::pgKeepEncoded($created['address_in'])
            . '&amount=' . rawurlencode(self::pgAmount((float) $total))
            . ($provider !== '' ? '&provider=' . rawurlencode($provider) : '')
            . '&email=' . rawurlencode($email)
            . '&currency=' . rawurlencode(strtoupper((string) $invoice->currency_code));
        if ($provider === '') {
            $theme = trim((string) $this->config('theme'));
            if ($theme !== '') {
                $query .= '&theme=' . rawurlencode($theme);
            }
            if (trim((string) $this->config('checkout_domain')) !== '') {
                $query .= '&domain=' . rawurlencode($this->pgCheckoutHost());
            }
        }

        return 'https://' . $this->pgCheckoutHost() . '/' . ($provider !== '' ? 'process-payment.php' : 'pay.php') . '?' . $query;
    }

    /**
     * PayGate.to's callback: verified, then the invoice is paid when enough arrived.
     */
    public function callback(Request $request)
    {
        [$invoice, $params, $error] = $this->pgAuthenticate($request);
        if ($error !== null) {
            return $error;
        }
        if (($params['pending'] ?? '0') === '1') {
            return self::pgOk(); // unconfirmed: the confirmed callback follows
        }

        return $this->pgLocked((int) $invoice->id, fn (Invoice $invoice) => $this->settle($invoice, $params));
    }

    private function settle(Invoice $invoice, array $params)
    {
        $txid = trim((string) $params['txid_in']);
        if (self::pgAlreadyRecorded($invoice, $txid)) {
            return self::pgOk();
        }
        $coin = strtolower((string) $params['coin']);
        $received = $params['value_coin'] . ' ' . $coin;

        if (!self::pgPaysUs($params, [(string) $this->config('wallet')])) {
            $this->pgRecordProblem($invoice, $txid, 'received ' . $received . ' but the payout did not go to the wallet configured here');

            return self::pgOk();
        }

        // Payouts arrive in USDC or USDT; some providers pay out in POL, ETH or BNB, priced in USD here.
        $receivedUsd = null;
        if (preg_match('/_(usdc|usdt)$/', $coin)) {
            $receivedUsd = (float) $params['value_coin'];
        } elseif (isset(self::NATIVE_PAYOUTS[$coin])) {
            $info = $this->pgRequest('GET', 'https://' . $this->pgApiHost() . '/crypto/' . self::NATIVE_PAYOUTS[$coin] . '/info.php');
            if ($info === null || !isset($info['prices']['USD']) || !is_numeric($info['prices']['USD'])) {
                return response('price unavailable', 503)->header('Content-Type', 'text/plain'); // PayGate.to calls again
            }
            $receivedUsd = (float) $params['value_coin'] * (float) $info['prices']['USD'];
        }
        if ($receivedUsd === null) {
            $this->pgRecordProblem($invoice, $txid, 'received ' . $received . ' on the card payment address');

            return self::pgOk();
        }
        if (!$this->pgOpen($invoice, $txid, $received)) {
            return self::pgOk();
        }

        $remaining = (float) $invoice->remaining;
        $tolerance = is_numeric($this->config('tolerance')) ? max(0.0, min(1.0, (float) $this->config('tolerance'))) : 0.60;
        if (self::isCreditTopUp($invoice)) {
            $tolerance = max($tolerance, 0.98); // account credit is paid out in full, so it must arrive in full
        }
        if ($tolerance > 0) {
            $currency = strtoupper((string) $invoice->currency_code);
            $expectedUsd = $remaining;
            if ($currency !== 'USD') {
                $conversion = $this->pgRequest('GET', 'https://' . $this->pgApiHost() . '/control/convert.php?value=' . rawurlencode(self::pgAmount($remaining)) . '&from=' . rawurlencode(strtolower($currency)));
                if ($conversion === null || !isset($conversion['value_coin']) || !is_numeric($conversion['value_coin'])) {
                    return response('conversion unavailable', 503)->header('Content-Type', 'text/plain'); // PayGate.to calls again
                }
                $expectedUsd = (float) $conversion['value_coin'];
            }
            if ($receivedUsd < $tolerance * $expectedUsd) {
                $this->pgRecordProblem($invoice, $txid, sprintf('underpaid: received %s (%s USD), %s USD expected, at least %d%% required', $received, number_format($receivedUsd, 2, '.', ''), number_format($expectedUsd, 2, '.', ''), (int) round($tolerance * 100)));

                return self::pgOk();
            }
        }

        ExtensionHelper::addPayment($invoice->id, self::NAME, $remaining, transactionId: $txid);

        return self::pgOk();
    }

    /** Invoices that add account credit (Paymenter's "Add credit"). */
    private static function isCreditTopUp(Invoice $invoice): bool
    {
        return $invoice->items()->where('reference_type', 'App\\Models\\Credit')->exists();
    }

    // ------------------------------------------------------------------
    // Shared PayGate.to helpers (identical in PayGateCard and PayGateCrypto)
    // ------------------------------------------------------------------

    private function pgApiHost(): string
    {
        return self::pgHost((string) $this->config('api_domain'), 'api.paygate.to');
    }

    private function pgCheckoutHost(): string
    {
        return self::pgHost((string) $this->config('checkout_domain'), 'checkout.paygate.to');
    }

    private static function pgHost(string $value, string $default): string
    {
        $host = strtolower(trim($value));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = rtrim(explode('/', $host, 2)[0], '.');
        if ($host === '' || !preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return $default;
        }

        return $host;
    }

    /** GET or POST to the PayGate.to API; the decoded JSON object, or null on any failure. */
    private function pgRequest(string $method, string $url, ?array $json = null): ?array
    {
        try {
            $http = Http::timeout(30)->withoutRedirecting()->acceptJson()->withUserAgent('Paymenter-PayGate/' . self::MODULE_VERSION);
            $response = $json === null ? $http->get($url) : $http->post($url, $json);
            if ($response->status() !== 200) {
                return null;
            }
            $data = json_decode($response->body(), true);
        } catch (\Throwable $e) {
            // Never log URLs: the callback URL inside them carries the invoice token.
            Log::warning('PayGate.to request to ' . parse_url($url, PHP_URL_HOST) . ' failed: ' . preg_replace('#[a-z][a-z0-9+.-]*://\S+#i', '[url]', $e->getMessage()));

            return null;
        }
        if (!is_array($data) || (isset($data['status']) && is_string($data['status']) && strtolower($data['status']) === 'error')) {
            return null;
        }

        return $data;
    }

    /** Values PayGate.to returns already percent-encoded are passed through; anything else is encoded. */
    private static function pgKeepEncoded(string $value): string
    {
        if (preg_match('/%[0-9A-Fa-f]{2}/', $value) && preg_match('/^(?:[A-Za-z0-9._~-]|%[0-9A-Fa-f]{2})+$/', $value)) {
            return $value;
        }

        return rawurlencode($value);
    }

    private static function pgAmount(float $value, int $decimals = 2): string
    {
        $text = number_format($value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** HMAC with a key derived from APP_KEY, so callback parameters cannot be changed from outside. */
    private static function pgHmac(string $data): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is not set.');
        }

        return hash_hmac('sha256', 'paygate|' . self::class . '|' . $data, $key);
    }

    /**
     * The callback route as an absolute URL. Paymenter builds it from its App URL setting (never the
     * Host header of the current request), including any subdirectory.
     */
    private static function pgCallbackBase(): string
    {
        return route(self::CALLBACK_ROUTE);
    }

    /** This invoice's callback URL, carrying the invoice id and its signature. */
    private static function pgCallbackUrl(int $invoiceId, array $extra = []): string
    {
        $url = self::pgCallbackBase() . '?invoice=' . $invoiceId . '&pg_token=' . self::pgHmac('invoice|' . $invoiceId);
        foreach ($extra as $name => $value) {
            $url .= '&' . rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return $url;
    }

    /** The undecoded query string of the request. */
    private static function pgRawQuery(Request $request): string
    {
        $uri = (string) $request->server('REQUEST_URI', '');
        if ($uri !== '') {
            $uri = explode('#', $uri, 2)[0];
            $mark = strpos($uri, '?');

            return $mark === false ? '' : substr($uri, $mark + 1);
        }

        return (string) $request->server('QUERY_STRING', '');
    }

    /** Query parameters; a repeated name keeps its last value (PayGate.to's, which comes last). */
    private static function pgParseQuery(string $raw): array
    {
        $params = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $params[urldecode($eq === false ? $pair : substr($pair, 0, $eq))] = $eq === false ? '' : urldecode(substr($pair, $eq + 1));
        }

        return $params;
    }

    /**
     * Verify PayGate.to's RSA signature over the registered callback URL followed by the raw query
     * string as received. Empty string when valid, otherwise the reason.
     */
    private static function pgVerifySignature(Request $request): string
    {
        $signature = trim((string) $request->header('X-PayGate-Signature', ''));
        if ($signature === '') {
            return 'signature_absent';
        }
        $keyId = trim((string) $request->header('X-PayGate-Key-Id', '')) ?: 'v1';
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId)) {
            return 'unknown_key_id';
        }
        $pemFile = __DIR__ . '/keys/paygate_callback_public_' . $keyId . '.pem';
        $pem = is_readable($pemFile) ? file_get_contents($pemFile) : false;
        if (!is_string($pem) || $pem === '') {
            return 'unknown_key_id';
        }
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return 'bad_public_key';
        }
        $raw = base64_decode($signature, true);
        if ($raw === false || $raw === '') {
            return 'signature_not_base64';
        }
        $query = self::pgRawQuery($request);
        $signed = $query === '' ? self::pgCallbackBase() : self::pgCallbackBase() . '?' . $query;
        $result = openssl_verify($signed, $raw, $publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1 ? '' : ($result === -1 ? 'openssl_error' : 'signature_mismatch');
    }

    /**
     * Common callback checks: [invoice, PayGate.to parameters], or a 403 response.
     *
     * @return array{0: ?Invoice, 1: array<string,string>, 2: ?\Illuminate\Http\Response}
     */
    private function pgAuthenticate(Request $request): array
    {
        $reason = self::pgVerifySignature($request);
        if ($reason !== '') {
            Log::warning(self::NAME . ' callback rejected: ' . $reason);

            return [null, [], response('invalid signature', 403)->header('Content-Type', 'text/plain')];
        }
        $params = self::pgParseQuery(self::pgRawQuery($request));
        $invoiceId = (int) ($params['invoice'] ?? 0);
        if ($invoiceId < 1 || !hash_equals(self::pgHmac('invoice|' . $invoiceId), (string) ($params['pg_token'] ?? ''))) {
            return [null, [], response('invalid invoice token', 403)->header('Content-Type', 'text/plain')];
        }
        $invoice = Invoice::find($invoiceId);
        if (!$invoice || trim((string) ($params['txid_in'] ?? '')) === '' || !isset($params['value_coin'], $params['coin'])) {
            return [null, [], response('unknown invoice or missing payment data', 404)->header('Content-Type', 'text/plain')];
        }

        return [$invoice, $params, null];
    }

    /**
     * Handle one callback per invoice at a time: PayGate.to may deliver the same callback more than
     * once, and simultaneous deliveries must not be credited twice. The invoice is loaded again
     * inside the lock so its remaining amount is current.
     */
    private function pgLocked(int $invoiceId, callable $handler)
    {
        // A row lock on the invoice, held until the payment is recorded: works on every web server
        // and cache setup, unlike a cache lock.
        return DB::transaction(function () use ($invoiceId, $handler) {
            $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->first();

            return $invoice ? $handler($invoice) : response('unknown invoice', 404)->header('Content-Type', 'text/plain');
        });
    }

    /**
     * Whether the invoice can still be paid. Payments for a paid, cancelled or otherwise closed
     * invoice are recorded for the merchant instead of being applied.
     */
    private function pgOpen(Invoice $invoice, string $txid, string $received): bool
    {
        if ((string) $invoice->status === 'pending' && (float) $invoice->remaining > 0) {
            return true;
        }
        $this->pgRecordProblem($invoice, $txid, sprintf('extra payment: the invoice is %s, so %s was not applied; refund the customer or credit their account', (string) $invoice->status, $received));

        return false;
    }

    /**
     * When PayGate.to lists the payout addresses (address_out), at least 90% of the payment must go
     * to the wallets configured here. Without this, a leaked callback URL could be registered with
     * someone else's wallet.
     *
     * @param string[] $wallets
     */
    private static function pgPaysUs(array $params, array $wallets): bool
    {
        if (!isset($params['address_out']) || trim((string) $params['address_out']) === '') {
            return true; // nothing to compare with
        }
        $shares = json_decode((string) $params['address_out'], true);
        if (!is_array($shares)) {
            return false;
        }
        $ours = array_map(static fn ($w) => strtolower(trim((string) $w)), array_filter($wallets, static fn ($w) => trim((string) $w) !== ''));
        $share = 0.0;
        foreach ($shares as $address => $value) {
            if (in_array(strtolower(trim((string) $address)), $ours, true) && is_numeric($value)) {
                $share += (float) $value;
            }
        }

        return $share >= 0.9;
    }

    /** True if this incoming blockchain transaction was already recorded for the invoice. */
    private static function pgAlreadyRecorded(Invoice $invoice, string $txid): bool
    {
        return $invoice->transactions()->where('transaction_id', $txid)->exists();
    }

    /** Record a payment that does not pay the invoice (shown on the invoice as a failed transaction). */
    private function pgRecordProblem(Invoice $invoice, string $txid, string $message): void
    {
        Log::warning(self::NAME . ' invoice #' . $invoice->id . ': ' . $message);
        ExtensionHelper::addPayment($invoice->id, self::NAME, 0, transactionId: $txid, status: InvoiceTransactionStatus::Failed);
    }

    private static function pgOk(): \Illuminate\Http\Response
    {
        return response('ok', 200)->header('Content-Type', 'text/plain');
    }
}
