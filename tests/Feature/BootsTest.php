<?php

declare(strict_types=1);

use Rushing\Versioning\Contracts\ReadableVersionResolver;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Readable\SequentialReadableVersion;
use Rushing\Versioning\Store\DatabaseVersionStore;
use Rushing\Versioning\VersioningServiceProvider;

it('boots the service provider', function (): void {
    expect(app()->getProviders(VersioningServiceProvider::class))->not->toBeEmpty();
});

it('merges the package config', function (): void {
    expect(config('versioning.readable_version'))->not->toBeNull();
});

it('resolves the VersionStore singleton', function (): void {
    expect(app(VersionStore::class))
        ->toBeInstanceOf(DatabaseVersionStore::class)
        ->and(app(VersionStore::class))->toBe(app(VersionStore::class)); // same instance = singleton
});

it('resolves the default readable-version resolver', function (): void {
    expect(app(ReadableVersionResolver::class))->toBeInstanceOf(SequentialReadableVersion::class);
});
