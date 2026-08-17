<?php

namespace App\Actions\Fortify;

use App\Actions\Workspaces\CreateWorkspace;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly CreateWorkspace $createWorkspace) {}

    /**
     * Validate and create a newly registered user, together with the workspace
     * they own.
     *
     * There is no user without a workspace in v1 (SPEC Module 1, rule 11), so
     * both are created in one transaction: a failure leaves neither.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->createWorkspace->handle(
                $user,
                $this->defaultWorkspaceName($input['name']),
            );

            return $user->refresh();
        });
    }

    private function defaultWorkspaceName(string $name): string
    {
        $first = trim(explode(' ', trim($name))[0]);

        return $first === '' ? 'My Workspace' : "{$first}'s Workspace";
    }
}
