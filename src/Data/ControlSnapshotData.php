<?php

namespace NetOs\ReleaseControl\Data;

use Spatie\LaravelData\Data;

class ControlSnapshotData extends Data
{
    public function __construct(
        public ControlDocumentData $document,
        public string $generation,
    ) {}
}
