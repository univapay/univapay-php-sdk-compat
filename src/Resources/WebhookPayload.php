<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use Univapay\Compat\Enums\WebhookEvent;

/**
 * Verbatim port (namespace lines only) of the old SDK's `Resources\WebhookPayload`. The return
 * value of `UnivapayClient::parseWebhookData()`: `$event` is the dispatched `WebhookEvent` case,
 * `$data` is the hydrated resource the event carries (a `TransactionToken`/`Charge`/
 * `Subscription`/`Refund`/`Cancel`/`Transfer` instance, depending on `$event`).
 */
class WebhookPayload
{
    public $event;
    public $data;

    public function __construct(WebhookEvent $event, $data)
    {
        $this->event = $event;
        $this->data = $data;
    }
}
