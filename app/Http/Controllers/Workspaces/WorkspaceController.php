<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspaces\CreateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * The workspace overview. Reaching this route at all already proves active
     * membership - ResolveWorkspace 404s otherwise.
     */
    #[Authorize('view', 'workspace')]
    public function show(Workspace $workspace): Response
    {
        return Inertia::render('workspaces/show', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'memberCount' => $workspace->activeMembers()->count(),
        ]);
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $workspace = $createWorkspace->handle(
            $request->user(),
            $request->validated('name'),
        );

        return to_route('workspaces.show', $workspace->slug);
    }

    #[Authorize('update', 'workspace')]
    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        // Slug is immutable (SPEC Module 2, rule 1 rationale applies here too):
        // it addresses the tenant in every URL anyone has shared.
        $workspace->update(['name' => $request->validated('name')]);

        return back()->with('status', 'workspace-updated');
    }
}
