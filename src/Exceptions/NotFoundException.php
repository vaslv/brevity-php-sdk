<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

/**
 * Thrown when the API responds with HTTP 404 `not-found`.
 *
 * The resource does not exist, is deleted, or belongs to another service —
 * the API never discloses whether a foreign code exists.
 */
class NotFoundException extends ApiException {}
