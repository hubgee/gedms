<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            // One row per chunk index: re-uploading a chunk after a network
            // retry is an upsert, never a duplicate.
            $table->unique(['upload_session_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_chunks');
    }
};
