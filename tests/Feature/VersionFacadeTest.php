<?php

use Illuminate\Support\Collection;
use Rushing\Versioning\Contracts\Versionable;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Facades\Version;
use Rushing\Versioning\Models\Version as VersionModel;

it('resolves the VersionStore singleton as its facade accessor', function () {
    expect(Version::getFacadeRoot())
        ->toBeInstanceOf(VersionStore::class)
        ->toBe(app(VersionStore::class));
});

it('is fakeable via a container swap', function () {
    $fake = new class implements VersionStore
    {
        public function snapshot(Versionable $model, ?string $label = null): VersionModel
        {
            return new VersionModel;
        }

        public function versions(Versionable $model): Collection
        {
            return collect(['faked']);
        }

        public function version(Versionable $model, string $ref): ?VersionModel
        {
            return null;
        }

        public function restore(Versionable $model, string $ref): void {}

        public function head(Versionable $model): ?VersionModel
        {
            return null;
        }
    };

    Version::swap($fake);

    $model = new class implements Versionable
    {
        public function toVersionSnapshot(): array
        {
            return [];
        }

        public function restoreVersionSnapshot(array $snapshot): void {}

        public function versionPinAttribute(): string
        {
            return 'head_version';
        }
    };

    expect(Version::getFacadeRoot())->toBe($fake)
        ->and(Version::versions($model)->all())->toBe(['faked']);
});
