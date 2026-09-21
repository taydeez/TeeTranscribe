<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transcriptions')->where('status', 'completed')->update(['status' => 'processing']);
        DB::table('transcriptions')->where('status', 'exports_completed')->update(['status' => 'complete']);
        DB::table('transcriptions')->where('status', 'export_failed')->update(['status' => 'failed']);

        match (DB::getDriverName()) {
            'pgsql' => $this->updatePostgresConstraint(),
            'mysql', 'mariadb' => DB::statement("ALTER TABLE transcriptions MODIFY status ENUM('pending', 'processing', 'failed', 'complete') NOT NULL DEFAULT 'pending'"),
            default => null,
        };
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transcriptions DROP CONSTRAINT IF EXISTS transcriptions_status_check');
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE transcriptions MODIFY status VARCHAR(255) NOT NULL DEFAULT 'pending'");
        }
    }

    private function updatePostgresConstraint(): void
    {
        DB::statement('ALTER TABLE transcriptions DROP CONSTRAINT IF EXISTS transcriptions_status_check');
        DB::statement("ALTER TABLE transcriptions ADD CONSTRAINT transcriptions_status_check CHECK (status IN ('pending', 'processing', 'failed', 'complete'))");
    }
};
