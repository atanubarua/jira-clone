<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspaces\SetMembershipStatus;
use App\Actions\Workspaces\TransferOwnership;
use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateMemberRequest;
use App\Models\Invitation;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    #[Authorize('viewSettings', 'workspace')]
    public function index(Workspace $workspace): Response
    {
        // WorkspaceMember and Invitation are tenant-scoped, so these queries
        // are already constrained to the resolved workspace.
        $members = WorkspaceMember::query()
            ->with('user:id,name,email,avatar_path')
            ->orderBy('created_at')
            ->get()
            ->map(fn (WorkspaceMember $member): array => [
                'id' => $member->id,
                'role' => $member->role->value,
                'status' => $member->status->value,
                'joinedAt' => $member->joined_at?->toIso8601String(),
                'user' => [
                    'id' => $member->user->id,
                    'name' => $member->user->name,
                    'email' => $member->user->email,
                ],
            ]);

        $invitations = Invitation::query()->live()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ]);

        return Inertia::render('workspaces/members', [
            'workspace' => [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'members' => $members,
            'invitations' => $invitations,
            'roles' => WorkspaceRole::invitableValues(),
            'can' => [
                'manageMembers' => request()->user()->can('manageMembers', $workspace),
                'transferOwnership' => request()->user()->can('transferOwnership', $workspace),
            ],
        ]);
    }

    #[Authorize('manageMembers', 'workspace')]
    public function update(
        UpdateMemberRequest $request,
        Workspace $workspace,
        string $memberId,
        SetMembershipStatus $setStatus,
        TransferOwnership $transferOwnership,
    ): RedirectResponse {
        // Resolved here rather than by route-model binding: WorkspaceMember is
        // tenant-scoped, and binding runs before ResolveWorkspace. Going
        // through the scoped query means another workspace's member id simply
        // does not exist -> 404, which is the behaviour SPEC wants anyway.
        $member = WorkspaceMember::query()->findOrFail($memberId);

        if ($request->has('status')) {
            $setStatus->handle($member, MembershipStatus::from($request->validated('status')));

            return back()->with('status', 'member-updated');
        }

        $role = WorkspaceRole::from($request->validated('role'));

        if ($role === WorkspaceRole::Owner) {
            // Promotion to owner is a transfer, and only the current owner may
            // do it - a separate capability from managing members.
            $this->authorize('transferOwnership', $workspace);

            $transferOwnership->handle($workspace, $member->user);

            return back()->with('status', 'ownership-transferred');
        }

        $member->forceFill(['role' => $role->value])->saveQuietly();

        return back()->with('status', 'member-updated');
    }
}
