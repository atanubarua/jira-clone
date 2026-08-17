<?php

namespace Tests\Support;

use Closure;

/**
 * THE cross-tenant isolation registry (TASKS 1.12).
 *
 * ---------------------------------------------------------------------------
 * EVERY new tenant-scoped route MUST be added here, in the same change that
 * adds the route. This is not optional housekeeping - a route missing from
 * this list is an untested cross-tenant data leak.
 * ---------------------------------------------------------------------------
 *
 * Each entry produces one test case: act as a user who owns workspace A, aim
 * the request at workspace B's data, and require a 404. Never a 403 - that
 * would confirm the resource exists.
 *
 * @phpstan-type RouteCase array{method: string, uri: Closure(IsolationFixture): string, payload: array<string, mixed>}
 */
final class IsolationRoutes
{
    /**
     * @return array<string, RouteCase>
     */
    public static function all(): array
    {
        return [
            // --- Phase 1: tenancy -------------------------------------------
            'workspace overview' => [
                'method' => 'get',
                'uri' => fn (IsolationFixture $f): string => "/w/{$f->foreign->slug}",
                'payload' => [],
            ],
            'workspace update' => [
                'method' => 'patch',
                'uri' => fn (IsolationFixture $f): string => "/w/{$f->foreign->slug}",
                'payload' => ['name' => 'Taken Over'],
            ],
            'members index' => [
                'method' => 'get',
                'uri' => fn (IsolationFixture $f): string => "/w/{$f->foreign->slug}/members",
                'payload' => [],
            ],
            'member update' => [
                'method' => 'patch',
                'uri' => fn (IsolationFixture $f): string => "/w/{$f->foreign->slug}/members/{$f->foreignMember->id}",
                'payload' => ['role' => 'guest'],
            ],
            'invitation store' => [
                'method' => 'post',
                'uri' => fn (IsolationFixture $f): string => "/w/{$f->foreign->slug}/invitations",
                'payload' => ['email' => 'intruder@example.com', 'role' => 'admin'],
            ],
            'invitation revoke' => [
                'method' => 'delete',
                'uri' => fn (IsolationFixture $f): string => "/w/{$f->foreign->slug}/invitations/{$f->foreignInvitation->id}",
                'payload' => [],
            ],

            // --- Phase 2+: append new routes here ---------------------------
        ];
    }

    /**
     * Cases where the URL is the caller's OWN workspace but the BODY carries
     * another tenant's id. The foreign id must be inert, never honoured.
     *
     * @return array<string, RouteCase>
     */
    public static function bodyTampering(): array
    {
        return [
            'workspace update with foreign workspace_id in body' => [
                'method' => 'patch',
                'uri' => fn (IsolationFixture $f): string => "/w/{$f->home->slug}",
                'payload' => [
                    'name' => 'Renamed Home',
                    'workspace_id' => '__FOREIGN_WORKSPACE__',
                    'id' => '__FOREIGN_WORKSPACE__',
                ],
            ],
            'invitation store with foreign workspace_id in body' => [
                'method' => 'post',
                'uri' => fn (IsolationFixture $f): string => "/w/{$f->home->slug}/invitations",
                'payload' => [
                    'email' => 'someone@example.com',
                    'role' => 'member',
                    'workspace_id' => '__FOREIGN_WORKSPACE__',
                ],
            ],
        ];
    }
}
