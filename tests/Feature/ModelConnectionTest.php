<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Tests\Fixtures\FixtureRecord;

/*
 * A record on a NON-default connection keeps its versions on that same connection (launch review, 2026-10-16).
 *
 * The store minted and read versions through the process-default connection while saving the record's HEAD through
 * the record's own. On a tenancy host a central record (default = the tenant) therefore had its history written to the
 * tenant, outside any transaction the caller opened on the record's connection: a caller that snapshots and then fails
 * (beam-ux's EntryPublication::baseline()) rolled back the record but not the version, leaving history it could never
 * reconcile. The record's own `versions()` relation already resolves on the record's connection; the store now agrees.
 */

beforeEach(function (): void {
    config(['database.connections.central' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);

    Schema::connection('central')->create('versions', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('versionable_type');
        $table->string('versionable_id');
        $table->unsignedInteger('version');
        $table->json('snapshot');
        $table->string('label')->nullable();
        $table->string('created_by')->nullable();
        $table->timestamps();
        $table->unique(['versionable_type', 'versionable_id', 'version']);
    });
    Schema::connection('central')->create('fixture_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->json('data')->nullable();
        $table->uuid('head_version')->nullable();
        $table->timestamps();
    });
});

it('mints and reads the versions of a record on a non-default connection on that connection', function (): void {
    $record = FixtureRecord::on('central')->create(['name' => 'central', 'data' => []]);

    $version = app(VersionStore::class)->snapshot($record, 'first');

    expect(DB::connection('central')->table('versions')->count())->toBe(1)
        ->and(DB::connection('testing')->table('versions')->count())->toBe(0)
        ->and(app(VersionStore::class)->versions($record)->pluck('id')->all())->toBe([$version->id])
        ->and(app(VersionStore::class)->head($record->fresh())?->id)->toBe($version->id)
        ->and($record->versions()->count())->toBe(1);
});

it('rolls a snapshot back with a transaction the caller opened on the record connection', function (): void {
    $record = FixtureRecord::on('central')->create(['name' => 'central', 'data' => []]);

    try {
        DB::connection('central')->transaction(function () use ($record): void {
            app(VersionStore::class)->snapshot($record, 'first');

            throw new RuntimeException('a failure after the snapshot');
        });
    } catch (RuntimeException) {
    }

    expect(DB::connection('central')->table('versions')->count())->toBe(0)
        ->and(DB::connection('testing')->table('versions')->count())->toBe(0)
        ->and(app(VersionStore::class)->versions($record))->toBeEmpty();
});

it('keeps a record on the default connection versioned on the default connection', function (): void {
    $record = FixtureRecord::create(['name' => 'default', 'data' => []]);

    app(VersionStore::class)->snapshot($record);

    expect(DB::connection('testing')->table('versions')->count())->toBe(1)
        ->and(DB::connection('central')->table('versions')->count())->toBe(0);
});
