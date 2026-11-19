<?php

namespace NetOs\ReleaseControl\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use NetOs\ReleaseControl\Data\CompatibilityPlatformsData;
use NetOs\ReleaseControl\Data\ControlDocumentData;
use NetOs\ReleaseControl\Data\ControlSnapshotData;
use NetOs\ReleaseControl\Data\MaintenanceStatusData;
use NetOs\ReleaseControl\Enums\ControlOperationEnum;
use NetOs\ReleaseControl\Exceptions\ControlStateConflictException;
use NetOs\ReleaseControl\Services\ControlDocumentValidator;
use NetOs\ReleaseControl\Services\ControlStore;

class UpdateReleaseControlAction
{
    use AsAction;

    public function __construct(private ControlStore $store, private ControlDocumentValidator $validator) {}

    public function handle(
        ControlOperationEnum $operation,
        string $expectedGeneration,
        string $actor,
        string $reference,
        ?CompatibilityPlatformsData $platforms = null,
    ): ControlSnapshotData {
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $expectedGeneration)
            || mb_trim($actor) === '' || mb_strlen($actor) > 200 || mb_trim($reference) === '' || mb_strlen($reference) > 500) {
            throw ValidationException::withMessages(['change' => 'Provide an exact generation, actor, and change reference.']);
        }
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
        if ($operation === ControlOperationEnum::INITIALIZE) {
            if ($expectedGeneration !== '0' || $platforms === null) {
                throw ValidationException::withMessages(['initialize' => 'Initialization requires generation 0 and a complete policy.']);
            }
            $document = new ControlDocumentData(
                1,
                config('release-control.environment'),
                (string) Str::uuid(),
                new MaintenanceStatusData(false, null, null),
                $platforms,
                $timestamp,
                $actor,
                $reference,
            );
        } else {
            $snapshot = $this->store->read();
            if ($snapshot->generation !== $expectedGeneration) {
                throw new ControlStateConflictException();
            }
            $document = ControlDocumentData::from($snapshot->document->toArray());
            if ($operation === ControlOperationEnum::POLICY) {
                if ($platforms === null) {
                    throw ValidationException::withMessages(['platforms' => 'A complete policy is required.']);
                }
                $document->platforms = $platforms;
            } else {
                $active = $operation === ControlOperationEnum::MAINTENANCE_ON;
                if ($document->maintenance->active === $active) {
                    // Repeated entry retains the original incident/start/revision and does not hide an ongoing outage.
                    return $snapshot;
                }
                $document->maintenance = new MaintenanceStatusData(
                    $active,
                    $active ? $timestamp : null,
                    $active ? (string) Str::uuid() : null,
                );
            }
            $document->revision = (string) Str::uuid();
            $document->changedAt = $timestamp;
            $document->changedBy = $actor;
            $document->changeReference = $reference;
        }
        $this->validator->decode($document->toJson(), config('release-control.environment'));

        return $this->store->write($document, $expectedGeneration);
    }
}
