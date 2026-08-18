<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Support;

use DateInterval;
use DateTime;
use DateTimeZone;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\CallMethod;
use Univapay\Compat\Enums\ConvenienceStore;
use Univapay\Compat\Enums\Field;
use Univapay\Compat\Enums\InstallmentPlanType;
use Univapay\Compat\Enums\OnlineBrand;
use Univapay\Compat\Enums\Period;
use Univapay\Compat\Enums\QrBrandMerchant;
use Univapay\Compat\Enums\Reason;
use Univapay\Compat\Enums\RefundReason;
use Univapay\Compat\Enums\SubscriptionPlanType;
use Univapay\Compat\Enums\SubscriptionStatus;
use Univapay\Compat\Enums\ThreeDSMode;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\PaymentData\Address;
use Univapay\Compat\Resources\PaymentData\ConvenienceStoreData;
use Univapay\Compat\Resources\PaymentData\CvvAuthorize;
use Univapay\Compat\Resources\PaymentData\PaidyData;
use Univapay\Compat\Resources\PaymentData\PhoneNumber;
use Univapay\Compat\Resources\PaymentData\TokenThreeDS;
use Univapay\Compat\Resources\PaymentMethod\ApplePayPayment;
use Univapay\Compat\Resources\PaymentMethod\CardPayment;
use Univapay\Compat\Resources\PaymentMethod\CardPaymentPatch;
use Univapay\Compat\Resources\PaymentMethod\ConvenienceStorePayment;
use Univapay\Compat\Resources\PaymentMethod\OnlinePayment;
use Univapay\Compat\Resources\PaymentMethod\PaidyPayment;
use Univapay\Compat\Resources\PaymentMethod\PaymentMethodPatch;
use Univapay\Compat\Resources\PaymentMethod\QrMerchantPayment;
use Univapay\Compat\Resources\PaymentMethod\QrScanPayment;
use Univapay\Compat\Resources\PaymentThreeDS;
use Univapay\Compat\Resources\Redirect;
use Univapay\Compat\Resources\Subscription\InstallmentPlan;
use Univapay\Compat\Resources\Subscription\ScheduleSettings;
use Univapay\Compat\Resources\Subscription\SubscriptionPlan;
use Univapay\Compat\Support\RequestModelFactory;
use Univapay\Compat\Tests\Support\WireParity;

/**
 * Wire-parity oracle tests (plan: "Wire-parity oracle redefined") for
 * `Support\RequestModelFactory`: for each payment method / argument shape this factory builds a
 * new-SDK request model for, assert `json_encode(newModel->jsonSerialize())` is JSON-semantically
 * equal (see `Tests\Support\WireParity`) to the corresponding ported old-SDK class's own
 * `jsonSerialize()` -- the old classes ARE the wire-truth oracle here, verbatim-ported.
 */
class RequestModelFactoryTest extends TestCase
{
    // --- tokenCreate() ------------------------------------------------------------------------

    public function testTokenCreateMinimalCardPayment()
    {
        $card = new CardPayment('user@example.com', 'John Doe', '4242424242424242', 12, 2030, '123');

        $request = RequestModelFactory::tokenCreate($card);

        WireParity::assertEquivalent($card->jsonSerialize(), $request->jsonSerialize());
    }

    public function testTokenCreateFullCardPaymentWithAddressPhoneCvvAuthorizeAndThreeDs()
    {
        $card = new CardPayment(
            'user@example.com',
            'John Doe',
            '4242424242424242',
            '12',
            '2030',
            '123',
            TokenType::RECURRING(),
            null,
            new Address('1 Main St', 'Suite 2', 'Tokyo', 'Chiyoda', 'JP', '100-0001'),
            new PhoneNumber('+81', '9012345678'),
            ['order_id' => 'order-1'],
            new CvvAuthorize(true, new Currency('JPY')),
            '203.0.113.1',
            new TokenThreeDS(true, 'https://example.com/return')
        );

        $request = RequestModelFactory::tokenCreate($card);

        WireParity::assertEquivalent($card->jsonSerialize(), $request->jsonSerialize());
    }

    public function testTokenCreateConvenienceStorePayment()
    {
        $data = new ConvenienceStoreData(
            'Taro Yamada',
            new PhoneNumber(PhoneNumber::JP, '9012345678'),
            ConvenienceStore::FAMILY_MART(),
            new DateInterval('P10D')
        );
        $payment = new ConvenienceStorePayment('user@example.com', $data);

        $request = RequestModelFactory::tokenCreate($payment);

        WireParity::assertEquivalent($payment->jsonSerialize(), $request->jsonSerialize());
    }

    public function testTokenCreateOnlinePayment()
    {
        $payment = new OnlinePayment(
            'user@example.com',
            OnlineBrand::WE_CHAT_ONLINE(),
            null,
            null,
            CallMethod::WEB()
        );

        $request = RequestModelFactory::tokenCreate($payment);

        WireParity::assertEquivalent($payment->jsonSerialize(), $request->jsonSerialize());
    }

    public function testTokenCreateOnlinePaymentWithoutCallMethodThrows()
    {
        $payment = new OnlinePayment('user@example.com', OnlineBrand::WE_CHAT_ONLINE());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        RequestModelFactory::tokenCreate($payment);
    }

    public function testTokenCreateOnlinePaymentWithUncoveredBrandThrows()
    {
        // The generated BaseOnlineDataBrand enum covers GCASH, DANA, KAKAOPAY, BOOST,
        // TOUCH_N_GO/tng, and others -- OnlineBrand::EASYPAISA remains one of the still-uncovered
        // old brands (coverage gap, see RequestModelFactory::buildOnlineData()).
        $payment = new OnlinePayment('user@example.com', OnlineBrand::EASYPAISA(), null, null, CallMethod::WEB());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        RequestModelFactory::tokenCreate($payment);
    }

    public function testTokenCreateQrScanPayment()
    {
        $payment = new QrScanPayment('user@example.com', 'scanned-qr-data');

        $request = RequestModelFactory::tokenCreate($payment);

        WireParity::assertEquivalent($payment->jsonSerialize(), $request->jsonSerialize());
    }

    public function testTokenCreateQrMerchantPayment()
    {
        $payment = new QrMerchantPayment(
            'user@example.com',
            QrBrandMerchant::PAY_PAY_MERCHANT()
        );

        $request = RequestModelFactory::tokenCreate($payment);

        // WireParity's existing case-insensitive 'brand' comparison covers the
        // getName()-vs-getValue() case-fold automatically.
        WireParity::assertEquivalent($payment->jsonSerialize(), $request->jsonSerialize());
    }

    /**
     * Coverage gap (documented, RequestModelFactory::buildQrMerchantData()'s class doc): brands
     * with an EXPLICIT value override (`TOUCH_N_GO()` -> `tng`, `PUBLICBANK()` -> `pbengagemy`)
     * do not satisfy `strtolower(getName()) === getValue()` -- this factory sends the lowercased
     * NAME (`touch_n_go`), not the enum's own declared wire value (`tng`), reproducing
     * `QrMerchantPayment::jsonSerialize()`'s own pre-existing `->getName()` choice exactly. A
     * known spec-backlog wire-parity gap, not silently fixed here.
     */
    public function testTokenCreateQrMerchantPaymentWithAnOverriddenBrandSendsTheLowercasedNameNotTheDeclaredValue()
    {
        $payment = new QrMerchantPayment('user@example.com', QrBrandMerchant::TOUCH_N_GO());

        $request = RequestModelFactory::tokenCreate($payment);

        $this->assertSame('touch_n_go', $request->getData()->getBrand());
        $this->assertNotSame(QrBrandMerchant::TOUCH_N_GO()->getValue(), $request->getData()->getBrand());
    }

    public function testTokenCreatePaidyPaymentWithoutPhoneNumber()
    {
        $payment = new PaidyPayment(
            new PaidyData('paidy-token', new Address(null, null, null, null, null, '100-0001'))
        );

        $request = RequestModelFactory::tokenCreate($payment);

        WireParity::assertEquivalent($payment->jsonSerialize(), $request->jsonSerialize());
    }

    public function testTokenCreatePaidyPaymentWithShippingAddressLines()
    {
        $payment = new PaidyPayment(
            new PaidyData('paidy-token', new Address('1 Main St', null, 'Tokyo', 'Chiyoda', null, '100-0001'))
        );

        $request = RequestModelFactory::tokenCreate($payment);

        WireParity::assertEquivalent($payment->jsonSerialize(), $request->jsonSerialize());
        $this->assertSame('1 Main St', $request->getData()->getShippingAddress()->getLine1());
        $this->assertSame('Chiyoda', $request->getData()->getShippingAddress()->getCity());
    }

    /**
     * Documented, intentional delta (RequestModelFactory::buildPaidyData()'s class doc): the old
     * nested-object `PhoneNumber` shape ({country_code, local_number}) collapses to its
     * `local_number` alone -- the generated `TokenCreatePaidyData::setPhoneNumber()` only accepts
     * a plain string, and `PaidyPayment`'s own constructor guard already rejected any non-JP
     * country code before this factory is ever reached. NOT a WireParity case (the two shapes are
     * genuinely different, not a normalization delta) -- asserted directly instead.
     */
    public function testTokenCreatePaidyPaymentCollapsesAPhoneNumberObjectToItsLocalNumberString()
    {
        $payment = new PaidyPayment(
            new PaidyData(
                'paidy-token',
                new Address(null, null, null, null, null, '100-0001'),
                new PhoneNumber(PhoneNumber::JP, '08012341234')
            )
        );

        $request = RequestModelFactory::tokenCreate($payment);

        $this->assertSame('08012341234', $request->getData()->getPhoneNumber());
    }

    /**
     * `RequestModelFactory::buildPaidyData()`'s `is_array($phoneNumber) ? ... : (string) ...`
     * branch defensively handles a plain-string `$data['phone_number']` too, but that shape is
     * NOT reachable through `PaidyPayment`'s own constructor: its guard
     * (`$paidyData->phoneNumber->countryCode`) dereferences `->countryCode` unconditionally once
     * `isset($paidyData->phoneNumber)` is true, which -- for a plain string -- reads a property
     * off a string (PHP: warning, evaluates to `null`), making `null != PhoneNumber::JP` true and
     * throwing `UnivapayValidationError` before this factory is ever reached. Exercised directly
     * against the factory instead, bypassing the guard, to document that the defensive branch
     * still does the right thing if some future caller ever reaches it another way (e.g. a
     * response-hydration path round-tripping a response-hydrated `PaidyData` back out).
     */
    public function testBuildPaidyDataPassesThroughAPlainStringPhoneNumberUnchanged()
    {
        $paidyData = new PaidyData('paidy-token', new Address(null, null, null, null, null, '100-0001'));
        // Bypass PaidyPayment's constructor guard entirely -- see method doc. PaidyData::
        // jsonSerialize() itself IS the wire `data` object (not nested under its own 'data' key).
        $paidyData->phoneNumber = '08012341234';
        $data = $paidyData->jsonSerialize();

        $method = new \ReflectionMethod(RequestModelFactory::class, 'buildPaidyData');
        $method->setAccessible(true);
        $request = $method->invoke(null, $data);

        $this->assertSame('08012341234', $request->getPhoneNumber());
    }

    public function testTokenCreateApplePayPaymentThrows()
    {
        $payment = new ApplePayPayment('user@example.com', 'John Doe', 'apple-pay-token');

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        RequestModelFactory::tokenCreate($payment);
    }

    public function testTokenCreateWithLocalCustomerIdThrows()
    {
        $card = new CardPayment('user@example.com', 'John Doe', '4242424242424242', 12, 2030, '123');

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        RequestModelFactory::tokenCreate($card, 'local-customer-1');
    }

    // --- chargeCreate() -------------------------------------------------------------------------

    public function testChargeCreateOmitsCaptureKeyWhenCaptureIsNull()
    {
        $request = RequestModelFactory::chargeCreate('token-1', new Money(1000, new Currency('JPY')));

        $serialized = $request->jsonSerialize();

        $this->assertArrayNotHasKey('capture', $serialized);
        $this->assertSame(1000, $serialized['amount']);
        $this->assertSame('JPY', $serialized['currency']);
    }

    public function testChargeCreateSendsExplicitCaptureFalse()
    {
        $request = RequestModelFactory::chargeCreate('token-1', new Money(1000, new Currency('JPY')), false);

        $this->assertFalse($request->jsonSerialize()['capture']);
    }

    public function testChargeCreateCaptureAtDoesNotMutateCallersDateTime()
    {
        $captureAt = new DateTime('2030-01-01 10:00:00', new DateTimeZone('Asia/Tokyo'));
        $originalTimezoneName = $captureAt->getTimezone()->getName();

        $request = RequestModelFactory::chargeCreate('token-1', new Money(1000, new Currency('JPY')), true, $captureAt);

        // DateTimeHelper mutation warning (plan): the caller's own DateTime must be untouched.
        $this->assertSame($originalTimezoneName, $captureAt->getTimezone()->getName());
        $this->assertSame('2030-01-01T10:00:00', $captureAt->format('Y-m-d\TH:i:s'));

        // But the wire value is UTC-normalized (allowed delta -- compared as the same instant).
        // Third arg forces the comparer's 'capture_at' branch (instant comparison, not string
        // equality) -- see WireParity's class doc.
        WireParity::assertEquivalent(
            $captureAt->format(DateTime::ATOM),
            $request->jsonSerialize()['capture_at'],
            'capture_at'
        );
    }

    public function testChargeCreateWithAllScalarMetadataBuildsTypedMetadata()
    {
        $request = RequestModelFactory::chargeCreate(
            'token-1',
            new Money(1000, new Currency('JPY')),
            null,
            null,
            ['order_id' => 'order-42', 'a-custom-key' => 'value']
        );

        $metadata = $request->jsonSerialize()['metadata'];
        $this->assertInstanceOf(\UnivaPay\Models\GenericMetadata::class, $metadata);
        $this->assertSame('order-42', $metadata->getOrderId());
        $this->assertSame('value', $metadata->findAdditionalProperty('a-custom-key'));
    }

    /**
     * `GenericMetadata::addAdditionalProperty()` accepts `anyOf(string,float,bool,array[])` --
     * an array-valued metadata entry builds the TYPED `GenericMetadata` (via
     * `addAdditionalProperty()`) instead of falling back to raw passthrough (see
     * `RequestModelFactory::isMetadataTypeCompatible()`'s class doc).
     */
    public function testChargeCreateWithArrayValuedMetadataNowBuildsTypedMetadataSinceS13()
    {
        $nested = ['order_id' => 'order-42', 'nested' => ['a' => 1, 'b' => [2, 3]]];

        $request = RequestModelFactory::chargeCreate(
            'token-1',
            new Money(1000, new Currency('JPY')),
            null,
            null,
            $nested
        );

        $metadata = $request->jsonSerialize()['metadata'];
        $this->assertInstanceOf(\UnivaPay\Models\GenericMetadata::class, $metadata);
        $this->assertSame('order-42', $metadata->getOrderId());
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $metadata->findAdditionalProperty('nested'));
    }

    /**
     * Genuine remaining gap: a metadata value that is neither a scalar
     * NOR an array at all (e.g. an object) still cannot be expressed by the generated
     * `GenericMetadata` model -- still falls back to raw passthrough on the root request.
     */
    public function testChargeCreateWithATrulyIncompatibleMetadataValueStillPassesThroughRaw()
    {
        $withObject = ['order_id' => 'order-42', 'not_expressible' => new DateTime('2030-01-01')];

        $request = RequestModelFactory::chargeCreate(
            'token-1',
            new Money(1000, new Currency('JPY')),
            null,
            null,
            $withObject
        );

        $this->assertSame($withObject, $request->jsonSerialize()['metadata']);
    }

    /**
     * `TransactionTokenCreateRequestMetadata` (token creation) is still
     * `oneOf(string,bool,float)` -- so an array-valued metadata entry here still falls back to raw
     * passthrough, unlike the GenericMetadata-backed builders above.
     */
    public function testTokenCreateWithArrayValuedMetadataStillPassesThroughRawNotWidenedByS13()
    {
        $card = new CardPayment('user@example.com', 'John Doe', '4242424242424242', 12, 2030, '123');
        $card->metadata = ['univapay-name' => 'Taro', 'nested' => ['a' => 1]];

        $request = RequestModelFactory::tokenCreate($card);

        $this->assertSame($card->metadata, $request->jsonSerialize()['metadata']);
    }

    public function testChargeCreateWithRedirect()
    {
        $redirect = new Redirect('https://example.com/redirect', 'ignored-response-only-field');

        $request = RequestModelFactory::chargeCreate(
            'token-1',
            new Money(1000, new Currency('JPY')),
            null,
            null,
            null,
            null,
            $redirect
        );

        WireParity::assertEquivalent(
            $redirect->jsonSerialize(),
            $request->jsonSerialize()['redirect']->jsonSerialize()
        );
    }

    public function testChargeCreateWithThreeDsRedirectPassesThroughRawArray()
    {
        $threeDS = PaymentThreeDS::withThreeDS('https://example.com/3ds-return', ThreeDSMode::IF_AVAILABLE());

        $request = RequestModelFactory::chargeCreate(
            'token-1',
            new Money(1000, new Currency('JPY')),
            null,
            null,
            null,
            null,
            null,
            $threeDS
        );

        // BLOCKER 3: must be the RAW array (old wire truth), never a ChargeCreateRequestThreeDs
        // instance -- that generated model can't express IF_AVAILABLE and would force-emit
        // mode:normal.
        $this->assertSame($threeDS->jsonSerialize(), $request->jsonSerialize()['three_ds']);
        $this->assertSame('if_available', $request->jsonSerialize()['three_ds']['mode']);
    }

    public function testChargeCreateWithThreeDsMpiPassesThroughRawArray()
    {
        $threeDS = PaymentThreeDS::withThreeDSMPI(
            str_repeat('a', 28),
            '05',
            'ds-transaction-id',
            'server-transaction-id',
            '2.1.0',
            'Y'
        );

        $request = RequestModelFactory::chargeCreate(
            'token-1',
            new Money(1000, new Currency('JPY')),
            null,
            null,
            null,
            null,
            null,
            $threeDS
        );

        WireParity::assertEquivalent($threeDS->jsonSerialize(), $request->jsonSerialize()['three_ds']);
        $this->assertArrayHasKey('authentication_value', $request->jsonSerialize()['three_ds']);
    }

    public function testChargeCreateOnlyDirectCurrency()
    {
        $request = RequestModelFactory::chargeCreate(
            'token-1',
            new Money(1000, new Currency('JPY')),
            null,
            null,
            null,
            true
        );

        $this->assertTrue($request->findAdditionalProperty('only_direct_currency'));
    }

    // --- refundCreate() -------------------------------------------------------------------------

    public function testRefundCreate()
    {
        $request = RequestModelFactory::refundCreate(
            new Money(500, new Currency('JPY')),
            RefundReason::CUSTOMER_REQUEST(),
            'customer asked',
            ['order_id' => 'order-1']
        );

        $serialized = $request->jsonSerialize();
        $this->assertSame(500, $serialized['amount']);
        $this->assertSame('JPY', $serialized['currency']);
        $this->assertSame('customer_request', $serialized['reason']);
        $this->assertSame('customer asked', $serialized['message']);
    }

    public function testRefundCreateRejectsChargebackReason()
    {
        $this->expectException(UnivapayValidationError::class);

        RequestModelFactory::refundCreate(new Money(500, new Currency('JPY')), RefundReason::CHARGEBACK());
    }

    // --- chargeCapture() ------------------------------------------------------------------------

    public function testChargeCapture()
    {
        $request = RequestModelFactory::chargeCapture(new Money(750, new Currency('JPY')));

        $serialized = $request->jsonSerialize();
        $this->assertSame(750, $serialized['amount']);
        $this->assertSame('JPY', $serialized['currency']);
    }

    // --- subscriptionCreate() -------------------------------------------------------------------

    public function testSubscriptionCreateMinimal()
    {
        $request = RequestModelFactory::subscriptionCreate(
            'token-1',
            new Money(1000, new Currency('JPY')),
            Period::MONTHLY()
        );

        $serialized = $request->jsonSerialize();
        $this->assertSame('token-1', $serialized['transaction_token_id']);
        $this->assertSame(1000, $serialized['amount']);
        $this->assertSame('JPY', $serialized['currency']);
        $this->assertSame('monthly', $serialized['period']);
        // Forced-default suppression: no old-SDK-equivalent key should leak onto the wire.
        $this->assertArrayNotHasKey('first_charge_authorization_only', $serialized);
    }

    public function testSubscriptionCreateWithScheduleAndPlans()
    {
        $scheduleSettings = new ScheduleSettings(
            new DateTime('+1 month'),
            new DateTimeZone('Asia/Tokyo'),
            false,
            new DateInterval('P5D')
        );
        $subscriptionPlan = new SubscriptionPlan(SubscriptionPlanType::FIXED_CYCLES(), 5);
        $installmentPlan = new InstallmentPlan(InstallmentPlanType::FIXED_CYCLES(), 10);

        $request = RequestModelFactory::subscriptionCreate(
            'token-1',
            new Money(1000, new Currency('JPY')),
            Period::MONTHLY(),
            new Money(500, new Currency('JPY')),
            $scheduleSettings,
            $subscriptionPlan,
            $installmentPlan,
            ['order_id' => 'order-1'],
            true,
            true,
            new DateInterval('P2D')
        );

        $serialized = $request->jsonSerialize();

        WireParity::assertEquivalent(
            $scheduleSettings->jsonSerialize(),
            $serialized['schedule_settings']->jsonSerialize()
        );
        WireParity::assertEquivalent(
            $subscriptionPlan->jsonSerialize(),
            $serialized['subscription_plan']->jsonSerialize()
        );
        WireParity::assertEquivalent(
            $installmentPlan->jsonSerialize(),
            $serialized['installment_plan']->jsonSerialize()
        );
        $this->assertSame(500, $serialized['initial_amount']);
        $this->assertTrue($serialized['first_charge_authorization_only']);
        $this->assertTrue($request->findAdditionalProperty('only_direct_currency'));
    }

    public function testSubscriptionCreateWithNoneSubscriptionPlanThrows()
    {
        $this->expectException(UnivapayUnsupportedFeatureError::class);

        RequestModelFactory::subscriptionCreate(
            'token-1',
            new Money(1000, new Currency('JPY')),
            Period::MONTHLY(),
            null,
            null,
            new SubscriptionPlan(SubscriptionPlanType::NONE())
        );
    }

    // --- tokenPatch() ---------------------------------------------------------------------------

    public function testTokenPatchWithPaymentMethodPatch()
    {
        $patch = new PaymentMethodPatch('new@example.com', ['order_id' => 'order-2']);

        $request = RequestModelFactory::tokenPatch($patch);

        WireParity::assertEquivalent($patch->jsonSerialize(), $request->jsonSerialize());
    }

    public function testTokenPatchWithCardPaymentPatchSetsCvv()
    {
        $patch = new CardPaymentPatch('999');

        $request = RequestModelFactory::tokenPatch($patch);

        $this->assertSame('999', $request->jsonSerialize()['data']->jsonSerialize()['cvv']);
    }

    public function testTokenPatchWithCardPaymentPatchNullCvvIsOmittedNotNull()
    {
        // Allowed delta: old CardPaymentPatch::jsonSerialize() always emits `data: {cvv: null}`
        // (no stripNulls at that nesting level); the generated TransactionTokenUpdateRequestData
        // omits the key entirely when unset.
        $patch = new CardPaymentPatch(null);

        $this->assertSame(['cvv' => null], $patch->jsonSerialize()['data']);

        $request = RequestModelFactory::tokenPatch($patch);

        // TransactionTokenUpdateRequestData::jsonSerialize() returns a bare stdClass (not an
        // array) when it has zero fields set -- which is exactly the case here, since `cvv` was
        // never set. Normalize before asserting the key is absent.
        $serializedData = json_decode((string) json_encode($request->jsonSerialize()['data']->jsonSerialize()), true);
        $this->assertArrayNotHasKey('cvv', $serializedData);
    }

    // --- chargeUpdate()/cancelCreate()/cancelUpdate()/refundUpdate() ------------------------------

    public function testChargeUpdateBuildsTypedMetadata()
    {
        $request = RequestModelFactory::chargeUpdate(['metadata' => ['order_id' => 'order-1']]);

        $this->assertSame('order-1', $request->getMetadata()->getOrderId());
    }

    public function testChargeUpdatePassesThroughUnknownKeysViaAdditionalProperty()
    {
        $request = RequestModelFactory::chargeUpdate(['some_future_field' => 'x']);

        $this->assertSame('x', $request->findAdditionalProperty('some_future_field'));
    }

    public function testCancelCreateBuildsTypedMetadata()
    {
        $request = RequestModelFactory::cancelCreate(['something' => 'anything']);

        $this->assertSame('anything', $request->getMetadata()->findAdditionalProperty('something'));
    }

    public function testCancelCreateWithNullMetadataOmitsIt()
    {
        $request = RequestModelFactory::cancelCreate();

        $this->assertNull($request->getMetadata());
    }

    public function testCancelUpdateBuildsTypedMetadata()
    {
        $request = RequestModelFactory::cancelUpdate(['metadata' => ['order_id' => 'order-1']]);

        $this->assertSame('order-1', $request->getMetadata()->getOrderId());
    }

    public function testRefundUpdateSetsMessageAndReasonAndMetadata()
    {
        $request = RequestModelFactory::refundUpdate([
            'message' => 'changed',
            'reason' => RefundReason::FRAUD(),
            'metadata' => ['order_id' => 'order-1']
        ]);

        $this->assertSame('changed', $request->getMessage());
        $this->assertSame('fraud', $request->getReason());
        $this->assertSame('order-1', $request->getMetadata()->getOrderId());
    }

    public function testRefundUpdateAcceptsARawReasonString()
    {
        $request = RequestModelFactory::refundUpdate(['reason' => 'duplicate']);

        $this->assertSame('duplicate', $request->getReason());
    }

    // --- subscriptionPatch() -----------------------------------------------------------------------

    public function testSubscriptionPatchMapsKnownFieldsToTypedSetters()
    {
        $request = RequestModelFactory::subscriptionPatch(
            'new-token-id',
            null,
            null,
            null,
            SubscriptionStatus::SUSPENDED(),
            ['order_id' => 'order-1'],
            null,
            null,
            null
        );

        $this->assertSame('new-token-id', $request->getTransactionTokenId());
        $this->assertSame('suspended', $request->getStatus());
        $this->assertSame('order-1', $request->getMetadata()->getOrderId());
    }

    public function testSubscriptionPatchMapsPeriodCyclicalPeriodInitialAmountAndPlansToTypedSetters()
    {
        $subscriptionPlan = new SubscriptionPlan(SubscriptionPlanType::FIXED_CYCLES(), 5);
        $installmentPlan = new InstallmentPlan(InstallmentPlanType::FIXED_CYCLES(), 10);

        $request = RequestModelFactory::subscriptionPatch(
            null,
            new Money(500, new Currency('JPY')),
            Period::WEEKLY(),
            null,
            null,
            null,
            $subscriptionPlan,
            $installmentPlan,
            new DateInterval('P10D')
        );

        // The generated SubscriptionUpdateRequest declares typed setters for all
        // five of these (see RequestModelFactory::subscriptionPatch()'s class doc) -- not
        // additionalProperties passthrough.
        $this->assertSame(500, $request->getInitialAmount());
        $this->assertSame('weekly', $request->getPeriod());
        $this->assertSame('P10D', $request->getCyclicalPeriod());
        $this->assertSame('fixed_cycles', $request->getSubscriptionPlan()->getPlanType());
        $this->assertSame(5, $request->getSubscriptionPlan()->getFixedCycles());
        $this->assertSame('fixed_cycles', $request->getInstallmentPlan()->getPlanType());
        $this->assertSame(10, $request->getInstallmentPlan()->getFixedCycles());
    }

    public function testSubscriptionPatchScheduleSettingsMapsKnownFieldsIncludingPreserveEndOfMonthAndZoneId()
    {
        $scheduleSettings = new ScheduleSettings(
            new DateTime('+1 month'),
            new DateTimeZone('Asia/Tokyo'),
            true,
            new DateInterval('P5D')
        );

        $request = RequestModelFactory::subscriptionPatch(
            null,
            null,
            null,
            $scheduleSettings,
            null,
            null,
            null,
            null,
            null
        );

        $updateScheduleSettings = $request->getScheduleSettings();
        $this->assertNotNull($updateScheduleSettings->getStartOn());
        $this->assertSame('P5D', $updateScheduleSettings->getRetryInterval());
        // Forced-default suppression: no old-SDK termination_mode equivalent.
        $this->assertNull($updateScheduleSettings->getTerminationMode());
        $this->assertSame('Asia/Tokyo', $updateScheduleSettings->findAdditionalProperty('zone_id'));
        // SubscriptionUpdateScheduleSettings has a typed preserve_end_of_month
        // setter (zone_id remains a genuine gap, still additionalProperties).
        $this->assertTrue($updateScheduleSettings->getPreserveEndOfMonth());
    }

    // --- scheduledPaymentUpdate() --------------------------------------------------------------

    public function testScheduledPaymentUpdateMapsKnownFields()
    {
        $request = RequestModelFactory::scheduledPaymentUpdate([
            'is_paid' => true,
            'retry_interval' => 'P3D'
        ]);

        $this->assertTrue($request->getIsPaid());
        $this->assertSame('P3D', $request->getRetryInterval());
    }

    public function testScheduledPaymentUpdatePassesThroughUnknownKeys()
    {
        $request = RequestModelFactory::scheduledPaymentUpdate(['some_future_field' => 'x']);

        $this->assertSame('x', $request->findAdditionalProperty('some_future_field'));
    }
}
