<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The mail provider refused one address, as opposed to being down or misconfigured.
 * Still a TransportException, so the queue and everything that catches mail failures
 * treat it as before; the kind is what the owner is told.
 */
class DeliveryRefused extends TransportException
{
    public const BOUNCED = 'bounced';

    public const SUPPRESSED = 'suppressed';

    /** @param string $kind self::BOUNCED or self::SUPPRESSED */
    public function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }
}
