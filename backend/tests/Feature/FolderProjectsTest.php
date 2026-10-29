<?php

use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\AudioDubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Services\DubbingService;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\Folder;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Cache::forget('translation:google:nmt:languages:en');
    config(['translation.google.key' => 'test', 'dubbing.provider' => 'elevenlabs', 'dubbing.key' => 'test',
        'billing.free_credits' => '0', 'billing.rates.translation.google.nmt.credits' => '10',
        'billing.rates.dubbing.elevenlabs.dubbing_v2.credits' => '10']);
    Http::fake(['translation.googleapis.com/language/translate/v2/languages*' => Http::response(['data' => ['languages' => [
        ['language' => 'en', 'name' => 'English'], ['language' => 'es', 'name' => 'Spanish'],
    ]]])]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'funding');
    $this->upload = UploadSession::create(['user_id' => $this->user->id, 'client_key' => (string) Str::uuid(), 'filename' => 'Interview.mp4',
        'content_type' => 'video/mp4', 'size' => 1000, 'fingerprint' => str_repeat('f', 64), 'storage_path' => 'audio/'.Str::ulid().'.mp4',
        'part_size' => 16 * 1024 * 1024, 'expires_at' => now()->addDays(7), 'status' => 'completed']);
    $this->mock(DubbingMediaInterface::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturn(['size' => 1000, 'duration_ms' => 60000]));
});

function folderTranslationQuote(object $test, array $options = []): array
{
    return $test->postJson('/api/v1/translations/quotes', $options + ['text' => 'Hello world.', 'target_language' => 'es', 'client_key' => (string) Str::uuid()])->assertOk()->json();
}

function folderDubbingQuote(object $test, array $options = []): array
{
    $quote = $test->postJson('/api/v1/dubbings/quotes', $options + ['video_storage_path' => $test->upload->storage_path,
        'target_language' => 'es', 'client_key' => (string) Str::uuid()])->assertAccepted()->json();
    app(DubbingService::class)->measure($quote['id']);

    return $quote;
}

test('translations and dubbing confirmations save to the selected folder exactly once', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Client work']);
    $translationQuote = folderTranslationQuote($this, ['folder_id' => $folder->id]);
    $dubQuote = folderDubbingQuote($this, ['folder_id' => $folder->id]);
    $translation = $this->postJson('/api/v1/translations', ['quote_id' => $translationQuote['id']])->assertAccepted()->assertJsonPath('folderId', $folder->id)->json();
    $dubbing = $this->postJson('/api/v1/dubbings', ['quote_id' => $dubQuote['id']])->assertAccepted()->assertJsonPath('folderId', $folder->id)->json();
    $this->postJson('/api/v1/translations', ['quote_id' => $translationQuote['id']])->assertAccepted()->assertJsonPath('id', $translation['id']);
    $this->postJson('/api/v1/dubbings', ['quote_id' => $dubQuote['id']])->assertAccepted()->assertJsonPath('id', $dubbing['id']);
    expect($folder->fresh()->translations->modelKeys())->toBe([$translation['id']])
        ->and($folder->fresh()->dubbings->modelKeys())->toBe([$dubbing['id']])
        ->and(Translation::sole()->folder->id)->toBe($folder->id)->and(Dubbing::sole()->folder->id)->toBe($folder->id);
    $this->assertDatabaseCount('usage_charges', 2);
    $this->getJson('/api/v1/folders/'.$folder->id)->assertOk()->assertJsonCount(2, 'projects')
        ->assertJsonPath('translationCount', 1)->assertJsonPath('dubbingCount', 1)
        ->assertJsonFragment(['id' => $translation['id'], 'type' => 'translation'])
        ->assertJsonFragment(['id' => $dubbing['id'], 'type' => 'dubbing']);
    $this->getJson('/api/v1/folders')->assertOk()->assertJsonPath('data.0.translationCount', 1)->assertJsonPath('data.0.dubbingCount', 1);
});

test('all new projects without a selected folder share todays existing folder', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => now()->format('F j, Y')]);
    $translationQuote = folderTranslationQuote($this);
    $dubQuote = folderDubbingQuote($this);
    $this->postJson('/api/v1/translations', ['quote_id' => $translationQuote['id']])->assertAccepted()->assertJsonPath('folderId', $folder->id);
    $this->postJson('/api/v1/dubbings', ['quote_id' => $dubQuote['id']])->assertAccepted()->assertJsonPath('folderId', $folder->id);
    $this->assertDatabaseCount('folders', 1);
});

test('audio dubbing and subtitles only save their results in the selected folder', function (string $mode) {
    config(['dubbing.audio_provider' => 'elevenlabs', 'transcriber.deepgram.key' => 'test',
        'billing.rates.subtitles.deepgram.nova-2.credits' => '4']);
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Media']);
    $input = ['client_key' => (string) Str::uuid(), 'folder_id' => $folder->id, 'target_language' => 'es', 'source_language' => 'en'];
    if ($mode === 'audio') {
        $this->upload->update(['content_type' => 'audio/mpeg']);
        $this->mock(AudioDubbingMediaInterface::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturn(['size' => 1000, 'duration_ms' => 60000]));
        $input += ['media_type' => 'audio', 'audio_storage_path' => $this->upload->storage_path];
    } else {
        $input += ['operation' => 'subtitles', 'video_storage_path' => $this->upload->storage_path];
    }
    $quote = $this->postJson('/api/v1/dubbings/quotes', $input)->assertAccepted()->json();
    app(DubbingService::class)->measure($quote['id']);
    $record = $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted()->assertJsonPath('folderId', $folder->id)->json();
    $this->getJson('/api/v1/folders/'.$folder->id)->assertOk()->assertJsonPath('projects.0.id', $record['id'])
        ->assertJsonPath('projects.0.type', $mode === 'audio' ? 'dubbing' : 'subtitles')
        ->assertJsonPath('projects.0.mediaType', $mode === 'audio' ? 'audio' : 'video');
})->with(['audio', 'subtitles']);

test('a translated transcript inherits its folder unless the user selects another folder', function () {
    $originalFolder = Folder::create(['user_id' => $this->user->id, 'name' => 'Interviews']);
    $otherFolder = Folder::create(['user_id' => $this->user->id, 'name' => 'Spanish']);
    $transcript = Transcription::factory()->create(['user_id' => $this->user->id, 'transcript' => 'Hello world.']);
    $originalFolder->transcriptions()->attach($transcript->id);
    $quote = folderTranslationQuote($this, ['transcription_id' => $transcript->id]);
    $this->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertAccepted()->assertJsonPath('folderId', $originalFolder->id);
    $quote = folderTranslationQuote($this, ['transcription_id' => $transcript->id, 'folder_id' => $otherFolder->id]);
    $this->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertAccepted()->assertJsonPath('folderId', $otherFolder->id);
});

test('another users folder cannot be selected for translations or dubbing', function () {
    $folder = Folder::create(['user_id' => User::factory()->create()->id, 'name' => 'Private']);
    $this->postJson('/api/v1/translations/quotes', ['folder_id' => $folder->id, 'text' => 'Hello.', 'target_language' => 'es', 'client_key' => (string) Str::uuid()])->assertNotFound();
    $this->postJson('/api/v1/dubbings/quotes', ['folder_id' => $folder->id, 'video_storage_path' => $this->upload->storage_path,
        'target_language' => 'es', 'client_key' => (string) Str::uuid()])->assertNotFound();
    expect(BillingQuote::count())->toBe(0)->and(UsageCharge::count())->toBe(0)->and(OutboxEvent::where('event_type', 'DubbingRequested')->count())->toBe(0);
    Http::assertNothingSent();
});

test('a folder removed after quoting cannot silently redirect a paid confirmation', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Removed']);
    $translationQuote = folderTranslationQuote($this, ['folder_id' => $folder->id]);
    $dubQuote = folderDubbingQuote($this, ['folder_id' => $folder->id]);
    $folder->delete();
    $this->postJson('/api/v1/translations', ['quote_id' => $translationQuote['id']])->assertNotFound();
    $this->postJson('/api/v1/dubbings', ['quote_id' => $dubQuote['id']])->assertNotFound();
    expect(Translation::count())->toBe(0)->and(Dubbing::count())->toBe(0)->and(UsageCharge::count())->toBe(0);
});

test('folder contents distinguish audio video and subtitles and never expose another owners records', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Media']);
    $audio = Dubbing::factory()->create(['folder_id' => $folder->id, 'user_id' => $this->user->id, 'media_type' => 'audio']);
    $subtitles = Dubbing::factory()->create(['folder_id' => $folder->id, 'user_id' => $this->user->id, 'operation' => 'subtitles']);
    Translation::factory()->create(['folder_id' => $folder->id, 'user_id' => User::factory()->create()->id, 'name' => 'Must stay private']);
    $this->getJson('/api/v1/folders/'.$folder->id)->assertOk()->assertJsonCount(2, 'projects')
        ->assertJsonFragment(['id' => $audio->id, 'mediaType' => 'audio'])
        ->assertJsonFragment(['id' => $subtitles->id, 'type' => 'subtitles'])->assertJsonMissing(['name' => 'Must stay private']);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/folders/'.$folder->id)->assertNotFound();
});

test('deleting a populated folder queues permanent deletion for every saved project', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => now()->format('F j, Y')]);
    $translation = Translation::factory()->create(['user_id' => $this->user->id, 'folder_id' => $folder->id]);
    $dubbing = Dubbing::factory()->create(['user_id' => $this->user->id, 'folder_id' => $folder->id]);
    $transcript = Transcription::factory()->create(['user_id' => $this->user->id]);
    $folder->transcriptions()->attach($transcript->id);
    $this->deleteJson('/api/v1/folders/'.$folder->id)->assertNoContent();
    expect(Folder::find($folder->id))->toBeNull()->and(Folder::where('user_id', $this->user->id)->count())->toBe(0)
        ->and(Translation::find($translation->id))->toBeNull()->and(Dubbing::find($dubbing->id))->toBeNull()
        ->and(Transcription::find($transcript->id))->toBeNull();
    $this->assertDatabaseCount('privacy_deletions', 4);
});

test('migration assigns existing projects to owned folders without losing their exports', function () {
    $migration = require database_path('migrations/2026_10_09_022756_add_folders_to_translations_and_dubbings.php');
    $migration->down();
    $sourceFolder = Folder::create(['user_id' => $this->user->id, 'name' => 'Original']);
    $datedFolder = Folder::create(['user_id' => $this->user->id, 'name' => 'October 1, 2026']);
    $transcript = Transcription::factory()->create(['user_id' => $this->user->id]);
    $sourceFolder->transcriptions()->attach($transcript->id);
    $translation = Translation::factory()->create(['user_id' => $this->user->id, 'transcription_id' => $transcript->id,
        'exports' => [['format' => 'txt', 'storage_path' => 'exports/saved.txt']], 'created_at' => '2026-10-01 12:00:00']);
    $dub = Dubbing::factory()->create(['user_id' => $this->user->id, 'audio_storage_path' => 'dub/saved.flac', 'created_at' => '2026-10-01 14:00:00']);
    $pasted = Translation::factory()->create(['user_id' => $this->user->id, 'created_at' => '2026-10-01 16:00:00']);
    $unfiled = Transcription::factory()->create(['user_id' => $this->user->id, 'created_at' => '2026-10-01 17:00:00']);
    $migration->up();
    expect($translation->fresh()->folder_id)->toBe($sourceFolder->id)->and($translation->fresh()->exports[0]['storage_path'])->toBe('exports/saved.txt')
        ->and($dub->fresh()->folder_id)->toBe($datedFolder->id)->and($dub->fresh()->audio_storage_path)->toBe('dub/saved.flac')
        ->and($pasted->fresh()->folder_id)->toBe($datedFolder->id)
        ->and($unfiled->fresh()->folders->modelKeys())->toBe([$datedFolder->id]);
    $this->assertDatabaseCount('folders', 2);
});
