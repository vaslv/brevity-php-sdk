<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

/**
 * Thrown when the API responds with HTTP 403 `missing-ability`.
 *
 * The token lacks the ability the endpoint requires (`links:create`,
 * `links:read` or `links:update`); re-issue the token from the admin panel.
 */
class MissingAbilityException extends ForbiddenException {}
