<?php

namespace Tests\Support;

use App\Models\Concerns\HasLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Checks that Eloquent models agree with the lifecycle registry (design 08):
 * a model uses SoftDeletes exactly when its table's soft_delete_policy is
 * "allowed". Without it, deleted rows would show up in normal queries; with it
 * on a protected table, Laravel would offer a delete the database refuses.
 */
final class LifecycleModels
{
    /**
     * Every concrete Eloquent model under app/Models.
     *
     * @return list<class-string<Model>>
     */
    public static function inApp(): array
    {
        $models = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Models')));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = Str::after($file->getPathname(), app_path().DIRECTORY_SEPARATOR);
            $class = 'App\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

            if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    /**
     * One message per model that disagrees with its table's policy. Models
     * without HasLifecycle are skipped (every domain model will have it).
     *
     * @param  list<class-string<Model>>  $models
     * @return list<string>
     */
    public static function softDeleteMismatches(array $models): array
    {
        $problems = [];

        foreach ($models as $model) {
            $traits = class_uses_recursive($model);

            if (! isset($traits[HasLifecycle::class])) {
                continue;
            }

            $table = (new $model)->getTable();
            $policy = DB::table('lifecycle_entities')->where('entity_name', $table)->value('soft_delete_policy');
            $softDeletes = isset($traits[SoftDeletes::class]);

            if ($policy === null) {
                $problems[] = "{$model}: table {$table} is not in the lifecycle standard";
            } elseif ($softDeletes && $policy !== 'allowed') {
                $problems[] = "{$model}: uses SoftDeletes, but {$table} is {$policy}";
            } elseif (! $softDeletes && $policy === 'allowed') {
                $problems[] = "{$model}: needs SoftDeletes, because {$table} allows soft delete";
            }
        }

        return $problems;
    }
}
