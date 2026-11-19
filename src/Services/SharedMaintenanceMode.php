<?php

namespace NetOs\ReleaseControl\Services;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use LogicException;
use NetOs\ReleaseControl\Exceptions\ControlStateUnavailableException;

class SharedMaintenanceMode implements MaintenanceMode
{
    public function __construct(private SharedControlReader $reader) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function activate(array $payload): void
    {
        throw new LogicException('Enter maintenance mode through a guarded release control operation.');
    }

    public function deactivate(): void
    {
        throw new LogicException('Use a guarded release control operation after verifying migrations.');
    }

    public function active(): bool
    {
        try {
            return $this->reader->read()->document->maintenance->active !== false;
        } catch (ControlStateUnavailableException $exception) {
            unset($exception);

            // Workers and scheduler must not begin new database work when control state is unknown.
            return true;
        }
    }

    /**
     * @return array<string, int>
     */
    public function data(): array
    {
        return ['status' => 503, 'retry' => 15];
    }
}
