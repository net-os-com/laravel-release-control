<?php

namespace NetOs\ReleaseControl\Data;

use Spatie\LaravelData\Data;

class ControlDocumentData extends Data
{
    public function __construct(
        public int $schemaVersion,
        public string $environment,
        public string $revision,
        public MaintenanceStatusData $maintenance,
        public CompatibilityPlatformsData $platforms,
        public string $changedAt,
        public string $changedBy,
        public string $changeReference,
    ) {}
}
