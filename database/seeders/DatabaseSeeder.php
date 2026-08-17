<?php

namespace Database\Seeders;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\InviteMember;
use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds two workspaces, deliberately.
     *
     * One tenant proves nothing about isolation - with two, you can log in as
     * either owner and confirm that neither can see the other's workspace.
     */
    public function run(): void
    {
        $acme = $this->workspace(
            name: 'Acme Engineering',
            ownerName: 'Test User',
            ownerEmail: 'test@example.com',
            colleagues: [
                ['Priya Admin', 'priya@example.com', WorkspaceRole::Admin, MembershipStatus::Active],
                ['Sam Member', 'sam@example.com', WorkspaceRole::Member, MembershipStatus::Active],
                ['Casey Contractor', 'casey@example.com', WorkspaceRole::Guest, MembershipStatus::Active],
                ['Alex Former', 'alex@example.com', WorkspaceRole::Member, MembershipStatus::Deactivated],
            ],
        );

        // A pending invitation so the members screen has something to show.
        app(InviteMember::class)->handle(
            $acme,
            $acme->owner,
            'pending@example.com',
            WorkspaceRole::Member,
        );

        $this->workspace(
            name: 'Globex Rival',
            ownerName: 'Rival Owner',
            ownerEmail: 'rival@example.com',
            colleagues: [
                ['Rival Colleague', 'rival-colleague@example.com', WorkspaceRole::Member, MembershipStatus::Active],
            ],
        );

        $this->command->info('Seeded. Log in as test@example.com / password');
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: WorkspaceRole, 3: MembershipStatus}>  $colleagues
     */
    private function workspace(
        string $name,
        string $ownerName,
        string $ownerEmail,
        array $colleagues,
    ): Workspace {
        $owner = User::factory()->create([
            'name' => $ownerName,
            'email' => $ownerEmail,
        ]);

        $workspace = app(CreateWorkspace::class)->handle($owner, $name);

        app(TenantContext::class)->runFor($workspace, function () use ($workspace, $colleagues): void {
            foreach ($colleagues as [$memberName, $email, $role, $status]) {
                $user = User::factory()->create(['name' => $memberName, 'email' => $email]);

                WorkspaceMember::create([
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $user->getKey(),
                    'role' => $role,
                    'status' => $status,
                    'joined_at' => now(),
                ]);
            }
        });

        return $workspace;
    }
}
