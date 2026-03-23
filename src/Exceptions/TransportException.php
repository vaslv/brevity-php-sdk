<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

use RuntimeException;
use Throwable;

class TransportException extends RuntimeException
{
    public static function fromThrowable(Throwable $throwable): self
    {
        return new self($throwable->getMessage(), (int) $throwable->getCode(), $throwable);
    }
}
