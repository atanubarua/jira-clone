<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            // Globally unique: it addresses the tenant in the URL (/w/{slug}).
            $table->string('slug', 60)->unique();
            $table->foreignUlid('owner_id')->constrained('users');
            $table->string('logo_path')->nullable();
            $table->timestamps();
            // Soft delete is suspension, not erasure (SPEC Module 1).
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            // Where to land after login. Convenience only - never authorization.
            $table->foreignUlid('last_workspace_id')
                ->nullable()
                ->after('timezone')
                ->constrained('workspaces')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_workspace_id');
        });

        Schema::dropIfExists('workspaces');
    }
};
