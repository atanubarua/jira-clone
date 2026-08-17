<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pending invitation to join a workspace (SPEC Module 1, rules 8 and 9).
 *
 * The plaintext token exists only in the invite URL. What is stored is its
 * SHA-256 hash, so a database leak does not yield usable invite links.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $email
 * @property WorkspaceRole $role
 * @property string $token_hash
 * @property string $invited_by_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property-read Workspace $workspace
 * @property-read User $invitedBy
 */
#[Fillable(['workspace_id', 'email', 'role', 'token_hash', 'invited_by_id', 'expires_at'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids;

    /**
     * Invitations are valid for 14 days (SPEC Module 1).
     */
    public const TTL_DAYS = 14;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Generate a new plaintext token. Only ever returned to the caller once,
     * to be embedded in the invite URL.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Constant-time comparison against this invitation's stored hash.
     *
     * Lookup is already done by hash (an indexed equality match), but the
     * explicit hash_equals makes the guarantee obvious at the call site.
     */
    public function tokenMatches(string $token): bool
    {
        return hash_equals($this->token_hash, self::hashToken($token));
    }

    /** @param  Builder<Invitation>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isLive(): bool
    {
        return ! $this->accepted_at
            && ! $this->revoked_at
            && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }
}
