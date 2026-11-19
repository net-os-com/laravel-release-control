<?php

namespace NetOs\ReleaseControl\Data;

use Spatie\LaravelData\Data;

class PlatformCompatibilityData extends Data
{
    public function __construct(
        public string $supportedAppReleases,
        public ?string $recommendedAppRelease,
        public ?string $storeUrl,
    ) {}
}
