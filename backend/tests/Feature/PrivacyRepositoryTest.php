<?php

use App\Domain\Privacy\Contracts\PrivacyRepositoryInterface;
use App\Domain\Privacy\Contracts\PrivacyStorageInterface;
use App\Domain\Privacy\Services\PrivacyService;
use App\Domain\Privacy\Services\ProcessPrivacyDeletion;
use App\Infrastructure\Persistence\Eloquent\Models\PrivacyDeletion;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('failed storage cleanup keeps its manifest for a safe retry and scrubs only after success', function () {
    Queue::fake();
    Storage::fake('r2');
    $user = User::factory()->create();
    $translation = Translation::factory()->create(['user_id' => $user->id, 'status' => 'complete', 'source_text' => 'Private source']);
    $deletion = app(PrivacyService::class)->delete($user->id, 'translation', $translation->id, 'project');
    $storage = Mockery::mock(PrivacyStorageInterface::class);
    $storage->shouldReceive('purge')->once()->andThrow(new RuntimeException('temporary storage failure'));
    app()->instance(PrivacyStorageInterface::class, $storage);
    expect(fn () => app(ProcessPrivacyDeletion::class)->handle($deletion['id']))->toThrow(RuntimeException::class);
    expect(PrivacyDeletion::find($deletion['id'])->status)->toBe('failed')
        ->and(Translation::withTrashed()->find($translation->id)->source_text)->toBe('Private source');
    app(PrivacyService::class)->retry($deletion['id'], $user->id);
    app()->forgetInstance(PrivacyStorageInterface::class);
    app(ProcessPrivacyDeletion::class)->handle($deletion['id']);
    expect(PrivacyDeletion::find($deletion['id'])->status)->toBe('completed')
        ->and(PrivacyDeletion::find($deletion['id'])->attempts)->toBe(2)
        ->and(Translation::withTrashed()->find($translation->id)->source_text)->toBe('');
});

test('deleting a PDF does not extend retention for a translation text download', function () {
    Queue::fake();
    Storage::fake('r2');
    $user = User::factory()->create();
    $record = Translation::factory()->create(['user_id' => $user->id, 'status' => 'complete',
        'exports' => [['format' => 'pdf', 'variant' => 'plain', 'status' => 'completed', 'storage_path' => 'unused.pdf'],
            ['format' => 'txt', 'variant' => 'plain', 'status' => 'completed', 'storage_path' => 'unused.txt']],
        'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)]);
    app(PrivacyService::class)->updateSettings($user->id, ['txt' => 1]);
    $deletion = app(PrivacyService::class)->delete($user->id, 'translation', $record->id, 'generated', 'pdf');
    app(ProcessPrivacyDeletion::class)->handle($deletion['id']);
    $expired = app(PrivacyRepositoryInterface::class)->expired();
    expect($expired)->toContain(['user_id' => $user->id, 'resource_type' => 'translation', 'resource_id' => $record->id, 'scope' => 'generated', 'category' => 'txt']);
});
