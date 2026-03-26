<?php

namespace App\Exceptions;

class NotFoundException extends \Exception {
    public function __construct($message = "Recurso no encontrado", $code = 404) {
        parent::__construct($message, $code);
    }
}
