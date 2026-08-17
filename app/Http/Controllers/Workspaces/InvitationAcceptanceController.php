<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspaces\AcceptInvitation;
use App\Exceptions\InvalidInvitationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\AcceptInvitationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Redeeming an invitation link.
 *
 * Deliberately outside the /w/{workspace} tenant boundary: the invitee is not
 * a member of anything yet, so there is no tenant to resolve.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(private readonly AcceptInvitation $acceptInvitation) {}

    public function show(string $token): Response|RedirectResponse
    {
        try {
            $invitation = $this->acceptInvitation->resolve($token);
        } catch (InvalidInvitationException $e) {
            return $this->failure($e);
        }

        return Inertia::render('invitations/show', [
            'token' => $token,
            'workspaceName' => $invitation->workspace->name,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            // Drives whether the page asks for a name and password or just
            // shows an "accept" button.
            'hasAccount' => User::query()->where('email', $invitation->email)->exists(),
            'isAuthenticated' => Auth::check(),
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        try {
            if (Auth::check()) {
                $membership = $this->acceptInvitation->forExistingUser($token, $request->user());
            } else {
                $membership = $this->acceptInvitation->forNewUser($token, [
                    'name' => $request->validated('name'),
                    'password' => $request->validated('password'),
                ]);

                Auth::login($membership->user);
                $request->session()->regenerate();
            }
        } catch (InvalidInvitationException $e) {
            return $this->failure($e);
        }

        return to_route('workspaces.show', $membership->workspace->slug);
    }

    private function failure(InvalidInvitationException $e): never
    {
        throw ValidationException::withMessages(['token' => $e->getMessage()])
            ->redirectTo(route('home'));
    }
}
