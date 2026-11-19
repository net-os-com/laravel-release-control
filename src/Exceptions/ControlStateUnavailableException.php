<?php

namespace NetOs\ReleaseControl\Exceptions;

use RuntimeException;

class ControlStateUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The shared release control state is unavailable.');
    }
}
