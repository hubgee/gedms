<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storage_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('name');
            $table->string('mime_type', 100)->nullable();
            $table->string('fingerprint');
            $table->unsignedBigInteger('total_bytes');
            $table->unsignedInteger('chunk_bytes');
            $table->unsignedInteger('total_chunks');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            // Resume lookup: same user + same file fingerprint + still active.
            $table->index(['user_id', 'fingerprint', 'status']);
            // Prune lookup: expired sessions.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
