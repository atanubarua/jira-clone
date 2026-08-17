<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the resolved workspace.
 *
 * SPEC Module 1, rule 2. The scope is qualified with the table name so it
 * survives joins.
 *
 * @implements Scope<Model>
 */
class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('workspace_id'),
            $context->idOrFail(),
        );
    }
}
