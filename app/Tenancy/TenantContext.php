<?php

namespace App\Tenancy;

use App\Models\Workspace;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Closure;

/**
 * Holds the workspace resolved for the current request (SPEC Module 1, rule 1).
 *
 * Registered as a singleton, so it is request-scoped in the web context and
 * explicitly managed everywhere else. There is deliberately NO ambient default:
 * queue jobs and console commands must call {@see runFor()} or {@see set()}.
 */
class TenantContext
{
    protected ?Workspace $workspace = null;

    /**
     * When true, the BelongsToWorkspace global scope is bypassed.
     *
     * This exists for the handful of legitimately cross-tenant operations -
     * invitation acceptance (the invitee is not a member yet), and system
     * maintenance. It is never set by request handling.
     */
    protected bool $bypassed = false;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function forget(): void
    {
        $this->workspace = null;
    }

    public function has(): bool
    {
        return $this->workspace instanceof Workspace;
    }

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    public function current(): ?Workspace
    {
        return $this->workspace;
    }

    /**
     * The resolved workspace id, or throw.
     *
     * Throwing is the point: a tenant-owned query with no tenant bound is a
     * bug, and a silent unscoped query is a cross-tenant data leak.
     *
     * @throws TenantNotResolvedException
     */
    public function idOrFail(): string
    {
        if (! $this->workspace instanceof Workspace) {
            throw new TenantNotResolvedException(
                'No workspace is bound to the current context. Web requests resolve '
                .'one from the /w/{workspace} route segment; queue jobs, console '
                .'commands and tests must call TenantContext::runFor() explicitly.'
            );
        }

        return $this->workspace->getKey();
    }

    /**
     * Run a callback with the given workspace bound, then restore the previous
     * context - including on failure.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Workspace $workspace, Closure $callback): mixed
    {
        $previous = $this->workspace;
        $this->workspace = $workspace;

        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
        }
    }

    /**
     * Run a callback with tenant scoping disabled.
     *
     * Use sparingly and deliberately. Every call site should be obvious about
     * why it legitimately crosses tenant boundaries.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWithout(Closure $callback): mixed
    {
        $previous = $this->bypassed;
        $this->bypassed = true;

        try {
            return $callback();
        } finally {
            $this->bypassed = $previous;
        }
    }
}
