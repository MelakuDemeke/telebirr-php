<?php

declare(strict_types=1);

namespace Melaku\Telebirr;

/**
 * Result of {@see Telebirr::createInAppPayment()}.
 *
 * Carries the `receiveCode` the mobile Telebirr SDK needs to start the
 * payment, plus the exact `merch_order_id` the library sent to Telebirr — the
 * same value Telebirr echoes back in the notification. Persist
 * {@see getMerchOrderId()} against your order before answering the app.
 */
final class InAppOrderResult
{
    private string $receiveCode;
    private string $merchOrderId;
    private ?string $prepayId;

    public function __construct(string $receiveCode, string $merchOrderId, ?string $prepayId = null)
    {
        $this->receiveCode = $receiveCode;
        $this->merchOrderId = $merchOrderId;
        $this->prepayId = $prepayId;
    }

    /** The code to hand to the mobile SDK, unchanged. */
    public function getReceiveCode(): string
    {
        return $this->receiveCode;
    }

    /** The exact merchant order id used — persist this, Telebirr echoes it back verbatim. */
    public function getMerchOrderId(): string
    {
        return $this->merchOrderId;
    }

    /** Telebirr's prepay id for this order, when the gateway returns one. */
    public function getPrepayId(): ?string
    {
        return $this->prepayId;
    }

    /** @return array{receiveCode:string, merchOrderId:string, prepayId:?string} */
    public function toArray(): array
    {
        return [
            'receiveCode'  => $this->receiveCode,
            'merchOrderId' => $this->merchOrderId,
            'prepayId'     => $this->prepayId,
        ];
    }
}
