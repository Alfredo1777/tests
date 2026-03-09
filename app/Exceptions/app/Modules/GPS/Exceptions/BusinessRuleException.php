<?php

namespace App\Exceptions\app\Modules\GPS\Exceptions;

use Exception;

class BusinessRuleException extends Exception
{
    public string $severity;

    public function __construct(string $message, string $severity = 'medium')
    {
        parent::__construct($message);
        $this->severity = $severity;
    }
}
