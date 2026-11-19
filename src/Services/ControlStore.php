<?php

namespace NetOs\ReleaseControl\Services;

use NetOs\ReleaseControl\Data\ControlDocumentData;
use NetOs\ReleaseControl\Data\ControlSnapshotData;

interface ControlStore
{
    public function read(): ControlSnapshotData;

    /**
     * Generation 0 creates an absent object; an existing object requires its exact current generation.
     */
    public function write(ControlDocumentData $document, string $expectedGeneration): ControlSnapshotData;
}
