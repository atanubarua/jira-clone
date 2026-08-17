<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $current = app(TenantContext::class)->current();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            // Drives the workspace switcher. Only workspaces where the user is
            // an ACTIVE member appear, so a deactivated membership disappears
            // from the switcher on their very next request.
            'workspaces' => fn (): array => $user === null ? [] : $user->activeWorkspaces()
                ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug'])
                ->map(fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                ])->all(),
            'currentWorkspace' => $current instanceof Workspace ? [
                'id' => $current->id,
                'name' => $current->name,
                'slug' => $current->slug,
                'role' => $user?->roleIn($current)?->value,
            ] : null,
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'invitationUrl' => fn () => $request->session()->get('invitationUrl'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
