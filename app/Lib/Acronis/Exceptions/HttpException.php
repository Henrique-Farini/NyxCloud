<?php

declare(strict_types=1);

namespace NyxCloud\Lib\Acronis\Exceptions;

class HttpException extends AcronisException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly mixed $response = null
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function response(): mixed
    {
        return $this->response;
    }
}

