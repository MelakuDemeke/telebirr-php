<?php

declare(strict_types=1);

namespace Melaku\Telebirr;

/**
 * Result of {@see Telebirr::createCheckoutUrl()}.
 *
 * Crucially this exposes the exact `merch_order_id` the library sent to
 * Telebirr — the same value Telebirr echoes back in notifications and return
 * URLs. Persist {@see getMerchOrderId()} against your order so the later lookup
 * cannot miss, rather than assuming it equals whatever id you passed in.
 */
final class CheckoutResult
{
    private string $checkoutUrl;
    private string $merchOrderId;
    private string $prepayId;

    public function __construct(string $checkoutUrl, string $merchOrderId, string $prepayId)
    {
        $this->checkoutUrl = $checkoutUrl;
        $this->merchOrderId = $merchOrderId;
        $this->prepayId = $prepayId;
    }

    /** The URL to redirect the user to for payment. */
    public function getCheckoutUrl(): string
    {
        return $this->checkoutUrl;
    }

    /** The exact merchant order id used — persist this, Telebirr echoes it back verbatim. */
    public function getMerchOrderId(): string
    {
        return $this->merchOrderId;
    }

    /** Telebirr's prepay id for this order. */
    public function getPrepayId(): string
    {
        return $this->prepayId;
    }

    /** @return array{checkoutUrl:string, merchOrderId:string, prepayId:string} */
    public function toArray(): array
    {
        return [
            'checkoutUrl'  => $this->checkoutUrl,
            'merchOrderId' => $this->merchOrderId,
            'prepayId'     => $this->prepayId,
        ];
    }
}
