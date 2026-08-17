<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\TenantContext;

/**
 * The workspace capability matrix from SPEC section 4, asserted row by row for
 * all four roles.
 *
 * This test is the executable form of that table. If the table changes, this
 * changes with it - and if they ever disagree, this fails.
 *
 * @return array<string, array{owner: bool, admin: bool, member: bool, guest: bool}>
 */
function workspaceCapabilityMatrix(): array
{
    return [
        //                          owner  admin  member guest
        'view' => ['owner' => true, 'admin' => true, 'member' => true, 'guest' => true],
        'viewSettings' => ['owner' => true, 'admin' => true, 'member' => true, 'guest' => false],
        'update' => ['owner' => true, 'admin' => true, 'member' => false, 'guest' => false],
        'invite' => ['owner' => true, 'admin' => true, 'member' => false, 'guest' => false],
        'inviteAdmin' => ['owner' => true, 'admin' => true, 'member' => false, 'guest' => false],
        'manageMembers' => ['owner' => true, 'admin' => true, 'member' => false, 'guest' => false],
        'transferOwnership' => ['owner' => true, 'admin' => false, 'member' => false, 'guest' => false],
        'delete' => ['owner' => true, 'admin' => false, 'member' => false, 'guest' => false],
        'manageBilling' => ['owner' => true, 'admin' => false, 'member' => false, 'guest' => false],
        'createProject' => ['owner' => true, 'admin' => true, 'member' => true, 'guest' => false],
        'manageLabels' => ['owner' => true, 'admin' => true, 'member' => true, 'guest' => false],
        'viewAllProjects' => ['owner' => true, 'admin' => true, 'member' => false, 'guest' => false],
    ];
}

function memberWithRole(WorkspaceRole $role): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    app(TenantContext::class)->runFor($workspace, fn (): WorkspaceMember => WorkspaceMember::factory()
        ->role($role)
        ->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]));

    return [$user, $workspace];
}

it('enforces the SPEC section 4 workspace capability matrix', function (string $ability) {
    $expectations = workspaceCapabilityMatrix()[$ability];

    foreach ($expectations as $roleValue => $expected) {
        [$user, $workspace] = memberWithRole(WorkspaceRole::from($roleValue));

        expect($user->can($ability, $workspace))->toBe(
            $expected,
            sprintf(
                'SPEC section 4: a %s should %s be able to "%s"',
                $roleValue,
                $expected ? '' : 'NOT',
                $ability,
            ),
        );
    }
})->with(array_keys(workspaceCapabilityMatrix()));

it('grants a non-member nothing at all', function () {
    $workspace = Workspace::factory()->create();
    $stranger = User::factory()->create();

    foreach (array_keys(workspaceCapabilityMatrix()) as $ability) {
        expect($stranger->can($ability, $workspace))->toBeFalse("a non-member could {$ability}");
    }
});

it('grants a deactivated member nothing at all', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    app(TenantContext::class)->runFor($workspace, fn (): WorkspaceMember => WorkspaceMember::factory()
        ->admin()
        ->deactivated()
        ->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]));

    foreach (array_keys(workspaceCapabilityMatrix()) as $ability) {
        expect($user->can($ability, $workspace))->toBeFalse("a deactivated admin could {$ability}");
    }
});

it('returns 403 rather than 404 when a member may see the workspace but not the action', function () {
    // SPEC section 4, rule 3: 403 is correct precisely when the user CAN see
    // the object. A guest can see the workspace they belong to, but not its
    // member settings.
    [$guest, $workspace] = memberWithRole(WorkspaceRole::Guest);

    $this->actingAs($guest)->get("/w/{$workspace->slug}")->assertOk();
    $this->actingAs($guest)->get("/w/{$workspace->slug}/members")->assertForbidden();
});

it('lets a member read settings but not change them', function () {
    [$member, $workspace] = memberWithRole(WorkspaceRole::Member);

    $this->actingAs($member)->get("/w/{$workspace->slug}/members")->assertOk();
    $this->actingAs($member)
        ->patch("/w/{$workspace->slug}", ['name' => 'Renamed'])
        ->assertForbidden();

    expect($workspace->refresh()->name)->not->toBe('Renamed');
});

it('stops an admin from transferring ownership', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    $adminMembership = app(TenantContext::class)->runFor($workspace, function () use ($workspace, $owner, $admin): WorkspaceMember {
        WorkspaceMember::factory()->owner()->create([
            'workspace_id' => $workspace->id, 'user_id' => $owner->id,
        ]);

        return WorkspaceMember::factory()->admin()->create([
            'workspace_id' => $workspace->id, 'user_id' => $admin->id,
        ]);
    });

    $this->actingAs($admin)
        ->patch("/w/{$workspace->slug}/members/{$adminMembership->id}", ['role' => 'owner'])
        ->assertForbidden();

    expect($workspace->refresh()->owner_id)->toBe($owner->id);
});
