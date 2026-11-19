<?php

use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Cloud\Core\Exception\ServiceException;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use NetOs\ReleaseControl\Actions\UpdateReleaseControlAction;
use NetOs\ReleaseControl\Data\CompatibilityPlatformsData;
use NetOs\ReleaseControl\Data\ControlDocumentData;
use NetOs\ReleaseControl\Data\ControlSnapshotData;
use NetOs\ReleaseControl\Data\MaintenanceStatusData;
use NetOs\ReleaseControl\Data\PlatformCompatibilityData;
use NetOs\ReleaseControl\Enums\CompatibilityStatusEnum;
use NetOs\ReleaseControl\Enums\ControlOperationEnum;
use NetOs\ReleaseControl\Exceptions\ControlStateConflictException;
use NetOs\ReleaseControl\Exceptions\ControlStateUnavailableException;
use NetOs\ReleaseControl\Services\ClientCompatibilityEvaluator;
use NetOs\ReleaseControl\Services\ControlDocumentValidator;
use NetOs\ReleaseControl\Services\ControlStore;
use NetOs\ReleaseControl\Services\GcsControlStore;
use NetOs\ReleaseControl\Services\SharedControlReader;
use NetOs\ReleaseControl\Services\SharedMaintenanceMode;

function memoryReleaseControlStore(): ControlStore
{
    return new class () implements ControlStore {
        public ?ControlSnapshotData $snapshot = null;

        public bool $unavailable = false;

        /** @var bool[] */
        public array $reads = [];

        /** @var ControlDocumentData[] */
        public array $writes = [];

        public function read(): ControlSnapshotData
        {
            $this->reads[] = true;
            if ($this->unavailable || $this->snapshot === null) {
                throw new ControlStateUnavailableException();
            }

            return $this->snapshot;
        }

        public function write(ControlDocumentData $document, string $expectedGeneration): ControlSnapshotData
        {
            if (($this->snapshot?->generation ?? '0') !== $expectedGeneration) {
                throw new ControlStateConflictException();
            }
            $this->writes[] = $document;
            $this->snapshot = new ControlSnapshotData(
                ControlDocumentData::from($document->toArray()),
                (string) ((int) $expectedGeneration + 1),
            );

            return $this->snapshot;
        }
    };
}

function sampleReleaseControl(bool $active = false): ControlDocumentData
{
    $policy = fn (): PlatformCompatibilityData => new PlatformCompatibilityData('>=1.8.2 <3.0.0', '1.9.0', null);

    return new ControlDocumentData(
        1,
        'testing',
        '11111111-1111-4111-8111-111111111111',
        new MaintenanceStatusData(
            $active,
            $active ? '2026-11-16T12:00:00.000000Z' : null,
            $active ? '22222222-2222-4222-8222-222222222222' : null,
        ),
        new CompatibilityPlatformsData($policy(), $policy(), $policy()),
        '2026-11-16T12:00:00.000000Z',
        'operator@example.test',
        'change-123',
    );
}

beforeEach(function (): void {
    config(['release-control.environment' => 'testing', 'release-control.read_lease_seconds' => 0]);
});

it('preserves the maintenance incident, original start, and policy across repeated entry and cache clear', function (): void {
    $store = memoryReleaseControlStore();
    $store->snapshot = new ControlSnapshotData(sampleReleaseControl(), '10');
    $action = new UpdateReleaseControlAction($store, app(ControlDocumentValidator::class));
    $active = $action->handle(ControlOperationEnum::MAINTENANCE_ON, '10', 'operator', 'incident-123');
    $this->travel(10)->minutes();
    Cache::flush();
    $repeated = $action->handle(ControlOperationEnum::MAINTENANCE_ON, '11', 'operator', 'incident-123');
    expect($repeated->document->maintenance->toArray())->toBe($active->document->maintenance->toArray())
        ->and($repeated->document->platforms->toArray())->toBe(sampleReleaseControl()->platforms->toArray())
        ->and(count($store->writes))->toBe(1)
        ->and((new SharedControlReader($store))->read()->document->maintenance->active)->toBeTrue();
});

it('requires a current generation for maintenance off and preserves policy on verified recovery', function (): void {
    $store = memoryReleaseControlStore();
    $store->snapshot = new ControlSnapshotData(sampleReleaseControl(true), '20');
    $action = new UpdateReleaseControlAction($store, app(ControlDocumentValidator::class));
    expect(fn () => $action->handle(ControlOperationEnum::MAINTENANCE_OFF, '19', 'operator', 'verified'))
        ->toThrow(ControlStateConflictException::class);
    expect($store->snapshot->document->maintenance->active)->toBeTrue();
    $recovered = $action->handle(ControlOperationEnum::MAINTENANCE_OFF, '20', 'operator', 'verified');
    expect($recovered->document->maintenance->toArray())->toBe(['active' => false, 'startedAt' => null, 'incidentId' => null])
        ->and($recovered->document->platforms->toArray())->toBe(sampleReleaseControl()->platforms->toArray())
        ->and($recovered->generation)->toBe('21');
});

it('initializes only an absent record and never recreates missing state implicitly', function (): void {
    $store = memoryReleaseControlStore();
    $action = new UpdateReleaseControlAction($store, app(ControlDocumentValidator::class));
    expect(fn () => $action->handle(ControlOperationEnum::MAINTENANCE_OFF, '0', 'operator', 'recovery'))
        ->toThrow(ControlStateUnavailableException::class);
    $created = $action->handle(
        ControlOperationEnum::INITIALIZE,
        '0',
        'operator',
        'reviewed-initialization',
        sampleReleaseControl()->platforms,
    );
    expect($created->document->maintenance->active)->toBeFalse();
    expect(fn () => $action->handle(ControlOperationEnum::INITIALIZE, '0', 'operator', 'reinitialize', sampleReleaseControl()->platforms))
        ->toThrow(ControlStateConflictException::class);
});

it('updates platform policy without lifting active maintenance', function (): void {
    $store = memoryReleaseControlStore();
    $store->snapshot = new ControlSnapshotData(sampleReleaseControl(true), '20');
    $policy = sampleReleaseControl()->platforms;
    $policy->ios->supportedAppReleases = '>=1.9.0 <3.0.0';
    $result = (new UpdateReleaseControlAction($store, app(ControlDocumentValidator::class)))
        ->handle(ControlOperationEnum::POLICY, '20', 'operator', 'policy-review', $policy);
    expect($result->document->maintenance->toArray())->toBe(sampleReleaseControl(true)->maintenance->toArray())
        ->and($result->document->platforms->ios->supportedAppReleases)->toBe('>=1.9.0 <3.0.0');
});

it('expires inactive read leases within five seconds and fails closed instead of renewing stale state', function (): void {
    $store = memoryReleaseControlStore();
    $store->snapshot = new ControlSnapshotData(sampleReleaseControl(), '10');
    $clock = (object) ['nanoseconds' => 0];
    config(['release-control.read_lease_seconds' => 99]);
    $reader = new SharedControlReader($store, fn (): int => $clock->nanoseconds);
    $reader->read();
    $store->unavailable = true;
    $clock->nanoseconds = 4_999_999_999;
    expect($reader->read()->document->maintenance->active)->toBeFalse()->and(count($store->reads))->toBe(1);
    $clock->nanoseconds = 5_000_000_000;
    expect(fn () => $reader->read())->toThrow(ControlStateUnavailableException::class);
    expect((new SharedMaintenanceMode($reader))->active())->toBeTrue();
});

it('never rolls an observed active incident back to an older or altered generation', function (): void {
    $store = memoryReleaseControlStore();
    $store->snapshot = new ControlSnapshotData(sampleReleaseControl(true), '10000000000000000001');
    $reader = new SharedControlReader($store);
    $reader->read();
    foreach (['9999999999999999999', '10000000000000000001'] as $generation) {
        $store->snapshot = new ControlSnapshotData(sampleReleaseControl(), $generation);
        expect(fn () => $reader->read())->toThrow(ControlStateUnavailableException::class);
    }
    $store->snapshot = new ControlSnapshotData(sampleReleaseControl(), '10000000000000000002');
    expect($reader->read()->document->maintenance->active)->toBeFalse();
});

it('blocks workers and scheduler on missing state and forbids unguarded artisan up or down writes', function (): void {
    $mode = new SharedMaintenanceMode(new SharedControlReader(memoryReleaseControlStore()));
    expect($mode->active())->toBeTrue()->and($mode->data())->toBe(['status' => 503, 'retry' => 15]);
    expect(fn () => $mode->activate([]))->toThrow(LogicException::class);
    expect(fn () => $mode->deactivate())->toThrow(LogicException::class);
});

it('rejects corrupted, wrong-environment, contradictory and untrusted-store policies', function (): void {
    $validator = app(ControlDocumentValidator::class);
    foreach ([
        fn (array $data): array => array_replace($data, ['environment' => 'production']),
        fn (array $data): array => array_replace($data, ['schemaVersion' => 2]),
        fn (array $data): array => array_replace($data, ['maintenance' => ['active' => true, 'startedAt' => null, 'incidentId' => null]]),
        fn (array $data): array => array_replace_recursive($data, [
            'platforms' => ['ios' => ['storeUrl' => 'https://example.test/fake-store']],
        ]),
        fn (array $data): array => array_replace_recursive($data, ['platforms' => ['ios' => ['recommendedAppRelease' => '9.0.0']]]),
        fn (array $data): array => array_replace($data, ['unknownFutureControl' => true]),
    ] as $change) {
        expect(fn () => $validator->decode(json_encode($change(sampleReleaseControl()->toArray())), 'testing'))
            ->toThrow(ValidationException::class);
    }
    expect(fn () => $validator->decode('{', 'testing'))->toThrow(JsonException::class);
});

it('reads a matching GCS metadata and payload generation without falling back to old versions', function (): void {
    $client = Mockery::mock(StorageClient::class);
    $bucket = Mockery::mock(Bucket::class);
    $object = Mockery::mock(StorageObject::class);
    $client->shouldReceive('bucket')->once()->with('private-control')->andReturn($bucket);
    $bucket->shouldReceive('object')->once()->with('environments/testing/state.json')->andReturn($object);
    $object->shouldReceive('reload')->once()->andReturn(['generation' => '123', 'size' => '1024']);
    $object->shouldReceive('downloadAsString')->once()->withArgs(
        fn (array $options): bool => $options['restOptions']['query']['ifGenerationMatch'] === '123',
    )
        ->andReturn(sampleReleaseControl()->toJson());
    $store = new GcsControlStore(
        app(ControlDocumentValidator::class),
        'private-control',
        'environments/testing/state.json',
        'testing',
        $client,
    );
    expect($store->read()->generation)->toBe('123');
});

it('uses generation-match writes and rejects concurrent writers', function (): void {
    $client = Mockery::mock(StorageClient::class);
    $bucket = Mockery::mock(Bucket::class);
    $client->shouldReceive('bucket')->once()->andReturn($bucket);
    $bucket->shouldReceive('upload')->once()->withArgs(fn (string $json, array $options): bool => $options['ifGenerationMatch'] === '123'
        && $options['metadata']['cacheControl'] === 'no-store' && json_decode($json, true)['maintenance']['active'] === true)
        ->andThrow(new ServiceException('precondition failed', 412));
    $store = new GcsControlStore(app(ControlDocumentValidator::class), 'private-control', 'state.json', 'testing', $client);
    expect(fn () => $store->write(sampleReleaseControl(true), '123'))->toThrow(ControlStateConflictException::class);
});

it('returns unknown rather than inventing an inactive control record when storage is unconfigured', function (): void {
    $store = new GcsControlStore(app(ControlDocumentValidator::class), '', '', 'testing');
    expect(fn () => $store->read())->toThrow(ControlStateUnavailableException::class);
});

it('logs the failed storage stage without exposing the SDK message or control document', function (): void {
    $client = Mockery::mock(StorageClient::class);
    $bucket = Mockery::mock(Bucket::class);
    $object = Mockery::mock(StorageObject::class);
    $client->shouldReceive('bucket')->once()->andReturn($bucket);
    $bucket->shouldReceive('object')->once()->with('state.json')->andReturn($object);
    $object->shouldReceive('reload')->once()->andThrow(new ServiceException('Private SDK response', 403));

    Log::shouldReceive('warning')->once()->with('Shared release control storage operation failed', [
        'environment' => 'testing',
        'operation' => 'read',
        'stage' => 'read_metadata',
        'exception_class' => ServiceException::class,
        'exception_code' => 403,
    ]);

    $store = new GcsControlStore(app(ControlDocumentValidator::class), 'private-control', 'state.json', 'testing', $client);
    expect(fn () => $store->read())->toThrow(ControlStateUnavailableException::class);
});

it('evaluates both compatibility directions without coupling patch/minor versions', function (
    string $appRelease,
    string $apiRelease,
    string $range,
    CompatibilityStatusEnum $expected,
): void {
    $request = Request::create('/me');
    $request->headers->add(['X-App-Release' => $appRelease, 'X-App-Platform' => 'ios', 'X-App-Supported-Api-Releases' => $range]);
    $result = app(ClientCompatibilityEvaluator::class)->evaluate($request, sampleReleaseControl(), $apiRelease);
    expect($result->status)->toBe($expected);
})->with([
    ['1.8.2', '1.9.0', '>=1.0.0 <2.0.0', CompatibilityStatusEnum::COMPATIBLE],
    ['1.8.1', '1.9.0', '>=1.0.0 <2.0.0', CompatibilityStatusEnum::APP_UPDATE_REQUIRED],
    ['2.0.0', '1.9.0', '>=2.0.0 <3.0.0', CompatibilityStatusEnum::API_UPDATE_PENDING],
    ['2.0.0', '1.9.0', '>=1.0.0 <3.0.0', CompatibilityStatusEnum::COMPATIBLE],
    ['1.9.0', '2.0.0', '>=1.0.0 <2.0.0', CompatibilityStatusEnum::APP_UPDATE_REQUIRED],
    ['1.9.0', '2.0.0', '>=1.0.0 <3.0.0', CompatibilityStatusEnum::APP_UPDATE_REQUIRED],
    ['1.8.2', 'development', '>=1.0.0 <2.0.0', CompatibilityStatusEnum::UNKNOWN],
    ['2.0.0', '2.1.0', '>=2.2.0 <3.0.0', CompatibilityStatusEnum::API_UPDATE_PENDING],
    ['1.10.0', '1.9.0', '>=1.0.0 <1.5.0', CompatibilityStatusEnum::APP_UPDATE_REQUIRED],
    ['3.0.0', '2.1.0', '>=1.0.0 <2.0.0 || >=3.0.0 <4.0.0', CompatibilityStatusEnum::UNKNOWN],
]);

it('keeps legacy and machine callers separate from malformed app metadata', function (): void {
    $evaluator = app(ClientCompatibilityEvaluator::class);
    $request = Request::create('/device-api/net-link/v1/example');
    $request->headers->set('X-device', 'ios');
    expect($evaluator->evaluate($request, sampleReleaseControl(), '1.9.0')->status)->toBe(CompatibilityStatusEnum::LEGACY);
    foreach ([
        ['X-App-Release' => '1.9.0'],
        ['X-App-Release' => '1.9.0', 'X-App-Platform' => 'android', 'X-App-Supported-Api-Releases' => '>=1.0.0 <2.0.0'],
        ['X-App-Release' => 'not-a-version', 'X-App-Platform' => 'ios', 'X-App-Supported-Api-Releases' => '*'],
        ['X-App-Release' => '1.9.0', 'X-App-Platform' => 'ios', 'X-App-Supported-Api-Releases' => 'bad range'],
    ] as $headers) {
        $request = Request::create('/me');
        $request->headers->add(['X-device' => 'ios', ...$headers]);
        expect($evaluator->evaluate($request, sampleReleaseControl(), '1.9.0')->status)
            ->toBe(CompatibilityStatusEnum::INVALID_CLIENT_METADATA);
    }
});

it('does not add network time to the maximum inactive read lease', function (): void {
    $store = memoryReleaseControlStore();
    $store->snapshot = new ControlSnapshotData(sampleReleaseControl(), '10');
    config(['release-control.read_lease_seconds' => 5]);
    $times = [0, 6_000_000_000];
    $reader = new SharedControlReader($store, function () use (&$times): int {
        return array_shift($times);
    });
    expect(fn () => $reader->read())->toThrow(ControlStateUnavailableException::class);
});

it('sends generation preconditions through the real SDK transport for both reads and writes', function (): void {
    $document = sampleReleaseControl();
    $json = $document->toJson();
    $credentials = Mockery::mock(FetchAuthTokenInterface::class);
    $credentials->allows('getCacheKey')->andReturn('release-unit-test');
    $credentials->allows('fetchAuthToken')->andReturn(['access_token' => 'unit-test-token', 'expires_at' => time() + 3_600]);
    $credentials->allows('getLastReceivedToken')->andReturn(['access_token' => 'unit-test-token', 'expires_at' => time() + 3_600]);
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['generation' => '123', 'size' => (string) mb_strlen($json)])),
        new Response(200, [], $json),
        new Response(200, [], json_encode(['generation' => '124', 'name' => 'state.json', 'bucket' => 'private-control'])),
        new Response(200, [], json_encode(['generation' => '124', 'size' => (string) mb_strlen($json)])),
        new Response(412, ['Content-Type' => 'application/json'], json_encode(['error' => ['code' => 412, 'message' => 'conflict']])),
    ]));
    $handler->push(Middleware::history($history));
    $client = new StorageClient([
        'projectId' => 'unit-test', 'credentialsFetcher' => $credentials, 'retries' => 0,
        'httpHandler' => HttpHandlerFactory::build(new Client(['handler' => $handler])),
    ]);
    $store = new GcsControlStore(app(ControlDocumentValidator::class), 'private-control', 'state.json', 'testing', $client);
    expect($store->read()->generation)->toBe('123');
    expect($store->write($document, '123')->generation)->toBe('124');
    expect($history)->toHaveCount(3);
    parse_str($history[1]['request']->getUri()->getQuery(), $readQuery);
    parse_str($history[2]['request']->getUri()->getQuery(), $writeQuery);
    expect($readQuery)->toMatchArray(['alt' => 'media', 'ifGenerationMatch' => '123'])
        ->not->toHaveKey('generation')
        ->and($writeQuery)->toMatchArray(['ifGenerationMatch' => '123']);
    expect(fn () => $store->read())->toThrow(ControlStateUnavailableException::class);
    expect($history)->toHaveCount(5);
});

it('registers the shared driver and warms only the reader when booting', function (): void {
    expect(app(ControlStore::class))->toBeInstanceOf(GcsControlStore::class)
        ->and(app(SharedControlReader::class))->toBe(app(SharedControlReader::class))
        ->and(app(MaintenanceModeManager::class)->driver('release-control'))->toBe(app(SharedMaintenanceMode::class))
        ->and(config('octane.warm'))->toContain(SharedControlReader::class);
});

it('rejects the protected bucket before making a storage request', function (): void {
    config(['release-control.protected_bucket' => 'application-media']);
    $client = Mockery::mock(StorageClient::class);
    $client->shouldNotReceive('bucket');
    $store = new GcsControlStore(app(ControlDocumentValidator::class), 'application-media', 'state.json', 'testing', $client);

    expect(fn () => $store->read())->toThrow(ControlStateUnavailableException::class)
        ->and(fn () => $store->write(sampleReleaseControl(), '0'))->toThrow(ControlStateUnavailableException::class);
});
