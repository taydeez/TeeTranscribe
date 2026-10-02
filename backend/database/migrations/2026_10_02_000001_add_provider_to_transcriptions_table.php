<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table): void {
            $table->string('provider')->default('deepgram')->after('duration')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table): void {
            $table->dropIndex(['provider']);
            $table->dropColumn('provider');
        });
    }
};
