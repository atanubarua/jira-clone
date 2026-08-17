<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\UserHasAuthoredContentException;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $avatar_path
 * @property string $timezone
 * @property string|null $last_workspace_id
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'timezone', 'last_workspace_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * A user who has authored anything is never hard-deleted (SPEC Module 1,
     * rule 7). Deactivate the membership instead.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $user): void {
            if ($user->memberships()->withoutGlobalScopes()->exists()) {
                throw new UserHasAuthoredContentException(
                    "User {$user->id} belongs to one or more workspaces and cannot be "
                    .'hard-deleted. Deactivate the membership instead.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<WorkspaceMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /** @return BelongsTo<Workspace, $this> */
    public function lastWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'last_workspace_id');
    }

    /**
     * The workspaces this user can actually reach.
     */
    /** @return BelongsToMany<Workspace, $this> */
    public function activeWorkspaces(): BelongsToMany
    {
        return $this->workspaces()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->whereNull('workspaces.deleted_at');
    }

    /**
     * This user's membership of the given workspace, regardless of status.
     *
     * Resolved without the tenant scope because membership lookup is what
     * establishes the tenant in the first place.
     */
    public function membershipFor(Workspace $workspace): ?WorkspaceMember
    {
        return WorkspaceMember::withoutGlobalScopes()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $this->getKey())
            ->first();
    }

    /**
     * The user's effective workspace role, or null when they are not an active
     * member. Null means "cannot see this workspace at all".
     */
    public function roleIn(Workspace $workspace): ?WorkspaceRole
    {
        $membership = $this->membershipFor($workspace);

        if (! $membership instanceof WorkspaceMember || ! $membership->status->isActive()) {
            return null;
        }

        return $membership->role;
    }

    public function belongsToWorkspace(Workspace $workspace): bool
    {
        return $this->roleIn($workspace) instanceof WorkspaceRole;
    }
}
