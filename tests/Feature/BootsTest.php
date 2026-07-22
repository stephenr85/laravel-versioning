<?php

declare(strict_types=1);

use Rushing\Versioning\VersioningServiceProvider;

it('boots the service provider', function (): void {
    expect(app()->getProviders(VersioningServiceProvider::class))->not->toBeEmpty();
});

it('merges the package config', function (): void {
    expect(config('versioning.readable_version'))->not->toBeNull();
});
