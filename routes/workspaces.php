<?php

use App\Http\Controllers\Workspaces\InvitationAcceptanceController;
use App\Http\Controllers\Workspaces\InvitationController;
use App\Http\Controllers\Workspaces\MemberController;
use App\Http\Controllers\Workspaces\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Invitation redemption (outside the tenant boundary)
|--------------------------------------------------------------------------
|
| The invitee is not a member of anything yet, so there is no tenant to
| resolve. The single-use, hashed token is the authorization.
|
*/
Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.show');

Route::post('invitations/{token}', [InvitationAcceptanceController::class, 'accept'])
    ->name('invitations.accept');

/*
|--------------------------------------------------------------------------
| Workspace creation (authenticated, no tenant yet)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('workspaces', [WorkspaceController::class, 'store'])
        ->name('workspaces.store');
});

/*
|--------------------------------------------------------------------------
| Tenant-scoped routes
|--------------------------------------------------------------------------
|
| `workspace` middleware resolves the tenant from the {workspace} segment and
| 404s for anyone who is not an ACTIVE member. Everything below it can assume
| a bound tenant, which is what makes the global scope safe.
|
| Note the ordering: auth -> verified -> workspace. An unauthenticated request
| must redirect to login, not 404.
|
*/
Route::middleware(['auth', 'verified', 'workspace'])
    ->prefix('w/{workspace}')
    ->name('workspaces.')
    ->group(function (): void {
        Route::get('/', [WorkspaceController::class, 'show'])->name('show');
        Route::patch('/', [WorkspaceController::class, 'update'])->name('update');

        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::patch('members/{memberId}', [MemberController::class, 'update'])->name('members.update');

        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::delete('invitations/{invitationId}', [InvitationController::class, 'destroy'])
            ->name('invitations.destroy');
    });
