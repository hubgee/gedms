<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_spaces', function (Blueprint $table) {
            // Null means "use the global driver from config/uploads.php".
            $table->string('storage_driver', 32)->nullable()->after('type');
            // Encrypted JSON: credentials, root, endpoint, etc. Null when unused.
            $table->text('storage_config')->nullable()->after('storage_driver');
        });
    }

    public function down(): void
    {
        Schema::table('storage_spaces', function (Blueprint $table) {
            $table->dropColumn(['storage_driver', 'storage_config']);
        });
    }
};
