<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['translations', 'dubbings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignUlid('folder_id')->nullable()->constrained('folders')->restrictOnDelete();
                $table->index(['folder_id', 'created_at']);
            });
        }

        foreach (['transcriptions', 'translations', 'dubbings'] as $tableName) {
            DB::table($tableName)->whereNotNull('user_id')
                ->when($tableName === 'transcriptions', fn ($query) => $query->whereNotExists(fn ($pivot) => $pivot->selectRaw('1')
                    ->from('folder_transcription')->join('folders', 'folders.id', '=', 'folder_transcription.folder_id')
                    ->whereColumn('folder_transcription.transcription_id', 'transcriptions.id')->whereColumn('folders.user_id', 'transcriptions.user_id')))
                ->orderBy('id')->chunkById(200, function ($records) use ($tableName): void {
                    foreach ($records as $record) {
                        $folderId = null;
                        if ($tableName === 'translations' && $record->transcription_id !== null) {
                            $folderId = DB::table('folder_transcription')->join('folders', 'folders.id', '=', 'folder_transcription.folder_id')
                                ->where('transcription_id', $record->transcription_id)->where('folders.user_id', $record->user_id)
                                ->orderBy('folders.id')->value('folders.id');
                        }
                        if ($folderId === null) {
                            $name = (new DateTimeImmutable($record->created_at ?? 'now'))->format('F j, Y');
                            $folderId = DB::table('folders')->where('user_id', $record->user_id)->where('name', $name)->orderBy('id')->value('id');
                            if ($folderId === null) {
                                $folderId = (string) Str::ulid();
                                DB::table('folders')->insert(['id' => $folderId, 'user_id' => $record->user_id, 'name' => $name,
                                    'created_at' => $record->created_at ?? now(), 'updated_at' => now()]);
                            }
                        }
                        if ($tableName === 'transcriptions') {
                            DB::table('folder_transcription')->insert(['id' => (string) Str::ulid(), 'folder_id' => $folderId, 'transcription_id' => $record->id]);
                        } else {
                            DB::table($tableName)->where('id', $record->id)->update(['folder_id' => $folderId]);
                        }
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['translations', 'dubbings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['folder_id', 'created_at']);
                $table->dropConstrainedForeignId('folder_id');
            });
        }
    }
};
