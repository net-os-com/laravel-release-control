<?php

namespace NetOs\ReleaseControl\Services;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use NetOs\ReleaseControl\Data\CompatibilityPlatformsData;
use NetOs\ReleaseControl\Data\ControlDocumentData;

class ControlDocumentValidator
{
    public const string VERSION_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D';

    public function decode(string $json, string $environment): ControlDocumentData
    {
        if (mb_strlen($json, '8bit') > 65_536) {
            throw ValidationException::withMessages(['document' => 'Control documents must be at most 64 KiB.']);
        }

        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $data = Validator::make(['document' => $value], [
            'document' => [
                'required', 'array:schemaVersion,environment,revision,maintenance,platforms,changedAt,changedBy,changeReference',
            ],
            'document.schemaVersion' => ['required', 'integer', 'in:1'],
            'document.environment' => ['required', 'string', 'in:' . $environment],
            'document.revision' => ['required', 'uuid'],
            'document.changedAt' => ['required', 'date_format:Y-m-d\TH:i:s.u\Z'],
            'document.changedBy' => ['required', 'string', 'max:200'],
            'document.changeReference' => ['required', 'string', 'max:500'],
            'document.maintenance' => ['required', 'array:active,startedAt,incidentId'],
            'document.maintenance.active' => ['required', 'boolean'],
            'document.maintenance.startedAt' => ['present', 'nullable', 'date_format:Y-m-d\TH:i:s.u\Z'],
            'document.maintenance.incidentId' => ['present', 'nullable', 'uuid'],
            'document.platforms' => ['required', 'array:ios,android,web'],
            ...$this->platformRules('document.platforms'),
        ])->validate()['document'];

        if (!is_int($data['schemaVersion']) || !is_bool($data['maintenance']['active'])
            || $data['maintenance']['active'] !== ($data['maintenance']['startedAt'] !== null)
            || $data['maintenance']['active'] !== ($data['maintenance']['incidentId'] !== null)) {
            throw ValidationException::withMessages([
                'maintenance' => 'Active maintenance requires its original start and incident; inactive maintenance requires null values.',
            ]);
        }

        if ($data['maintenance']['startedAt'] !== null && $data['maintenance']['startedAt'] > $data['changedAt']) {
            throw ValidationException::withMessages(['maintenance' => 'The incident start cannot follow the change timestamp.']);
        }
        $this->validatePlatforms($data['platforms']);

        return ControlDocumentData::from($data);
    }

    public function decodePlatforms(string $json): CompatibilityPlatformsData
    {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $data = Validator::make(['platforms' => $value], [
            'platforms' => ['required', 'array:ios,android,web'],
            ...$this->platformRules('platforms'),
        ])->validate()['platforms'];
        $this->validatePlatforms($data);

        return CompatibilityPlatformsData::from($data);
    }

    /**
     * @return array<string, array<string>>
     */
    private function platformRules(string $prefix): array
    {
        $rules = [];
        foreach (['ios', 'android', 'web'] as $platform) {
            $path = $prefix . '.' . $platform;
            $rules[$path] = ['required', 'array:supportedAppReleases,recommendedAppRelease,storeUrl'];
            $rules[$path . '.supportedAppReleases'] = ['required', 'string', 'max:128'];
            $rules[$path . '.recommendedAppRelease'] = ['present', 'nullable', 'regex:' . self::VERSION_PATTERN];
            $rules[$path . '.storeUrl'] = ['present', 'nullable', 'url:https', 'max:500'];
        }

        return $rules;
    }

    /**
     * @param array<string, array<string, mixed>> $platforms
     */
    private function validatePlatforms(array $platforms): void
    {
        foreach ($platforms as $platform => $policy) {
            (new VersionParser())->parseConstraints($policy['supportedAppReleases']);
            if ($policy['recommendedAppRelease'] !== null
                && !Semver::satisfies($policy['recommendedAppRelease'], $policy['supportedAppReleases'])) {
                throw ValidationException::withMessages([$platform => 'The recommended release must be supported.']);
            }
            $storeHost = $policy['storeUrl'] === null ? null : parse_url($policy['storeUrl'], PHP_URL_HOST);
            if (($platform === 'web' && $storeHost !== null)
                || ($platform === 'ios' && $storeHost !== null && $storeHost !== 'apps.apple.com')
                || ($platform === 'android' && $storeHost !== null && $storeHost !== 'play.google.com')) {
                throw ValidationException::withMessages([$platform => 'Use a verified platform store URL, or null before publication.']);
            }
        }
    }
}
