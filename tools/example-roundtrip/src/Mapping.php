<?php

declare(strict_types=1);

namespace Univapay\Compat\Tools\ExampleRoundTrip;

/**
 * THE MAPPING TABLE -- this class's `table()` array is the example round-trip harness's contract:
 * every example the extracted spec defines (every `components.examples.*` entry, plus every
 * `webhooks.*` envelope that carries an inline `example`) must have exactly one row here.
 * `Harness::run()` fails loudly (a `MAPPING-TABLE-GAP` finding, not a silent skip) if the spec
 * contains an example this table doesn't know about, or if this table names an example the spec
 * no longer has -- either direction means the table has drifted from the spec it claims to
 * describe.
 *
 * Row shape:
 *   'source'  => 'components.examples' | 'webhooks'
 *   'category'=> self::MAPPED | self::OUT_OF_SCOPE | self::UNMAPPED
 *   'parser'  => FQCN of the compat class whose ::getSchema()->parse() hydrates this payload, or
 *                null for OUT_OF_SCOPE/UNMAPPED rows
 *   'kind'    => how to feed the payload to the parser:
 *                  'single'          -- parse the whole example value directly
 *                  'list-items'      -- example value is a `{items: [...], ...}` page; parse each
 *                                       item in `items`
 *                  'raw-array-items' -- example value IS the array; parse each element (the
 *                                       `/subscriptions/simulate_plan` shape -- no envelope)
 *                  'webhook-envelope'-- example value is `{id, event, data, created_on}`; parse
 *                                       `data` only (mirrors `UnivapayClient::parseWebhookData()`)
 *   'reason'  => required for OUT_OF_SCOPE/UNMAPPED rows (why there's no parser target); an
 *                optional short note for MAPPED rows flagging an ANTICIPATED finding so the
 *                report reads as "yes, we already knew about this" rather than a surprise.
 *
 * Classification legend:
 *   MAPPED       -- a compat resource parser exists and this example SHOULD hydrate through it
 *                   (whether or not it actually does without throwing is what running the
 *                   harness determines -- see the final report's Findings section).
 *   OUT_OF_SCOPE -- the example is not response-shaped (a request-body payload, a bare 204-style
 *                   empty body, a raw scalar field with no resource class) even though the
 *                   surrounding feature IS ported in compat. Not a gap, just not a hydration
 *                   target -- excluded from mapped/unmapped coverage counts.
 *   UNMAPPED     -- the example IS response-shaped but compat has NO parser for it at all because
 *                   the whole feature is unported (Direct Debit, webhook-subscription CRUD, the
 *                   bank-transfer-specific ledger). Reported loudly, counted as a real gap.
 */
class Mapping
{
    public const MAPPED = 'MAPPED';
    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';
    public const UNMAPPED = 'UNMAPPED';

    public const NS = 'Univapay\\Compat\\Resources\\';
    public const NS_PT = 'Univapay\\Compat\\Resources\\PaymentToken\\';

    public static function table(): array
    {
        return [
            // --- Token hydration: the 9 payment-type variants ------------------------------------
            'EnableTokenThreeDsRequestExample' => [
                'source' => 'components.examples', 'category' => self::OUT_OF_SCOPE, 'parser' => null,
                'reason' => 'request payload (enable-3DS body), not a response hydration target',
            ],
            'TokenCardResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'single',
            ],
            'TokenKonbiniResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'single',
            ],
            'TokenOnlineResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'single',
            ],
            'TokenOnlineAlipayResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'single',
            ],
            'TokenOnlineHttpGetResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'single',
            ],
            'TokenBankTransferResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'single',
                'reason' => 'ANTICIPATED: TransactionToken::initSchema()\'s `data` switch has no '
                    . 'PaymentType::BANK_TRANSFER() case (verbatim old-SDK gap, verified against '
                    . 'the old SDK checkout) -- expect `data` to hydrate as null, not throw.',
            ],
            'TokenQrScanResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'single',
            ],
            'TokenQrMerchantResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'single',
            ],
            'TokenPaidyResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'single',
                'reason' => '`data.phone_number` is a plain string per the spec '
                    . '(TokenResponsePaidyData.properties.phone_number: {type: string}); '
                    . 'PaidyData accepts it untyped and passes it through unchanged rather than '
                    . 'routing it through PhoneNumber::getSchema() (a nested-object parser), so '
                    . 'this hydrates cleanly. See PaidyData.php.',
            ],

            // --- Charge --------------------------------------------------------------------------
            'ChargeResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Charge', 'kind' => 'single',
            ],
            'ChargeGetSuccessExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Charge', 'kind' => 'single',
            ],
            'ChargeListResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Charge', 'kind' => 'list-items',
            ],
            'ChargeListAllStoresResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Charge', 'kind' => 'list-items',
            ],
            'ChargeCaptureRequestExample' => [
                'source' => 'components.examples', 'category' => self::OUT_OF_SCOPE, 'parser' => null,
                'reason' => 'request payload (capture body: amount/currency), not a response',
            ],
            'ChargePatchRequestExample' => [
                'source' => 'components.examples', 'category' => self::OUT_OF_SCOPE, 'parser' => null,
                'reason' => 'request payload (metadata patch body), not a response',
            ],
            'ChargePatchResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Charge', 'kind' => 'single',
            ],
            'CaptureChargeResponseExample' => [
                'source' => 'components.examples', 'category' => self::OUT_OF_SCOPE, 'parser' => null,
                'reason' => 'empty response body (`{}`, documented as a 204-style capture ack) -- '
                    . 'Support\\ApiCaller decodes a real empty body to `true`, never routes it '
                    . 'through Charge::getSchema()->parse()',
            ],
            'GetSubscriptionLatestChargeResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Charge', 'kind' => 'single',
            ],
            'ListSubscriptionChargesResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Charge', 'kind' => 'list-items',
            ],
            'ListChargesForSubscriptionPaymentResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Charge', 'kind' => 'list-items',
            ],

            // --- Issuer tokens (two DIFFERENT compat classes behind one "issuer token" idea) -----
            // GET /stores/{storeId}/charges/{id}/issuer_token -> Charge::onlineToken(), hydrates
            // PaymentToken\OnlineToken (verified: Charge.php calls
            // OnlineToken::getSchema()->parse() at its getChargeIssuerToken() call site).
            'IssuerTokenOnlineExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS_PT . 'OnlineToken', 'kind' => 'single',
            ],
            'IssuerTokenBankTransferExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS_PT . 'OnlineToken', 'kind' => 'single',
                'reason' => 'this variant of the polymorphic issuer_token response has NO '
                    . '`call_method` field at all (account_id/branch_code/... instead); '
                    . 'OnlineToken::initSchema() marks `call_method` optional, so this hydrates '
                    . 'without it.',
            ],
            'IssuerTokenDBaraiExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS_PT . 'OnlineToken', 'kind' => 'single',
            ],
            // GET /stores/{storeId}/{charges,tokens}/{id}/three_ds/issuer_token ->
            // Charge::threeDSIssuerToken() / TransactionToken::threeDSIssuerToken(), both hydrate
            // PaymentToken\ThreeDSIssuerToken.
            'ThreeDsIssuerTokenExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS_PT . 'ThreeDSIssuerToken', 'kind' => 'single',
            ],
            'TokenThreeDsIssuerTokenExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS_PT . 'ThreeDSIssuerToken', 'kind' => 'single',
            ],

            // --- Bank-transfer-specific ledger: no compat resource at all ------------------------
            'BankTransferLedgerListResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'shape (bank_ledger_type/virtual_bank_account_*) does not match compat\'s '
                    . 'Resources\\Ledger (payout-ledger shape: percentFee/flatFee/exchangeRate/origin) '
                    . '-- this is Direct-Debit-adjacent, post-old-SDK functionality with no compat class',
            ],

            // --- Refund --------------------------------------------------------------------------
            'RefundListResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Refund', 'kind' => 'list-items',
            ],
            'CreateRefundResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Refund', 'kind' => 'single',
            ],
            'GetRefundResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Refund', 'kind' => 'single',
            ],
            'UpdateRefundResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Refund', 'kind' => 'single',
            ],

            // --- Cancel --------------------------------------------------------------------------
            'CancelListResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Cancel', 'kind' => 'list-items',
            ],
            'CreateCancelResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Cancel', 'kind' => 'single',
            ],
            'GetCancelResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Cancel', 'kind' => 'single',
            ],
            'UpdateCancelResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Cancel', 'kind' => 'single',
            ],

            // --- Merchant / Store ------------------------------------------------------------------
            'MerchantResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Merchant', 'kind' => 'single',
            ],
            'StoreResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Store', 'kind' => 'single',
            ],
            'StoreListResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Store', 'kind' => 'list-items',
            ],

            // --- create_customer_id: no resource class, and gated behind pending compat wiring ---
            'CreateCustomerIdRequestExample' => [
                'source' => 'components.examples', 'category' => self::OUT_OF_SCOPE, 'parser' => null,
                'reason' => 'request payload, not a response',
            ],
            'CreateCustomerIdResponseExample' => [
                'source' => 'components.examples', 'category' => self::OUT_OF_SCOPE, 'parser' => null,
                'reason' => 'response is a bare `{customer_id}` scalar field, never hydrated through '
                    . 'a resource class in the old SDK; also currently gated -- '
                    . 'Resources\\Store::getCustomerId() throws UnivapayUnsupportedFeatureError '
                    . 'pending compat wiring, independent of the spec',
            ],

            // --- Subscription ----------------------------------------------------------------------
            'SubscriptionCreateResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'single',
            ],
            'GetSubscriptionResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'single',
            ],
            'UpdateSubscriptionResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'single',
            ],
            'SubscriptionListResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'list-items',
            ],
            'ListStoreSubscriptionsResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'list-items',
            ],
            'SuspendSubscriptionResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'single',
            ],
            'UnsuspendSubscriptionResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'single',
            ],
            'UpdateSubscriptionTokenResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'single',
            ],

            // --- Subscription simulation: raw array response, no envelope ------------------------
            'SimulateSubscriptionPlanResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription\\ScheduledPayment', 'kind' => 'raw-array-items',
            ],
            'SimulateStoreSubscriptionPlanResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription\\ScheduledPayment', 'kind' => 'raw-array-items',
            ],
            'ListSubscriptionPaymentsResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription\\ScheduledPayment', 'kind' => 'list-items',
            ],
            'GetSubscriptionPaymentResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription\\ScheduledPayment', 'kind' => 'single',
            ],
            'UpdateSubscriptionPaymentResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription\\ScheduledPayment', 'kind' => 'single',
            ],

            // --- Webhook subscription CRUD + delivery log: entirely unported in compat ------------
            'WebhookListResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'no Webhook (subscription-config) resource class exists in compat yet -- '
                    . 'ListDispatcher::listWebhooks() exists but has zero callers',
            ],
            'CreateWebhookResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'same gap as WebhookListResponseExample',
            ],
            'WebhookEventListResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'no compat resource for webhook delivery-log entries',
            ],

            // --- TransactionToken lists --------------------------------------------------------
            'TransactionTokenListResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'list-items',
            ],
            'StoreTransactionTokenListResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'list-items',
            ],

            // --- CheckoutInfo ----------------------------------------------------------------------
            'CheckoutInfoResponseExample' => [
                'source' => 'components.examples', 'category' => self::MAPPED,
                'parser' => self::NS . 'CheckoutInfo', 'kind' => 'single',
                'reason' => '`supported_brands[*].supported_currencies` is null in the example; '
                    . 'SupportedBrand::__construct()\'s 4th param, `array $supportedCurrencies`, '
                    . 'is nullable, so this hydrates cleanly. See Configuration/SupportedBrand.php.',
            ],

            // --- Direct Debit: brand-new domain, zero compat resources (old SDK never had it) -----
            'DirectDebitConfigurationResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit has no compat resource at all -- new, post-old-SDK domain',
            ],
            'DirectDebitNotificationConfigurationResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit has no compat resource at all -- new, post-old-SDK domain',
            ],
            'DirectDebitScheduleResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit has no compat resource at all -- new, post-old-SDK domain',
            ],
            'DirectDebitBankAccountResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit has no compat resource at all (distinct from the '
                    . 'PERMANENTLY unsupported merchant-payout Resources\\BankAccount -- different '
                    . 'field set entirely; the merchant-payout BankAccount endpoints themselves '
                    . 'were removed from the spec)',
            ],
            'DirectDebitBankAccountDeactivatedResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit has no compat resource at all -- new, post-old-SDK domain',
            ],
            'DirectDebitBankAccountListResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit has no compat resource at all -- new, post-old-SDK domain',
            ],
            'DirectDebitBankTransferResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit has no compat resource at all -- new, post-old-SDK domain',
            ],
            'DirectDebitBankTransferFailedResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit has no compat resource at all -- new, post-old-SDK domain',
            ],
            'DirectDebitBankTransferListResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit has no compat resource at all -- new, post-old-SDK domain',
            ],
            'DirectDebitBankAccountNotActiveErrorExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit error envelope -- feature entirely unported in compat',
            ],
            'DirectDebitBankAccountNotInactiveErrorExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit error envelope -- feature entirely unported in compat',
            ],
            'DirectDebitBankTransferLockedErrorExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit error envelope -- feature entirely unported in compat',
            ],
            'DirectDebitInvalidAmountErrorExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit error envelope -- feature entirely unported in compat',
            ],
            'DirectDebitBankAccountValidationErrorExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'Direct Debit error envelope -- feature entirely unported in compat',
            ],

            // --- Webhook envelopes (OpenAPI 3.1 top-level `webhooks:` section, inline `example`) --
            // Parser resolution mirrors UnivapayClient::parseWebhookData()'s switch EXACTLY.
            'webhooks.chargeUpdated' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'Charge', 'kind' => 'webhook-envelope',
            ],
            'webhooks.chargeFinished' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'Charge', 'kind' => 'webhook-envelope',
            ],
            'webhooks.tokenCreated' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'webhook-envelope',
            ],
            'webhooks.tokenUpdated' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'webhook-envelope',
            ],
            'webhooks.tokenCvvAuthUpdated' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'webhook-envelope',
            ],
            'webhooks.recurringTokenDeleted' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'webhook-envelope',
            ],
            'webhooks.subscriptionPayment' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'webhook-envelope',
            ],
            'webhooks.subscriptionCompleted' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'webhook-envelope',
            ],
            'webhooks.subscriptionFailure' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'webhook-envelope',
            ],
            'webhooks.subscriptionCanceled' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'webhook-envelope',
            ],
            'webhooks.subscriptionSuspended' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'webhook-envelope',
            ],
            // The three below carry `event` VALUES that `WebhookEvent::fromValue()` cannot
            // recognize at all -- WebhookEvent (verbatim old-SDK port) never had cases for these
            // three event NAMES, because the old SDK never had these three EVENTS. Kept MAPPED
            // (the target resource -- TransactionToken/Subscription -- absolutely exists) so the
            // harness still attempts a DIRECT `data`-payload parse (bypassing
            // parseWebhookData()'s event dispatch, which would throw UnivapayUnknownWebhookEvent
            // before ever reaching the resource parser) and can still validate the `data` shape
            // itself against the resource schema, decoupled from the separate enum-coverage gap.
            'webhooks.tokenThreeDsUpdated' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'webhook-envelope',
                'reason' => 'NOTE: this used to be an ANTICIPATED FAILURE for two independent '
                    . 'reasons; (1) the trimmed-CardData gap (missing `cvv_authorize`) is now fixed '
                    . 'in the docs spec, so the resource-parser attempt below (which, per this '
                    . 'table\'s class doc, parses `data` directly and does NOT go through '
                    . 'UnivapayClient::parseWebhookData()) passes. (2) STILL OPEN, separately: the '
                    . 'wire `event` value emitted here (see spec `TokenWebhookEvent.event` enum) has '
                    . 'no corresponding Enums\\WebhookEvent case -- old SDK never had a '
                    . 'token_three_ds_updated event either, so parseWebhookData() itself would still '
                    . 'reject this with UnivapayUnknownWebhookEvent before ever reaching the resource '
                    . 'parser. That gap is decoupled from what this row tests and does not fail here.',
            ],
            'webhooks.tokenCvvAuthCheckUpdated' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'webhook-envelope',
                'reason' => 'NOTE: same resolution as webhooks.tokenThreeDsUpdated -- the '
                    . 'trimmed-CardData gap is fixed, so this row now passes. The separate, STILL '
                    . 'OPEN gap: no WebhookEvent::TOKEN_CVV_AUTH_CHECK_UPDATED() case (old SDK never '
                    . 'had one), so parseWebhookData() itself would still reject this event -- '
                    . 'decoupled from what this row tests.',
            ],
            'webhooks.tokenReplaced' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'TransactionToken', 'kind' => 'webhook-envelope',
                'reason' => 'NOTE: same resolution as webhooks.tokenThreeDsUpdated -- the '
                    . 'trimmed-CardData gap is fixed, so this row now passes. The separate, STILL '
                    . 'OPEN gap: no WebhookEvent::TOKEN_REPLACED() case (old SDK never had one), so '
                    . 'parseWebhookData() itself would still reject this event -- decoupled from '
                    . 'what this row tests.',
            ],
            'webhooks.subscriptionCreated' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'Subscription', 'kind' => 'webhook-envelope',
                'reason' => 'ANTICIPATED: no WebhookEvent::SUBSCRIPTION_CREATED() case (old SDK never '
                    . 'had one) -- same enum-coverage gap as webhooks.tokenThreeDsUpdated. (The '
                    . '`data` payload itself is a normal, full Subscription shape, so unlike the '
                    . 'token.* rows this one is expected to parse cleanly.)',
            ],
            // refundFinished/cancelFinished/bankTransferStatusUpdated/customsDeclarationFinished
            // each now carry an inline `example` in the spec. Parser resolution for the two MAPPED
            // ones below mirrors UnivapayClient::parseWebhookData()'s switch exactly
            // (REFUND_FINISHED() -> Refund, CANCEL_FINISHED() -> Cancel).
            'webhooks.refundFinished' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'Refund', 'kind' => 'webhook-envelope',
            ],
            'webhooks.cancelFinished' => [
                'source' => 'webhooks', 'category' => self::MAPPED,
                'parser' => self::NS . 'Cancel', 'kind' => 'webhook-envelope',
            ],
            // Unlike refund/cancel, parseWebhookData()'s switch has no case at all for either of
            // these two events -- $parser stays null and the call falls through to the broad
            // Throwable catch, always surfacing as UnivapayInvalidWebhookData regardless of payload
            // shape. Both are UNMAPPED here (not just "would throw via parseWebhookData") because
            // there is no compat RESOURCE CLASS either that this row could point a direct-parse
            // attempt at (mirroring how the table treats BankTransferLedgerListResponseExample).
            'webhooks.bankTransferStatusUpdated' => [
                'source' => 'webhooks', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'the `data` payload is a BankTransferStatusData shape (id/charge_id/'
                    . 'payment_status/latest_deposit_amount/balance/token_metadata/charge_metadata) '
                    . 'that matches neither Charge (requested_amount/charged_amount/status/error/'
                    . 'mode, no deposit/balance fields) nor any other compat resource -- same '
                    . 'Direct-Debit-adjacent, post-old-SDK-functionality gap as '
                    . 'BankTransferLedgerListResponseExample. UnivapayClient::parseWebhookData() has '
                    . 'no BANK_TRANSFER_STATUS_UPDATED() case either, so a real webhook of this type '
                    . 'would surface as UnivapayInvalidWebhookData end-to-end.',
            ],
            'webhooks.customsDeclarationFinished' => [
                'source' => 'webhooks', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'customs declarations have no compat resource at all (matches the '
                    . 'inline.*/stores/{storeId}/charges/{chargeId}/customs* gap already in this '
                    . 'table) -- by design, not an oversight: '
                    . 'UnivapayClient::parseWebhookData() has no CUSTOMS_DECLARATION_FINISHED() case '
                    . 'in its switch, so $parser stays null and the method throws '
                    . 'UnivapayInvalidWebhookData for every real webhook of this type, same as the '
                    . 'bank-transfer-status one above.',
            ],

            // --- Transaction history: brand-new list endpoints with no compat resource at all -----
            'TransactionHistoryListResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'TransactionHistoryItem is a new merged charge/refund ledger row shape '
                    . 'with no compat equivalent -- new, post-old-SDK domain, same category as '
                    . 'the other UNMAPPED rows in this table.',
            ],
            'StoreTransactionHistoryListResponseExample' => [
                'source' => 'components.examples', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'same gap as TransactionHistoryListResponseExample (store-scoped variant '
                    . 'of the same new resource).',
            ],

            // --- Inline (non-$ref) RESPONSE examples under ordinary `paths.*` ---------------------
            // Customs declarations (charge-level): no compat resource at all (matches the
            // documented CUSTOMS_DECLARATION_FINISHED webhook gap -- same unported domain).
            'inline.post /stores/{storeId}/charges/{chargeId}/customs 200' => [
                'source' => 'paths.inline-response', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'customs declarations have no compat resource at all -- new, '
                    . 'post-old-SDK domain (matches the CUSTOMS_DECLARATION_FINISHED webhook gap)',
            ],
            'inline.post /stores/{storeId}/charges/{chargeId}/customs 201' => [
                'source' => 'paths.inline-response', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'same gap as the 200 response for this operation',
            ],
            'inline.get /stores/{storeId}/charges/{chargeId}/customs/{id} 200' => [
                'source' => 'paths.inline-response', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'customs declarations have no compat resource at all',
            ],
            'inline.patch /stores/{storeId}/charges/{chargeId}/customs/{id} 200' => [
                'source' => 'paths.inline-response', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'customs declarations have no compat resource at all',
            ],
            // Webhook subscription CRUD (single-item get/patch + redeliver): same unported domain
            // as WebhookListResponseExample/CreateWebhookResponseExample above.
            'inline.get /stores/{storeId}/webhooks/{id} 200' => [
                'source' => 'paths.inline-response', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'no Webhook (subscription-config) resource class exists in compat yet',
            ],
            'inline.patch /stores/{storeId}/webhooks/{id} 200' => [
                'source' => 'paths.inline-response', 'category' => self::UNMAPPED, 'parser' => null,
                'reason' => 'no Webhook (subscription-config) resource class exists in compat yet',
            ],
            'inline.post /stores/{storeId}/webhooks/{id}/events/{eventId}/redeliver 202' => [
                'source' => 'paths.inline-response', 'category' => self::OUT_OF_SCOPE, 'parser' => null,
                'reason' => 'example value is `{}` (empty body ack), never routed through a resource '
                    . 'parser -- and no Webhook resource exists in compat regardless',
            ],
        ];
    }
}
