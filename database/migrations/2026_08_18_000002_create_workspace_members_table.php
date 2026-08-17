<?php

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', array_column(WorkspaceRole::cases(), 'value'));
            $table->enum('status', array_column(MembershipStatus::cases(), 'value'))
                ->default(MembershipStatus::Active->value);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
            // Leads with workspace_id so the tenant filter is index-covered
            // (SPEC Module 1, rule 4).
            $table->index(['workspace_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
    }
};
