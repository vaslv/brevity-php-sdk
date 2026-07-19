<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

/**
 * Thrown when the API responds with HTTP 401 `unauthenticated`
 * (missing or invalid token).
 */
class AuthenticationException extends ApiException {}
