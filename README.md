# Laravel Release Control

Read shared maintenance state and evaluate app and API version compatibility across Laravel processes. The default store uses Google Cloud Storage generation preconditions for reads and writes. A process may reuse a confirmed document for at most five seconds, measured from the start of the storage read. Laravel reports maintenance as active when the shared state cannot be confirmed.

## Install

Requires PHP 8.4 or later and Laravel 12 or 13.

~~~sh
composer require net-os/laravel-release-control
php artisan vendor:publish --tag=release-control-config
~~~

Set the following on every process using shared maintenance state:

~~~dotenv
APP_MAINTENANCE_DRIVER=release-control
RELEASE_CONTROL_ENVIRONMENT=staging
RELEASE_CONTROL_BUCKET=example-private-release-control
RELEASE_CONTROL_OBJECT=control.json
RELEASE_CONTROL_PROTECTED_BUCKET=example-application-media
GOOGLE_CLOUD_PROJECT_ID=example-project
~~~

Use a private bucket dedicated to release control. The protected bucket setting rejects the configured application-media bucket. Google Cloud authentication uses Application Default Credentials. Give readers object-read access and restrict object writes to the process that performs reviewed release operations.

## Use

- Resolve the current generation and validated document by calling `read()` on `NetOs\ReleaseControl\Services\SharedControlReader` from the container.
- Evaluate the app version against the current API version and platform policy with `NetOs\ReleaseControl\Services\ClientCompatibilityEvaluator`. The evaluator reads `X-App-Release`, `X-App-Platform`, and `X-App-Supported-Api-Releases`. It returns a status for the application to interpret.
- Use `NetOs\ReleaseControl\Actions\UpdateReleaseControlAction` from an operator-controlled command. Initialization requires generation `0`, a complete policy, an actor, and a change reference. Updates require the exact current generation, an actor, and a change reference. The application decides who can authorize writes and when it is safe to exit maintenance.
- Bind another implementation of `NetOs\ReleaseControl\Services\ControlStore` to replace Google Cloud Storage.

The package registers the Laravel maintenance driver and exposes these services. Applications own their HTTP routes, request bypass rules, operator commands, and deployment checks.

## Develop

~~~sh
composer install
composer test
~~~

The tests use an in-memory store and a mocked Google Cloud transport. They do not require a bucket or cloud credentials.
