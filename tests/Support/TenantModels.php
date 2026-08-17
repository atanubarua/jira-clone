<?php

namespace Tests\Support;

use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Reflection over App\Models, so the architecture tests and the unscoped-query
 * guard stay correct as models are added in later phases without anyone having
 * to remember to update a list.
 */
final class TenantModels
{
    /**
     * Models that are deliberately NOT tenant-owned.
     *
     * User is global (one person, many workspaces). Workspace IS the tenant.
     */
    public const NOT_TENANT_OWNED = [
        User::class,
        Workspace::class,
    ];

    /**
     * Every concrete model class under App\Models.
     *
     * @return array<int, class-string<Model>>
     */
    public static function all(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            $class = self::classFromFile($file);

            if ($class === null) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * Models that must carry the BelongsToWorkspace trait.
     *
     * @return array<int, class-string<Model>>
     */
    public static function tenantOwned(): array
    {
        return array_values(array_filter(
            self::all(),
            fn (string $class): bool => ! in_array($class, self::NOT_TENANT_OWNED, true),
        ));
    }

    /**
     * Table names of every tenant-owned model, for the query guard.
     *
     * @return array<int, string>
     */
    public static function tenantTables(): array
    {
        return array_values(array_map(
            fn (string $class): string => (new $class)->getTable(),
            array_filter(
                self::tenantOwned(),
                fn (string $class): bool => in_array(BelongsToWorkspace::class, class_uses_recursive($class), true),
            ),
        ));
    }

    /** @return class-string<Model>|null */
    private static function classFromFile(SplFileInfo $file): ?string
    {
        $relative = Str::of($file->getRealPath())
            ->after(realpath(app_path()).DIRECTORY_SEPARATOR)
            ->replace(DIRECTORY_SEPARATOR, '\\')
            ->beforeLast('.php')
            ->value();

        $class = 'App\\'.$relative;

        return class_exists($class) ? $class : null;
    }
}
