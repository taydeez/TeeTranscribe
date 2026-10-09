<?php

use App\Infrastructure\Privacy\R2PrivacyStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('storage purge is repeatable and cannot affect another project or invoice', function () {
    Storage::fake('r2');
    $id = (string) Str::ulid();
    $other = (string) Str::ulid();
    Storage::disk('r2')->put('exports/'.$id.'/old.txt', 'private');
    Storage::disk('r2')->put('exports/'.$id.'/revisions/2/speakers/new.pdf', 'private');
    Storage::disk('r2')->put('exports/'.$other.'/keep.txt', 'keep');
    Storage::disk('r2')->put('invoices/keep.pdf', 'keep');
    $manifest = ['prefixes' => ['exports/'.$id.'/']];
    app(R2PrivacyStorage::class)->purge($manifest);
    app(R2PrivacyStorage::class)->purge($manifest);
    expect(Storage::disk('r2')->allFiles('exports/'.$id))->toBe([]);
    Storage::disk('r2')->assertExists(['exports/'.$other.'/keep.txt', 'invoices/keep.pdf']);
});

test('storage purge rejects a bucket wide prefix before deleting any object', function () {
    Storage::fake('r2');
    Storage::disk('r2')->put('exports/keep.txt', 'keep');
    expect(fn () => app(R2PrivacyStorage::class)->purge(['prefixes' => ['exports/']]))->toThrow(RuntimeException::class);
    Storage::disk('r2')->assertExists('exports/keep.txt');
});
