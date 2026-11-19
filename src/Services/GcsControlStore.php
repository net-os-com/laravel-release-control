<?php

namespace NetOs\ReleaseControl\Services;

use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Cloud\Core\Exception\ServiceException;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use NetOs\ReleaseControl\Data\ControlDocumentData;
use NetOs\ReleaseControl\Data\ControlSnapshotData;
use NetOs\ReleaseControl\Exceptions\ControlStateConflictException;
use NetOs\ReleaseControl\Exceptions\ControlStateUnavailableException;
use Throwable;

class GcsControlStore implements ControlStore
{
    public function __construct(
        private ControlDocumentValidator $validator,
        private string $bucketName,
        private string $objectName,
        private string $environment,
        private ?StorageClient $storage = null,
    ) {}

    public function read(): ControlSnapshotData
    {
        $stage = 'open_bucket';

        try {
            $object = $this->bucket()->object($this->objectName);
            $stage = 'read_metadata';
            $metadata = $object->reload();
            $generation = (string) $metadata['generation'];
            $stage = 'validate_metadata';
            if (!preg_match('/^[1-9][0-9]*$/D', $generation) || (int) $metadata['size'] > 65_536) {
                throw new ControlStateUnavailableException();
            }
            // Check the latest generation again while downloading. Never read an old object version as a fallback.
            $stage = 'download_document';
            $json = $object->downloadAsString([
                // SDK 2.6 drops unknown top-level download options; Guzzle's query option reaches the HTTP request.
                'restOptions' => [
                    'headers' => ['Cache-Control' => 'no-cache'],
                    'query' => ['alt' => 'media', 'ifGenerationMatch' => $generation],
                ],
            ]);

            $stage = 'validate_document';

            return new ControlSnapshotData($this->validator->decode($json, $this->environment), $generation);
        } catch (Throwable $exception) {
            $this->logFailure('read', $stage, $exception);

            throw new ControlStateUnavailableException();
        }
    }

    public function write(ControlDocumentData $document, string $expectedGeneration): ControlSnapshotData
    {
        $this->validator->decode($document->toJson(), $this->environment);

        $stage = 'open_bucket';

        try {
            $bucket = $this->bucket();
            $stage = 'upload_document';
            $object = $bucket->upload($document->toJson(), [
                'name' => $this->objectName,
                'ifGenerationMatch' => $expectedGeneration,
                'metadata' => ['contentType' => 'application/json', 'cacheControl' => 'no-store'],
            ]);

            return new ControlSnapshotData($document, (string) $object->info()['generation']);
        } catch (Throwable $exception) {
            if ($exception instanceof ServiceException && $exception->getCode() === 412) {
                throw new ControlStateConflictException();
            }

            $this->logFailure('write', $stage, $exception);

            throw new ControlStateUnavailableException();
        }
    }

    private function logFailure(string $operation, string $stage, Throwable $exception): void
    {
        // SDK messages can include response bodies or request details. Log only safe diagnostics.
        Log::warning('Shared release control storage operation failed', [
            'environment' => $this->environment,
            'operation' => $operation,
            'stage' => $stage,
            'exception_class' => $exception::class,
            'exception_code' => $exception instanceof ServiceException ? $exception->getCode() : null,
        ]);
    }

    private function bucket(): Bucket
    {
        if ($this->bucketName === '' || $this->objectName === ''
            || $this->bucketName === config('release-control.protected_bucket')) {
            throw new ControlStateUnavailableException();
        }

        $this->storage ??= new StorageClient([
            'projectId' => config('release-control.project_id'),
            'requestTimeout' => config('release-control.request_timeout_seconds'),
            'retries' => 0,
            'authHttpHandler' => HttpHandlerFactory::build(new Client(['timeout' => 2, 'connect_timeout' => 1])),
        ]);

        return $this->storage->bucket($this->bucketName);
    }
}
