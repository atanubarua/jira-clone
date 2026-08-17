<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspaces\InviteMember;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreInvitationRequest;
use App\Models\Invitation;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;

#[Authorize('invite', 'workspace')]
class InvitationController extends Controller
{
    public function store(
        StoreInvitationRequest $request,
        Workspace $workspace,
        InviteMember $inviteMember,
    ): RedirectResponse {
        $role = WorkspaceRole::from($request->validated('role'));

        if ($role === WorkspaceRole::Admin) {
            $this->authorize('inviteAdmin', $workspace);
        }

        $result = $inviteMember->handle(
            $workspace,
            $request->user(),
            $request->validated('email'),
            $role,
        );

        // The plaintext token exists only here and in the emailed link. Mail
        // delivery arrives with the notification work in Phase 4; until then
        // the link is surfaced to the inviter so the flow is demoable.
        return back()->with([
            'status' => 'invitation-sent',
            'invitationUrl' => route('invitations.show', $result['token']),
        ]);
    }

    public function destroy(Workspace $workspace, string $invitationId): RedirectResponse
    {
        // Invitation is tenant-scoped, so this scoped lookup cannot reach
        // another workspace's invitation - it 404s instead.
        $invitation = Invitation::query()->findOrFail($invitationId);

        $invitation->forceFill(['revoked_at' => now()])->save();

        return back()->with('status', 'invitation-revoked');
    }
}
