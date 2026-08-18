<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

use UnivaPay\Apis\CancelsApi;
use UnivaPay\Apis\ChargesApi;
use UnivaPay\Apis\CheckoutApi;
use UnivaPay\Apis\MerchantsApi;
use UnivaPay\Apis\RefundsApi;
use UnivaPay\Apis\StoresApi;
use UnivaPay\Apis\SubscriptionsApi;
use UnivaPay\Apis\TransactionHistoryApi;
use UnivaPay\Apis\TransactionTokensApi;
use UnivaPay\Apis\WebhooksApi;
use UnivaPay\Authentication\BearerAuthCredentialsBuilder;
use UnivaPay\UnivapayClientSdkClient;
use UnivaPay\UnivapayClientSdkClientBuilder;
use Univapay\Compat\Enums\Reason;
use Univapay\Compat\Errors\UnivapaySDKError;
use Univapay\Compat\Requests\Handlers\RequestHandler;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Authentication\StoreAppJWT;
use Univapay\Compat\UnivapayClientOptions;

/**
 * @internal
 *
 * Owns the generated-SDK client (`UnivaPay\UnivapayClientSdkClient`) that every ported compat
 * resource transports its calls through, plus everything the old SDK's `UnivapayClient` +
 * `Requests\HttpRequester` used to own directly: the JWT-derived auth context, the ported
 * request-handler cascade (Requests/Handlers/*), and the shared `ApiCaller` that runs calls
 * through it with retry-safe idempotency and raw-body capture (see its class doc).
 */
final class Bridge
{
    /** @var AppJWT */
    private $jwt;

    /** @var UnivapayClientOptions */
    private $options;

    /** @var RequestHandler[] */
    private $handlers;

    /** @var UnivapayClientSdkClient */
    private $client;

    /** @var ApiCaller */
    private $caller;

    /** @var ChargesApi|null */
    private $chargesApi;

    /** @var TransactionTokensApi|null */
    private $tokensApi;

    /** @var RefundsApi|null */
    private $refundsApi;

    /** @var SubscriptionsApi|null */
    private $subscriptionsApi;

    /** @var CancelsApi|null */
    private $cancelsApi;

    /** @var MerchantsApi|null */
    private $merchantsApi;

    /** @var StoresApi|null */
    private $storesApi;

    /** @var WebhooksApi|null */
    private $webhooksApi;

    /** @var CheckoutApi|null */
    private $checkoutApi;

    /** @var TransactionHistoryApi|null */
    private $transactionHistoryApi;

    public function __construct(AppJWT $jwt, ?UnivapayClientOptions $options = null)
    {
        $this->jwt = $jwt;
        $this->options = $options ?? new UnivapayClientOptions();
        $this->handlers = $this->options->getRequestHandlers();
        $this->caller = new ApiCaller();

        $this->client = UnivapayClientSdkClientBuilder::init()
            // ARG ORDER: the old SDK's `AppJWT::createToken($appToken, $appSecret)` stores them
            // as `->token` / `->secret` respectively; the new SDK's
            // `BearerAuthCredentialsBuilder::init(string $secretKey, string $jwtToken)` wants the
            // SECRET first. Passing them in old-SDK order here (`$jwt->token, $jwt->secret`)
            // would silently wire `Bearer {jwt}.{secret}` instead of `Bearer {secret}.{jwt}` and
            // every request would 401.
            ->bearerAuthCredentials(BearerAuthCredentialsBuilder::init($jwt->secret, $jwt->token))
            ->baseUrl($this->options->endpoint)
            // rmccue/requests (the old SDK's transport) defaults to a 10s timeout, which the old
            // SDK never exposed as configurable -- integrators have only ever experienced 10s.
            // The generated client's own default is 30s; pinning 10 here keeps that parity.
            // Retry knobs (enableRetries et al.) are deliberately left at generated defaults (off): retries
            // are handled entirely by the ported handler cascade via ApiCaller, and enabling both
            // would double-retry.
            ->timeout(10)
            ->httpCallback($this->caller->httpCallback())
            ->build();
    }

    public function jwt(): AppJWT
    {
        return $this->jwt;
    }

    /**
     * The generated-SDK client this bridge built and every compat resource transports its calls
     * through (see class doc). Exposed so `UnivapayClient::native()` (the escape hatch for
     * migrating off the compat layer) can hand consumers the SAME instance, wired with the same
     * auth/baseUrl/timeout as the compat surface, instead of constructing a second, differently
     * configured one.
     */
    public function client(): UnivapayClientSdkClient
    {
        return $this->client;
    }

    public function caller(): ApiCaller
    {
        return $this->caller;
    }

    /**
     * @return RequestHandler[] Current cascade, in `ApiCaller::call()`'s array-order convention
     *         (index 0 innermost, last element outermost).
     */
    public function handlers(): array
    {
        return $this->handlers;
    }

    /**
     * Appends $handlers as new OUTERMOST layers on top of whatever is already configured.
     * Mirrors old `UnivapayClient::addHandlers()`, which delegated straight to
     * `HttpRequester::addHandlers()` (`$this->handlers = array_merge($this->handlers, $handlers)`).
     */
    public function addHandlers(RequestHandler ...$handlers): void
    {
        $this->handlers = array_merge($this->handlers, $handlers);
    }

    /**
     * Replaces the cascade entirely with the default options handlers (innermost) followed by
     * $handlers (outermost). Mirrors old `UnivapayClient::setHandlers()`, which recomputed
     * `array_merge($this->clientOptions->getRequestHandlers(), $handlers)` and handed the whole
     * list to `HttpRequester::setHandlers()` -- a full replace, not an append, so calling this
     * repeatedly is idempotent with respect to previously-added handlers (unlike addHandlers()).
     */
    public function setHandlers(RequestHandler ...$handlers): void
    {
        $this->handlers = array_merge($this->options->getRequestHandlers(), $handlers);
    }

    /**
     * The store this client's app token was issued for, or null for a merchant-level token.
     * Old-SDK-equivalent of `UnivapayClient`'s implicit reliance on `StoreAppJWT::$storeId`.
     */
    public function storeId(): ?string
    {
        return $this->jwt instanceof StoreAppJWT ? $this->jwt->storeId : null;
    }

    /**
     * Old-SDK-equivalent of `UnivapayClient::getStoreBasedContext()`'s guard, which every
     * store-scoped client method (`createToken`, `getCheckoutInfo`, `getTransactionToken`,
     * subscription simulation, ...) called before issuing ANY request. Ported here (Support layer)
     * rather than duplicated per client method, so the client's `createCharge`/`createSubscription`
     * (which also delegate through a store-scoped token fetch), `createToken`/`getCheckoutInfo`/
     * `getTransactionToken`, and `Store` all share one implementation instead of re-deriving the
     * `instanceof StoreAppJWT` check by hand at each call site.
     *
     * @throws UnivapaySDKError If this bridge's app token is not a `StoreAppJWT` (i.e. was created
     *         from a merchant-level app token) -- identical error/message parity with old
     *         `UnivapayClient::getStoreBasedContext()`.
     */
    public function requireStoreId(): string
    {
        if (!($this->jwt instanceof StoreAppJWT)) {
            throw new UnivapaySDKError(Reason::REQUIRES_STORE_APP_TOKEN());
        }
        return $this->jwt->storeId;
    }

    /**
     * `UnivapayClientOptions::$deprecationNotices` -- read fresh on every call rather than cached,
     * so flipping it on `$clientOptions` mid-process (unusual, but nothing here forbids it) takes
     * effect immediately. See `Support\DeprecationNotifier`'s class doc.
     */
    public function deprecationNoticesEnabled(): bool
    {
        return $this->options->deprecationNotices;
    }

    /**
     * The merchant this client's app token was issued for -- present on both store- and
     * merchant-level tokens.
     */
    public function merchantId(): ?string
    {
        return $this->jwt->merchantId;
    }

    public function charges(): ChargesApi
    {
        if ($this->chargesApi === null) {
            $this->chargesApi = $this->client->getChargesApi();
        }
        return $this->chargesApi;
    }

    public function tokens(): TransactionTokensApi
    {
        if ($this->tokensApi === null) {
            $this->tokensApi = $this->client->getTransactionTokensApi();
        }
        return $this->tokensApi;
    }

    public function refunds(): RefundsApi
    {
        if ($this->refundsApi === null) {
            $this->refundsApi = $this->client->getRefundsApi();
        }
        return $this->refundsApi;
    }

    public function subscriptions(): SubscriptionsApi
    {
        if ($this->subscriptionsApi === null) {
            $this->subscriptionsApi = $this->client->getSubscriptionsApi();
        }
        return $this->subscriptionsApi;
    }

    public function cancels(): CancelsApi
    {
        if ($this->cancelsApi === null) {
            $this->cancelsApi = $this->client->getCancelsApi();
        }
        return $this->cancelsApi;
    }

    public function merchants(): MerchantsApi
    {
        if ($this->merchantsApi === null) {
            $this->merchantsApi = $this->client->getMerchantsApi();
        }
        return $this->merchantsApi;
    }

    public function stores(): StoresApi
    {
        if ($this->storesApi === null) {
            $this->storesApi = $this->client->getStoresApi();
        }
        return $this->storesApi;
    }

    public function webhooks(): WebhooksApi
    {
        if ($this->webhooksApi === null) {
            $this->webhooksApi = $this->client->getWebhooksApi();
        }
        return $this->webhooksApi;
    }

    public function checkout(): CheckoutApi
    {
        if ($this->checkoutApi === null) {
            $this->checkoutApi = $this->client->getCheckoutApi();
        }
        return $this->checkoutApi;
    }

    public function transactionHistory(): TransactionHistoryApi
    {
        if ($this->transactionHistoryApi === null) {
            $this->transactionHistoryApi = $this->client->getTransactionHistoryApi();
        }
        return $this->transactionHistoryApi;
    }
}
