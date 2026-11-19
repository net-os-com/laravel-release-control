<?php

namespace NetOs\ReleaseControl\Exceptions;

use RuntimeException;

class ControlStateConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The control generation changed. Read the current state before retrying.');
    }
}
