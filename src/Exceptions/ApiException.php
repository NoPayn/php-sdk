<?php

declare(strict_types=1);

namespace NoPayn\Exceptions;

class ApiException extends NoPaynException
{
    public function __construct(
        private readonly int $httpStatusCode,
        string $message,
        private readonly mixed $errorBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "NoPayn API error (HTTP {$httpStatusCode}): {$message}",
            $httpStatusCode,
            $previous,
        );
    }

    public function getStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getErrorBody(): mixed
    {
        return $this->errorBody;
    }
}
