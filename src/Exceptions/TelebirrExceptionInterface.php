<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Exceptions;

/**
 * Marker interface implemented by every exception this library throws.
 *
 * Catch this to handle any Telebirr error in one place, regardless of the
 * concrete type (API failure, bad parameter, misconfiguration):
 *
 *   try {
 *       $telebirr->createCheckoutUrl(...);
 *   } catch (TelebirrExceptionInterface $e) {
 *       // any error originating from the Telebirr library
 *   }
 */
interface TelebirrExceptionInterface extends \Throwable
{
}
