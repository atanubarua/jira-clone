<?php

namespace App\Http\Requests\Workspaces;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class AcceptInvitationRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * The token itself is the authorization. It is single-use, hashed at rest,
     * and validated by AcceptInvitation::resolve().
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An already-authenticated user just accepts; a new user supplies the
     * credentials their account will be created with. The email is never taken
     * from input - it comes from the invitation.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (Auth::check()) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }
}
