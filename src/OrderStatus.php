<?php

declare(strict_types=1);

namespace Melaku\Telebirr;

/**
 * Normalized result of {@see Telebirr::getOrderStatus} — a server-to-server
 * confirmed view of an order, safe to settle against.
 */
final class OrderStatus
{
    /** True only when Telebirr reports an explicit success status. Fails closed. */
    public bool $paid;

    /** True when Telebirr reports an explicit failure status. */
    public bool $failed;

    /** True when Telebirr reports the payment was cancelled. */
    public bool $cancelled;

    /** Raw trade_status string (e.g. 'PAY_SUCCESS'). */
    public string $tradeStatus;

    /** Total amount as reported by Telebirr — verify it against YOUR order amount before granting. */
    public string $amount;

    public string $currency;

    /** Telebirr's payment order id (their transaction reference), when available. */
    public ?string $paymentOrderId;

    public string $merchOrderId;

    /** Transaction end time as reported by Telebirr, when available. */
    public ?string $transEndTime;

    /** The full queryOrder response, for anything not covered above. */
    public array $raw;

    public function __construct(
        bool $paid,
        bool $failed,
        bool $cancelled,
        string $tradeStatus,
        string $amount,
        string $currency,
        ?string $paymentOrderId,
        string $merchOrderId,
        ?string $transEndTime,
        array $raw
    ) {
        $this->paid = $paid;
        $this->failed = $failed;
        $this->cancelled = $cancelled;
        $this->tradeStatus = $tradeStatus;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->paymentOrderId = $paymentOrderId;
        $this->merchOrderId = $merchOrderId;
        $this->transEndTime = $transEndTime;
        $this->raw = $raw;
    }

    public function toArray(): array
    {
        return [
            'paid'           => $this->paid,
            'failed'         => $this->failed,
            'cancelled'      => $this->cancelled,
            'tradeStatus'    => $this->tradeStatus,
            'amount'         => $this->amount,
            'currency'       => $this->currency,
            'paymentOrderId' => $this->paymentOrderId,
            'merchOrderId'   => $this->merchOrderId,
            'transEndTime'   => $this->transEndTime,
        ];
    }
}
