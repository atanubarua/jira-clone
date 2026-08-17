<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The tenant root. Its id is the `workspace_id` carried by every other
 * tenant-owned table.
 *
 * Deliberately NOT tenant-scoped itself - it is the thing being scoped to.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $owner_id
 * @property string|null $logo_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'slug', 'owner_id', 'logo_path'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * The slug addresses the tenant in every URL. Changing it would break every
     * link anyone has ever shared, so it is immutable after creation.
     */
    protected static function booted(): void
    {
        static::updating(function (self $workspace): void {
            if ($workspace->isDirty('slug')) {
                throw new LogicException(
                    'Workspace slugs are immutable: the slug addresses the tenant in '
                    .'every URL. Create a new workspace instead.'
                );
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<WorkspaceMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * The single owner membership. Exactly one exists at all times.
     */
    public function ownerMembership(): ?WorkspaceMember
    {
        return $this->members()
            ->withoutGlobalScopes()
            ->where('role', WorkspaceRole::Owner->value)
            ->first();
    }

    /** @return HasMany<WorkspaceMember, $this> */
    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', MembershipStatus::Active->value);
    }
}
