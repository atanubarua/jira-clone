<?php

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Tests\Support\IsolationRoutes;
use Tests\Support\TenantModels;

/**
 * Architecture guards (TASKS 1.2 and 1.12).
 *
 * These are the tests that keep the tenancy invariants true as the model layer
 * grows through Phases 2-7. A model that forgets BelongsToWorkspace is a
 * cross-tenant data leak, and it must fail CI rather than ship.
 */
it('gives every tenant-owned model the BelongsToWorkspace trait', function () {
    $offenders = [];

    foreach (TenantModels::tenantOwned() as $model) {
        if (! in_array(BelongsToWorkspace::class, class_uses_recursive($model), true)) {
            $offenders[] = $model;
        }
    }

    expect($offenders)->toBe([], sprintf(
        'These models are tenant-owned but do not use BelongsToWorkspace, which means '
        ."their queries are NOT scoped to a workspace:\n  - %s\n\n"
        .'Add the trait, or add the class to TenantModels::NOT_TENANT_OWNED if it is '
        .'genuinely global.',
        implode("\n  - ", $offenders),
    ));
});

it('gives every model ULID primary keys', function () {
    $offenders = [];

    foreach (TenantModels::all() as $model) {
        if (! in_array(HasUlids::class, class_uses_recursive($model), true)) {
            $offenders[] = $model;
        }
    }

    expect($offenders)->toBe([], sprintf(
        "SPEC A-5 requires ULID primary keys on every model. Missing HasUlids:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

it('keeps the Auth facade out of policies', function () {
    // SPEC section 4, rule 5: a policy that reads Auth::user() assumes a full
    // session and cannot be reused for token-scoped API callers in Phase 7.
    $offenders = [];

    foreach (glob(app_path('Policies/*.php')) ?: [] as $file) {
        $contents = (string) file_get_contents($file);

        if (preg_match('/\bAuth::|\bauth\(\)/', $contents)) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], sprintf(
        'Policies must take the acting User as a parameter, never read it from the '
        ."Auth facade:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

it('registers every tenant-scoped route with the isolation suite', function () {
    // A route under /w/{workspace} that nobody added to IsolationRoutes is an
    // untested cross-tenant surface.
    $registered = collect(IsolationRoutes::all())
        ->map(fn (array $case): string => $case['method'])
        ->count();

    $tenantRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'w/{workspace}'))
        ->count();

    expect($registered)->toBe($tenantRoutes, sprintf(
        'There are %d routes under /w/{workspace} but %d cases in IsolationRoutes. '
        .'Every tenant-scoped route needs an isolation case - see tests/Support/IsolationRoutes.php.',
        $tenantRoutes,
        $registered,
    ));
});
