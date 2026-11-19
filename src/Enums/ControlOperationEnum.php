<?php

namespace NetOs\ReleaseControl\Enums;

enum ControlOperationEnum: string
{
    case INITIALIZE = 'initialize';
    case MAINTENANCE_ON = 'on';
    case MAINTENANCE_OFF = 'off';
    case POLICY = 'policy';
}
