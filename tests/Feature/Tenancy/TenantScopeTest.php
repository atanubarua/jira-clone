<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantModels;

it('throws loudly when a tenant-owned model is queried with no workspace bound', function () {
    // The alternative - quietly returning every tenant's rows - is the worst
    // possible failure mode for this application, so it must be impossible.
    app(TenantContext::class)->forget();

    WorkspaceMember::query()->get();
})->throws(TenantNotResolvedException::class);

it('auto-fills workspace_id on create', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $member = app(TenantContext::class)->runFor($workspace, fn (): WorkspaceMember => WorkspaceMember::create([
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
        'joined_at' => now(),
    ]));

    expect($member->workspace_id)->toBe($workspace->id);
});

it('restores the previous tenant after runFor, even when the callback throws', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();
    $tenant = app(TenantContext::class);

    $tenant->set($a);

    try {
        $tenant->runFor($b, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($tenant->current()?->id)->toBe($a->id);
});

it('only bypasses scoping inside runWithout', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();
    $tenant = app(TenantContext::class);

    foreach ([$a, $b] as $workspace) {
        $tenant->runFor($workspace, fn (): WorkspaceMember => WorkspaceMember::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => User::factory()->create()->id,
        ]));
    }

    $scoped = $tenant->runFor($a, fn (): int => WorkspaceMember::query()->count());
    $unscoped = $tenant->runWithout(fn (): int => WorkspaceMember::query()->count());

    expect($scoped)->toBe(1)
        ->and($unscoped)->toBe(2);

    // And the bypass must not persist past the closure.
    $tenant->forget();
    expect(fn () => WorkspaceMember::query()->count())
        ->toThrow(TenantNotResolvedException::class);
});

/**
 * TASKS 1.13 - the unscoped-query guard.
 *
 * Watches real SQL rather than trusting the ORM: any statement that touches a
 * tenant-owned table without a workspace_id predicate is reported.
 */
it('never queries a tenant-owned table without a workspace_id predicate', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $tables = TenantModels::tenantTables();
    expect($tables)->not->toBeEmpty();

    $offenders = [];

    DB::listen(function (QueryExecuted $query) use ($tables, &$offenders): void {
        $sql = $query->sql;

        // Writes carry the column in the payload; reads must carry a predicate.
        if (! preg_match('/^\s*select/i', $sql)) {
            return;
        }

        foreach ($tables as $table) {
            if (preg_match('/\b(from|join)\s+`?'.preg_quote($table, '/').'`?/i', $sql)
                && ! str_contains($sql, 'workspace_id')) {
                $offenders[] = $sql;
            }
        }
    });

    app(TenantContext::class)->runFor($workspace, function () use ($workspace, $user): void {
        WorkspaceMember::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        WorkspaceMember::query()->with('user')->get();
        WorkspaceMember::query()->where('role', 'member')->first();
    });

    expect($offenders)->toBe([], "Unscoped read(s) against tenant-owned tables:\n".implode("\n", $offenders));
});
