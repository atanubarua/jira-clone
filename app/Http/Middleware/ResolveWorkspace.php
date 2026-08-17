<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for the current request (SPEC Module 1, rule 1).
 *
 * The workspace is taken ONLY from the {workspace} route segment. It is never
 * read from a request body, header or query parameter - that is what makes a
 * forged `workspace_id` in a payload inert.
 *
 * A user who is not an active member of an existing workspace gets 404, not
 * 403: 403 would confirm the workspace exists, leaking tenant names.
 */
class ResolveWorkspace
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $parameter = $request->route('workspace');

        // Workspace is not tenant-scoped, so route-model binding resolves it
        // safely before this middleware runs. Reuse the bound instance rather
        // than querying for it twice.
        if ($parameter instanceof Workspace) {
            $workspace = $parameter;
        } else {
            if (! is_string($parameter) || $parameter === '') {
                abort(404);
            }

            $workspace = Workspace::query()->where('slug', $parameter)->first();
        }

        if (! $workspace instanceof Workspace) {
            abort(404);
        }

        $user = $request->user();

        // Not authenticated is handled by the `auth` middleware upstream; this
        // is belt-and-braces so the tenant can never be bound anonymously.
        if ($user === null || ! $user->belongsToWorkspace($workspace)) {
            abort(404);
        }

        $this->tenant->set($workspace);

        // Remember where they were, so the next login lands somewhere useful.
        if ($user->last_workspace_id !== $workspace->getKey()) {
            $user->forceFill(['last_workspace_id' => $workspace->getKey()])->saveQuietly();
        }

        return $next($request);
    }
}
