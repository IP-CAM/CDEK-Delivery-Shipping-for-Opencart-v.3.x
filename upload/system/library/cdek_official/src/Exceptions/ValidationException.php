<?php

namespace CDEK\Exceptions;

class ValidationException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
