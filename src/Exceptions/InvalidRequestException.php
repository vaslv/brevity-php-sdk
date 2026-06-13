<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a request DTO is built with contradictory arguments, before any
 * HTTP call is made (e.g. an explicit domain together with a domain strategy).
 *
 * Being an {@see InvalidArgumentException}, it signals a programming error in
 * the caller rather than a runtime API failure.
 */
class InvalidRequestException extends InvalidArgumentException {}
