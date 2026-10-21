<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery bookkeeping for the upload assembly step.
 *
 * A session that is left in "assembling" because its worker died has to be
 * retried, but only a bounded number of times — otherwise a file whose chunks
 * are genuinely gone would be requeued forever. This counter is what makes that
 * bound possible, and also records when the session last entered "assembling"
 * so recovery can tell "still running" from "abandoned".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('assembling_attempts')->default(0)->after('status');
            $table->timestamp('assembling_since')->nullable()->after('assembling_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropColumn(['assembling_attempts', 'assembling_since']);
        });
    }
};
