<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Exceptions;

class ValidationException extends ApiException
{
    /** @var array<string, string[]> */
    private $errors;

    /**
     * @param  array<string, string[]>  $errors
     */
    public function __construct(
        string $message = 'Validation failed.',
        array $errors = [],
        int $statusCode = 422,
        ?array $responseBody = null
    ) {
        parent::__construct($message, $statusCode, $responseBody);
        $this->errors = $errors;
    }

    /**
     * @return array<string, string[]>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
