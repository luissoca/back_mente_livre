<?php

namespace App\Exceptions;

class ValidationException extends \Exception {
    public function __construct($message = "Error de validación", $code = 400) {
        parent::__construct($message, $code);
    }
}
