<?php

namespace NetOs\ReleaseControl\Services;

use Closure;
use NetOs\ReleaseControl\Data\ControlSnapshotData;
use NetOs\ReleaseControl\Exceptions\ControlStateUnavailableException;

class SharedControlReader
{
    private ?ControlSnapshotData $snapshot = null;

    private int $leaseExpiresAt = 0;

    private Closure $clock;

    public function __construct(private ControlStore $store, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => hrtime(true);
    }

    public function read(): ControlSnapshotData
    {
        $readStartedAt = ($this->clock)();
        if ($this->snapshot !== null && $readStartedAt < $this->leaseExpiresAt) {
            return $this->snapshot;
        }

        $latest = $this->store->read();
        if ($this->snapshot !== null) {
            $comparison = $this->compareGenerations($latest->generation, $this->snapshot->generation);
            if ($comparison < 0 || ($comparison === 0 && $latest->document->toJson() !== $this->snapshot->document->toJson())) {
                throw new ControlStateUnavailableException();
            }
        }
        $this->snapshot = $latest;
        $leaseSeconds = min(5, max(0, config('release-control.read_lease_seconds')));
        $this->leaseExpiresAt = $readStartedAt + (int) ($leaseSeconds * 1_000_000_000);
        if ($leaseSeconds > 0 && ($this->clock)() >= $this->leaseExpiresAt) {
            throw new ControlStateUnavailableException();
        }

        return $latest;
    }

    private function compareGenerations(string $left, string $right): int
    {
        // Do not cast GCS generation IDs to floats or platform-dependent integers.
        return mb_strlen($left) <=> mb_strlen($right) ?: strcmp($left, $right);
    }
}
