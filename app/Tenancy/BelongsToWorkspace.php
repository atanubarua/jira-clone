<?php

namespace App\Tenancy;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned (SPEC Module 1, rule 2).
 *
 * Every model under App\Models except User and Workspace must use this trait.
 * A model that forgets it is a cross-tenant data leak, so an architecture test
 * enforces it - see tests/Feature/Tenancy/ArchitectureTest.php.
 *
 * @property string $workspace_id
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        // Auto-fill workspace_id on create so callers never have to remember,
        // and cannot accidentally write a row into another tenant.
        static::creating(function (self $model): void {
            $context = app(TenantContext::class);

            if ($context->isBypassed() && ! $context->has()) {
                return;
            }

            $model->workspace_id ??= $context->idOrFail();
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
