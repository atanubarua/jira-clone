<?php

use App\Actions\Workspaces\SetMembershipStatus;
use App\Enums\MembershipStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\TenantContext;

/**
 * @return array{workspace: Workspace, owner: User, member: User, membership: WorkspaceMember}
 */
function deactivatableWorkspace(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    $membership = app(TenantContext::class)->runFor($workspace, function () use ($workspace, $owner, $member): WorkspaceMember {
        WorkspaceMember::factory()->owner()->create([
            'workspace_id' => $workspace->id, 'user_id' => $owner->id,
        ]);

        return WorkspaceMember::factory()->create([
            'workspace_id' => $workspace->id, 'user_id' => $member->id,
        ]);
    });

    return compact('workspace', 'owner', 'member', 'membership');
}

it('revokes workspace access on the next request after deactivation', function () {
    ['workspace' => $workspace, 'member' => $member, 'membership' => $membership] = deactivatableWorkspace();

    $this->actingAs($member)->get("/w/{$workspace->slug}")->assertOk();

    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated);

    // Access is revoked at the tenant boundary, so it takes effect immediately
    // and without disturbing this user's OTHER workspaces.
    $this->actingAs($member)->get("/w/{$workspace->slug}")->assertNotFound();
});

it('scopes deactivation to one workspace', function () {
    ['workspace' => $workspace, 'member' => $member, 'membership' => $membership] = deactivatableWorkspace();

    $other = Workspace::factory()->create();
    app(TenantContext::class)->runFor($other, fn (): WorkspaceMember => WorkspaceMember::factory()->create([
        'workspace_id' => $other->id, 'user_id' => $member->id,
    ]));

    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated);

    $this->actingAs($member)->get("/w/{$workspace->slug}")->assertNotFound();
    // Still an active member elsewhere - a global logout would be wrong.
    $this->actingAs($member)->get("/w/{$other->slug}")->assertOk();
});

it('preserves the deactivated user and their record', function () {
    ['member' => $member, 'membership' => $membership] = deactivatableWorkspace();

    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated);

    expect(User::query()->whereKey($member->id)->exists())->toBeTrue()
        ->and($membership->refresh()->status)->toBe(MembershipStatus::Deactivated)
        ->and($membership->joined_at)->not->toBeNull();
});

it('drops a deactivated workspace from the switcher', function () {
    ['workspace' => $workspace, 'member' => $member, 'membership' => $membership] = deactivatableWorkspace();

    expect($member->activeWorkspaces()->pluck('workspaces.id')->all())->toContain($workspace->id);

    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated);

    expect($member->refresh()->activeWorkspaces()->pluck('workspaces.id')->all())
        ->not->toContain($workspace->id);
});

it('clears last_workspace_id so the user is not stranded', function () {
    ['workspace' => $workspace, 'member' => $member, 'membership' => $membership] = deactivatableWorkspace();

    $member->forceFill(['last_workspace_id' => $workspace->id])->save();

    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated);

    expect($member->refresh()->last_workspace_id)->toBeNull();
});

it('can reactivate a deactivated member', function () {
    ['workspace' => $workspace, 'member' => $member, 'membership' => $membership] = deactivatableWorkspace();

    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated);
    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Active);

    $this->actingAs($member)->get("/w/{$workspace->slug}")->assertOk();
});

it('lets an admin deactivate a member over HTTP', function () {
    ['workspace' => $workspace, 'owner' => $owner, 'membership' => $membership] = deactivatableWorkspace();

    $this->actingAs($owner)
        ->patch("/w/{$workspace->slug}/members/{$membership->id}", ['status' => 'deactivated'])
        ->assertRedirect();

    expect($membership->refresh()->status)->toBe(MembershipStatus::Deactivated);
});
