<?php

use App\Models\Invitation;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\TenantContext;
use Tests\Support\IsolationFixture;
use Tests\Support\IsolationRoutes;

/**
 * The cross-tenant isolation suite (TASKS 1.12).
 *
 * Every tenant-scoped route in the application is driven through here. If you
 * are adding a route and this file did not change, you have almost certainly
 * shipped a leak.
 */
it('returns 404 when reaching another workspace by URL', function (string $name) {
    $case = IsolationRoutes::all()[$name];
    $fixture = IsolationFixture::make();

    $response = $this->actingAs($fixture->user)
        ->{$case['method']}(($case['uri'])($fixture), $case['payload']);

    // 404, never 403: a 403 would confirm the resource exists.
    expect($response->getStatusCode())->toBe(404, "[{$name}] leaked a non-404 status");
})->with(array_keys(IsolationRoutes::all()));

it('ignores another workspace id supplied in the request body', function (string $name) {
    $case = IsolationRoutes::bodyTampering()[$name];
    $fixture = IsolationFixture::make();

    $payload = array_map(
        fn (mixed $value): mixed => $value === '__FOREIGN_WORKSPACE__' ? $fixture->foreign->id : $value,
        $case['payload'],
    );

    $this->actingAs($fixture->user)
        ->{$case['method']}(($case['uri'])($fixture), $payload);

    // The foreign workspace must be untouched: the tenant comes from the URL
    // segment alone, so a forged body id is inert.
    $fixture->foreign->refresh();
    expect($fixture->foreign->name)->not->toBe('Renamed Home');

    $foreignInvites = app(TenantContext::class)->runFor(
        $fixture->foreign,
        fn (): int => Invitation::query()->count(),
    );
    expect($foreignInvites)->toBe(1, "[{$name}] wrote an invitation into the wrong tenant");
})->with(array_keys(IsolationRoutes::bodyTampering()));

it('scopes tenant-owned queries to the bound workspace', function () {
    $fixture = IsolationFixture::make();
    $tenant = app(TenantContext::class);

    $home = $tenant->runFor($fixture->home, fn (): int => WorkspaceMember::query()->count());
    $foreign = $tenant->runFor($fixture->foreign, fn (): int => WorkspaceMember::query()->count());

    // Same query object, different tenants, different rows.
    expect($home)->toBe(2)
        ->and($foreign)->toBe(1);

    $ids = $tenant->runFor($fixture->home, fn (): array => WorkspaceMember::query()->pluck('workspace_id')->unique()->all());
    expect($ids)->toBe([$fixture->home->id]);
});

it('cannot reach another tenant row by primary key', function () {
    $fixture = IsolationFixture::make();

    $found = app(TenantContext::class)->runFor(
        $fixture->home,
        fn (): ?WorkspaceMember => WorkspaceMember::query()->find($fixture->foreignMember->id),
    );

    expect($found)->toBeNull();
});

it('does not leak whether a foreign workspace exists', function () {
    $fixture = IsolationFixture::make();

    $real = $this->actingAs($fixture->user)->get("/w/{$fixture->foreign->slug}");
    $fake = $this->actingAs($fixture->user)->get('/w/definitely-not-a-real-workspace');

    // Identical responses: an attacker cannot enumerate tenant slugs.
    expect($real->getStatusCode())->toBe($fake->getStatusCode())->toBe(404);
});

it('redirects unauthenticated visitors to login rather than 404', function () {
    $workspace = Workspace::factory()->create();

    $this->get("/w/{$workspace->slug}")->assertRedirect(route('login'));
});
