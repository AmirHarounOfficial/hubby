<?php

namespace App\Services\Shipping\Exceptions;

/** A carrier rejected the account credentials (spec 04 §5.1). */
class CarrierAuthException extends \RuntimeException
{
}
