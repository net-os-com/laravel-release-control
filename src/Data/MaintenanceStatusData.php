<?php

namespace NetOs\ReleaseControl\Data;

use Spatie\LaravelData\Data;

class MaintenanceStatusData extends Data
{
    public function __construct(
        public ?bool $active,
        public ?string $startedAt,
        public ?string $incidentId,
    ) {}
}
