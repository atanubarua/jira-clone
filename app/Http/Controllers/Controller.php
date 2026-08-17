<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Authorization goes through Policies - via #[Authorize] attributes where
     * the check is per-route, or an explicit authorize() call where it is
     * conditional. Never a hand-rolled permission `if` (CLAUDE.md rule 6).
     */
    use AuthorizesRequests;
}
