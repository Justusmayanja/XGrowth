<?php

namespace App\Exceptions;

use RuntimeException;

class TikTokVideoException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'validation',
    ) {
        parent::__construct($message);
    }
}
