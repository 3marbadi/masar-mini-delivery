<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refusal of an inbound Masar event, carrying its contract code
 * (CONTRACT §3.21.7).
 *
 * The processor decides refusals deep inside a transaction, and the response
 * shape is decided at the edge. Throwing carries the verdict out while rolling
 * the transaction back, which is what makes «a rejected event changes nothing»
 * a property of the mechanism rather than a discipline each branch has to keep.
 */
class MasarIntegrationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
