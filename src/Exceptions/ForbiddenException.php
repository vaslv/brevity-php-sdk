<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

/**
 * Thrown when the API responds with HTTP 403 `forbidden`.
 *
 * The token is valid but the action is not allowed. The more specific
 * {@see MissingAbilityException} extends this class, so a
 * `catch (ForbiddenException)` block covers both 403 variants.
 */
class ForbiddenException extends ApiException {}
