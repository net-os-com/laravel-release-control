<?php

namespace NetOs\ReleaseControl\Data;

use Spatie\LaravelData\Data;

class CompatibilityPlatformsData extends Data
{
    public function __construct(
        public PlatformCompatibilityData $ios,
        public PlatformCompatibilityData $android,
        public PlatformCompatibilityData $web,
    ) {}
}
