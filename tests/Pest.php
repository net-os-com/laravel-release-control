<?php

use NetOs\ReleaseControl\ReleaseControlServiceProvider;
use Orchestra\Testbench\TestCase;
use Spatie\LaravelData\LaravelDataServiceProvider;

pest()->extend(TestCase::class)->beforeEach(function (): void {
    $this->app->register(LaravelDataServiceProvider::class);
    $this->app->register(ReleaseControlServiceProvider::class);
})->in('ReleaseControlTest.php');
