<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->changeFormats(['txt', 'pdf', 'docx']);
        Schema::table('transcription_exports', function (Blueprint $table) {
            $table->dropUnique(['transcription_id', 'format']);
            $table->string('variant')->default('plain');
            $table->unique(['transcription_id', 'format', 'variant'], 'transcription_export_variant_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('transcription_exports')->where('format', 'docx')->orWhere('variant', 'speakers')->delete();
        $this->changeFormats(['txt', 'pdf']);
        Schema::table('transcription_exports', function (Blueprint $table) {
            $table->dropUnique('transcription_export_variant_unique');
            $table->dropColumn('variant');
            $table->unique(['transcription_id', 'format']);
        });
    }

    private function changeFormats(array $formats): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transcription_exports DROP CONSTRAINT IF EXISTS transcription_exports_format_check');
            $values = implode(', ', array_map(fn (string $format): string => "'{$format}'", $formats));
            DB::statement("ALTER TABLE transcription_exports ADD CONSTRAINT transcription_exports_format_check CHECK (format IN ({$values}))");

            return;
        }
        Schema::table('transcription_exports', function (Blueprint $table) use ($formats) {
            $table->enum('format', $formats)->change();
        });
    }
};
