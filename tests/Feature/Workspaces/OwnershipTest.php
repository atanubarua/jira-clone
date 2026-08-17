<?php

use App\Actions\Workspaces\SetMembershipStatus;
use App\Actions\Workspaces\TransferOwnership;
use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\OwnershipException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\TenantContext;

/**
 * @return array{workspace: Workspace, owner: User, member: User}
 */
function ownedWorkspace(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    app(TenantContext::class)->runFor($workspace, function () use ($workspace, $owner, $member): void {
        WorkspaceMember::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
        ]);
        WorkspaceMember::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
        ]);
    });

    return ['workspace' => $workspace, 'owner' => $owner, 'member' => $member];
}

it('keeps exactly one owner at all times', function () {
    ['workspace' => $workspace, 'member' => $member] = ownedWorkspace();

    app(TransferOwnership::class)->handle($workspace, $member);

    $owners = app(TenantContext::class)->runFor($workspace, fn (): int => WorkspaceMember::query()
        ->where('role', WorkspaceRole::Owner->value)
        ->count());

    expect($owners)->toBe(1);
});

it('demotes the previous owner to admin in the same transaction', function () {
    ['workspace' => $workspace, 'owner' => $owner, 'member' => $member] = ownedWorkspace();

    app(TransferOwnership::class)->handle($workspace, $member);

    expect($member->refresh()->roleIn($workspace))->toBe(WorkspaceRole::Owner)
        ->and($owner->refresh()->roleIn($workspace))->toBe(WorkspaceRole::Admin)
        ->and($workspace->refresh()->owner_id)->toBe($member->id);
});

it('refuses to transfer ownership to someone who is not an active member', function () {
    ['workspace' => $workspace, 'member' => $member] = ownedWorkspace();

    app(TenantContext::class)->runFor($workspace, function () use ($member): void {
        WorkspaceMember::query()->where('user_id', $member->id)->first()
            ?->forceFill(['status' => MembershipStatus::Deactivated->value])->saveQuietly();
    });

    expect(fn () => app(TransferOwnership::class)->handle($workspace, $member))
        ->toThrow(OwnershipException::class);
});

it('refuses to transfer ownership to a non-member', function () {
    ['workspace' => $workspace] = ownedWorkspace();
    $stranger = User::factory()->create();

    expect(fn () => app(TransferOwnership::class)->handle($workspace, $stranger))
        ->toThrow(OwnershipException::class);
});

it('cannot deactivate the owner', function () {
    ['workspace' => $workspace, 'owner' => $owner] = ownedWorkspace();

    $membership = app(TenantContext::class)->runFor(
        $workspace,
        fn (): WorkspaceMember => WorkspaceMember::query()->where('user_id', $owner->id)->firstOrFail(),
    );

    expect(fn () => app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated))
        ->toThrow(OwnershipException::class);
});

it('cannot delete the owner membership', function () {
    ['workspace' => $workspace, 'owner' => $owner] = ownedWorkspace();

    $membership = app(TenantContext::class)->runFor(
        $workspace,
        fn (): WorkspaceMember => WorkspaceMember::query()->where('user_id', $owner->id)->firstOrFail(),
    );

    expect(fn () => $membership->delete())->toThrow(OwnershipException::class);
});

it('allows ownership transfer then removal of the former owner', function () {
    ['workspace' => $workspace, 'owner' => $owner, 'member' => $member] = ownedWorkspace();

    app(TransferOwnership::class)->handle($workspace, $member);

    $formerOwner = app(TenantContext::class)->runFor(
        $workspace,
        fn (): WorkspaceMember => WorkspaceMember::query()->where('user_id', $owner->id)->firstOrFail(),
    );

    app(SetMembershipStatus::class)->handle($formerOwner, MembershipStatus::Deactivated);

    expect($formerOwner->refresh()->status)->toBe(MembershipStatus::Deactivated);
});
