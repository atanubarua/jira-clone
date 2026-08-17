<?php

use App\Actions\Workspaces\AcceptInvitation;
use App\Actions\Workspaces\InviteMember;
use App\Enums\WorkspaceRole;
use App\Exceptions\InvalidInvitationException;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\TenantContext;

/**
 * @return array{workspace: Workspace, owner: User}
 */
function invitingWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    app(TenantContext::class)->runFor($workspace, fn (): WorkspaceMember => WorkspaceMember::factory()
        ->owner()
        ->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]));

    return ['workspace' => $workspace, 'owner' => $owner];
}

it('stores only a hash of the invitation token', function () {
    ['workspace' => $workspace, 'owner' => $owner] = invitingWorkspace();

    ['invitation' => $invitation, 'token' => $token] = app(InviteMember::class)
        ->handle($workspace, $owner, 'new@example.com', WorkspaceRole::Member);

    expect($invitation->token_hash)->not->toBe($token)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->toHaveLength(64);

    // The plaintext must not be recoverable from the row.
    expect($invitation->getAttributes())->not->toHaveKey('token');
});

it('accepts an invitation for a brand new user, creating them in the same transaction', function () {
    ['workspace' => $workspace, 'owner' => $owner] = invitingWorkspace();

    ['token' => $token] = app(InviteMember::class)
        ->handle($workspace, $owner, 'newcomer@example.com', WorkspaceRole::Member);

    $membership = app(AcceptInvitation::class)->forNewUser($token, [
        'name' => 'New Comer',
        'password' => 'password',
    ]);

    $user = User::query()->where('email', 'newcomer@example.com')->firstOrFail();

    expect($membership->user_id)->toBe($user->id)
        ->and($user->roleIn($workspace))->toBe(WorkspaceRole::Member)
        ->and($user->last_workspace_id)->toBe($workspace->id);
});

it('accepts an invitation for an existing user by adding a membership', function () {
    ['workspace' => $workspace, 'owner' => $owner] = invitingWorkspace();
    $existing = User::factory()->create(['email' => 'existing@example.com']);

    ['token' => $token] = app(InviteMember::class)
        ->handle($workspace, $owner, 'existing@example.com', WorkspaceRole::Admin);

    app(AcceptInvitation::class)->forExistingUser($token, $existing);

    expect($existing->refresh()->roleIn($workspace))->toBe(WorkspaceRole::Admin)
        ->and(User::query()->where('email', 'existing@example.com')->count())->toBe(1);
});

it('rejects an expired invitation', function () {
    ['workspace' => $workspace, 'owner' => $owner] = invitingWorkspace();
    $token = Invitation::generateToken();

    app(TenantContext::class)->runFor($workspace, fn (): Invitation => Invitation::factory()
        ->withToken($token)
        ->expired()
        ->create(['workspace_id' => $workspace->id, 'invited_by_id' => $owner->id]));

    expect(fn () => app(AcceptInvitation::class)->resolve($token))
        ->toThrow(InvalidInvitationException::class);
});

it('rejects a token that has already been used', function () {
    ['workspace' => $workspace, 'owner' => $owner] = invitingWorkspace();

    ['token' => $token] = app(InviteMember::class)
        ->handle($workspace, $owner, 'once@example.com', WorkspaceRole::Member);

    app(AcceptInvitation::class)->forNewUser($token, ['name' => 'Once', 'password' => 'password']);

    // Single-use: replaying a leaked link must fail.
    expect(fn () => app(AcceptInvitation::class)->resolve($token))
        ->toThrow(InvalidInvitationException::class);
});

it('rejects a revoked invitation', function () {
    ['workspace' => $workspace, 'owner' => $owner] = invitingWorkspace();
    $token = Invitation::generateToken();

    app(TenantContext::class)->runFor($workspace, fn (): Invitation => Invitation::factory()
        ->withToken($token)
        ->revoked()
        ->create(['workspace_id' => $workspace->id, 'invited_by_id' => $owner->id]));

    expect(fn () => app(AcceptInvitation::class)->resolve($token))
        ->toThrow(InvalidInvitationException::class);
});

it('rejects an unknown token', function () {
    expect(fn () => app(AcceptInvitation::class)->resolve(Invitation::generateToken()))
        ->toThrow(InvalidInvitationException::class);
});

it('keeps only one live invitation per email per workspace', function () {
    ['workspace' => $workspace, 'owner' => $owner] = invitingWorkspace();
    $invite = app(InviteMember::class);

    ['token' => $first] = $invite->handle($workspace, $owner, 'dup@example.com', WorkspaceRole::Member);
    ['token' => $second] = $invite->handle($workspace, $owner, 'dup@example.com', WorkspaceRole::Admin);

    $live = app(TenantContext::class)->runFor(
        $workspace,
        fn (): int => Invitation::query()->live()->where('email', 'dup@example.com')->count(),
    );

    expect($live)->toBe(1);

    // Re-inviting rotates the token: the superseded link stops working.
    expect(fn () => app(AcceptInvitation::class)->resolve($first))
        ->toThrow(InvalidInvitationException::class);

    expect(app(AcceptInvitation::class)->resolve($second)->role)->toBe(WorkspaceRole::Admin);
});

it('never issues an owner invitation', function () {
    expect(WorkspaceRole::invitableValues())->not->toContain(WorkspaceRole::Owner->value);
});

it('does not let an invitation grant access to a different workspace', function () {
    ['workspace' => $workspace, 'owner' => $owner] = invitingWorkspace();
    $other = Workspace::factory()->create();

    ['token' => $token] = app(InviteMember::class)
        ->handle($workspace, $owner, 'scoped@example.com', WorkspaceRole::Member);

    $membership = app(AcceptInvitation::class)
        ->forNewUser($token, ['name' => 'Scoped', 'password' => 'password']);

    $user = $membership->user;

    expect($user->belongsToWorkspace($workspace))->toBeTrue()
        ->and($user->belongsToWorkspace($other))->toBeFalse();
});
