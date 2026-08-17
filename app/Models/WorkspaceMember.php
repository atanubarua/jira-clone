<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\OwnershipException;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\WorkspaceMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's membership of a workspace, carrying their workspace role.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property WorkspaceRole $role
 * @property MembershipStatus $status
 * @property Carbon|null $joined_at
 * @property-read Workspace $workspace
 * @property-read User $user
 */
#[Fillable(['workspace_id', 'user_id', 'role', 'status', 'joined_at'])]
class WorkspaceMember extends Model
{
    /** @use HasFactory<WorkspaceMemberFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids;

    /**
     * Guard the "exactly one owner" invariant at the model layer, so it holds
     * regardless of which service or test writes the row (SPEC Module 1,
     * rules 5 and 6).
     */
    protected static function booted(): void
    {
        static::updating(function (self $member): void {
            $wasOwner = $member->getOriginal('role') === WorkspaceRole::Owner->value;

            // The owner may not be demoted or deactivated directly. Ownership
            // transfer is the only legal path, and it promotes the successor
            // in the same transaction before demoting the incumbent.
            if ($wasOwner && $member->isDirty('status')) {
                throw OwnershipException::cannotRemoveOwner();
            }
        });

        static::deleting(function (self $member): void {
            if ($member->role === WorkspaceRole::Owner) {
                throw OwnershipException::cannotRemoveOwner();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}
