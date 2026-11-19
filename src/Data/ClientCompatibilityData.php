<?php

namespace NetOs\ReleaseControl\Data;

use NetOs\ReleaseControl\Enums\CompatibilityStatusEnum;
use NetOs\ReleaseControl\Enums\ControlErrorCodeEnum;
use Spatie\LaravelData\Data;

class ClientCompatibilityData extends Data
{
    public function __construct(
        public CompatibilityStatusEnum $status,
        public ?ControlErrorCodeEnum $code = null,
    ) {}
}
