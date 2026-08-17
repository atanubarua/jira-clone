<?php

use App\Enums\WorkspaceRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            // Not necessarily an existing user - accepting creates one.
            $table->string('email');
            // Owner is excluded: ownership is transferred, never invited.
            $table->enum('role', WorkspaceRole::invitableValues());
            // SHA-256 of the token. The plaintext is only ever in the invite URL.
            $table->string('token_hash', 64)->unique();
            $table->foreignUlid('invited_by_id')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'email']);
            $table->index(['workspace_id', 'accepted_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
