<?php

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Exceptions\UserHasAuthoredContentException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

it('creates a workspace owned by the registrant', function () {
    $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
    $workspace = Workspace::query()->where('owner_id', $user->id)->firstOrFail();

    expect($workspace->name)->toBe("Ada's Workspace")
        ->and($user->last_workspace_id)->toBe($workspace->id);

    $role = $user->roleIn($workspace);
    expect($role)->toBe(WorkspaceRole::Owner);
});

it('leaves no user without a workspace', function () {
    foreach (['one', 'two', 'three'] as $i => $name) {
        $this->post(route('register.store'), [
            'name' => "User {$name}",
            'email' => "user{$i}@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    // A deliberately cross-tenant question ("is ANY user orphaned?"), so it
    // runs through the sanctioned bypass. Without it the tenant guard throws -
    // which is the guard doing its job.
    $orphans = app(TenantContext::class)->runWithout(
        fn (): array => User::query()->whereDoesntHave('memberships')->pluck('email')->all(),
    );

    expect($orphans)->toBe([]);
});

it('creates the user and workspace atomically', function () {
    // If workspace creation fails, the user must not survive it: there is no
    // user without a workspace in v1.
    $before = User::query()->count();

    try {
        DB::transaction(function (): void {
            $user = User::create([
                'name' => 'Rollback',
                'email' => 'rollback@example.com',
                'password' => 'password',
            ]);

            app(CreateWorkspace::class)->handle($user, 'Doomed');

            throw new RuntimeException('simulated failure after both writes');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(User::query()->count())->toBe($before)
        ->and(Workspace::query()->where('name', 'Doomed')->exists())->toBeFalse();
});

it('gives colliding workspace names distinct slugs', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $create = app(CreateWorkspace::class);

    $first = $create->handle($a, 'Acme');
    $second = $create->handle($b, 'Acme');

    expect($first->slug)->toBe('acme')
        ->and($second->slug)->not->toBe($first->slug)
        ->and($second->slug)->toStartWith('acme-');
});

it('refuses to change a slug once created', function () {
    $workspace = Workspace::factory()->create(['slug' => 'stable']);

    expect(fn () => $workspace->update(['slug' => 'renamed']))
        ->toThrow(LogicException::class);
});

it('never hard-deletes a user who belongs to a workspace', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    app(TenantContext::class)->runFor($workspace, fn (): WorkspaceMember => WorkspaceMember::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]));

    expect(fn () => $user->delete())
        ->toThrow(UserHasAuthoredContentException::class);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});
