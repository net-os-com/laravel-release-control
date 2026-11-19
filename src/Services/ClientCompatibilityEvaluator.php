<?php

namespace NetOs\ReleaseControl\Services;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Http\Request;
use NetOs\ReleaseControl\Data\ClientCompatibilityData;
use NetOs\ReleaseControl\Data\ControlDocumentData;
use NetOs\ReleaseControl\Enums\ClientPlatformEnum;
use NetOs\ReleaseControl\Enums\CompatibilityStatusEnum;
use NetOs\ReleaseControl\Enums\ControlErrorCodeEnum;
use UnexpectedValueException;

class ClientCompatibilityEvaluator
{
    public function evaluate(Request $request, ControlDocumentData $document, ?string $apiRelease): ClientCompatibilityData
    {
        $release = $request->header('X-App-Release');
        $platformHeader = $request->header('X-App-Platform');
        if ($release === null && $platformHeader === null) {
            // X-device alone belongs to existing clients and does not establish a version contract.
            return new ClientCompatibilityData(CompatibilityStatusEnum::LEGACY);
        }

        $platform = ClientPlatformEnum::tryFrom($platformHeader ?? '');
        $apiRange = $request->header('X-App-Supported-Api-Releases');
        if ($platform === null || !is_string($release) || !preg_match(ControlDocumentValidator::VERSION_PATTERN, $release)
            || !is_string($apiRange) || mb_strlen($apiRange) > 128 || $apiRange === ''
            || ($request->hasHeader('X-device') && $request->header('X-device') !== $platform->value)) {
            return new ClientCompatibilityData(
                CompatibilityStatusEnum::INVALID_CLIENT_METADATA,
                ControlErrorCodeEnum::INVALID_CLIENT_METADATA,
            );
        }

        try {
            $supportedApis = (new VersionParser())->parseConstraints($apiRange);
        } catch (UnexpectedValueException $exception) {
            unset($exception);

            return new ClientCompatibilityData(
                CompatibilityStatusEnum::INVALID_CLIENT_METADATA,
                ControlErrorCodeEnum::INVALID_CLIENT_METADATA,
            );
        }
        if ($apiRelease === null || !preg_match(ControlDocumentValidator::VERSION_PATTERN, $apiRelease)) {
            return new ClientCompatibilityData(CompatibilityStatusEnum::UNKNOWN);
        }

        if (version_compare(explode('.', $release)[0], explode('.', $apiRelease)[0], '<')) {
            return new ClientCompatibilityData(CompatibilityStatusEnum::APP_UPDATE_REQUIRED, ControlErrorCodeEnum::APP_UPDATE_REQUIRED);
        }

        $policy = match ($platform) {
            ClientPlatformEnum::IOS => $document->platforms->ios,
            ClientPlatformEnum::ANDROID => $document->platforms->android,
            ClientPlatformEnum::WEB => $document->platforms->web,
        };
        $acceptsApi = Semver::satisfies($apiRelease, $apiRange);
        if (!$acceptsApi) {
            $normalizedApi = (new VersionParser())->normalize($apiRelease);
            $lower = $supportedApis->getLowerBound();
            $upper = $supportedApis->getUpperBound();
            $comparedToLower = version_compare($normalizedApi, $lower->getVersion());
            $comparedToUpper = version_compare($normalizedApi, $upper->getVersion());
            if ($comparedToLower < 0 || ($comparedToLower === 0 && !$lower->isInclusive())) {
                return new ClientCompatibilityData(CompatibilityStatusEnum::API_UPDATE_PENDING, ControlErrorCodeEnum::API_UPDATE_PENDING);
            }
            if ($comparedToUpper > 0 || ($comparedToUpper === 0 && !$upper->isInclusive())) {
                return new ClientCompatibilityData(CompatibilityStatusEnum::APP_UPDATE_REQUIRED, ControlErrorCodeEnum::APP_UPDATE_REQUIRED);
            }

            // A gap in a disjoint range does not prove that another store update can resolve the situation.
            return new ClientCompatibilityData(CompatibilityStatusEnum::UNKNOWN);
        }
        if (Semver::satisfies($release, $policy->supportedAppReleases)) {
            return new ClientCompatibilityData(CompatibilityStatusEnum::COMPATIBLE);
        }
        if (version_compare($release, $apiRelease, '>')) {
            return new ClientCompatibilityData(CompatibilityStatusEnum::API_UPDATE_PENDING, ControlErrorCodeEnum::API_UPDATE_PENDING);
        }

        return new ClientCompatibilityData(CompatibilityStatusEnum::APP_UPDATE_REQUIRED, ControlErrorCodeEnum::APP_UPDATE_REQUIRED);
    }
}
