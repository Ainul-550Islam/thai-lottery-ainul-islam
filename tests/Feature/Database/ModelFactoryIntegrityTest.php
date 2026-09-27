<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every model that PROMISES a factory must have one, and it must actually write a row.
 *
 * WHY THIS EXISTS
 * -----------------------------------------------------------------------------
 * `use HasFactory` is a promise with no compile-time enforcement: the class gains a
 * static `factory()` method whose resolution happens at runtime, in
 * Factory::resolveFactoryName(), by string concatenation —
 * `Database\Factories\{Model}Factory`. Nothing fails, nothing warns, and no static
 * analyser complains when that class does not exist. The break surfaces later, inside
 * whichever test first calls `Bet::factory()`, as a bare "Class not found".
 *
 * A file-completeness audit of this repository found 23 models carrying the trait with
 * no matching factory file on disk (only User, Draw and Wallet had one). Every one of
 * them was a file that had been skipped, not a deliberate omission: the trait was there,
 * the factory was not.
 *
 * WHAT IS ASSERTED, AND WHY IT IS DISCOVERED RATHER THAN LISTED
 * A hand-written list of 26 model names would pass forever after someone adds a 27th
 * model with the trait and forgets its factory — which is the exact failure this test
 * exists to catch. So the model set is DISCOVERED from app/Models at run time, the
 * trait check is done by reflection, and every discovered factory is then executed:
 *
 *   1. the factory class named by Laravel's own convention exists;
 *   2. `Model::factory()->create()` persists — real columns, real constraints, real
 *      enum casts, real NOT NULLs — so a factory that merely parses cannot pass;
 *   3. the persisted row is readable back through the model, so casts round-trip.
 *
 * WHAT IT WILL NOT DO
 * It asserts nothing about business meaning. A factory row is a schema-valid row, not
 * a domain-valid one: it is not a settled bet, not a balanced ledger pair, not a paid
 * payout. Those invariants belong to the services and to the suites that test them.
 */
final class ModelFactoryIntegrityTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();

        $this->assertContains(
            basename((string) $database),
            self::ALLOWED_DATABASES,
            'Refusing to run destructive tests against database: '.$database,
        );
    }

    public function test_every_model_using_the_has_factory_trait_has_a_factory_class(): void
    {
        $missing = [];

        foreach ($this->modelsUsingHasFactory() as $model) {
            $factory = 'Database\\Factories\\'.class_basename($model).'Factory';

            if (! class_exists($factory)) {
                $missing[] = $model.' -> '.$factory;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "A model declares `use HasFactory` but its factory file does not exist:\n".implode("\n", $missing),
        );
    }

    public function test_every_discovered_factory_persists_a_row(): void
    {
        $models = $this->modelsUsingHasFactory();

        $this->assertNotEmpty($models, 'No models were discovered — the scan itself is broken.');

        $failures = [];

        foreach ($models as $model) {
            try {
                /** @var Model $instance */
                $instance = $model::factory()->create();

                $this->assertTrue(
                    $instance->exists,
                    $model.'::factory()->create() returned a model that was never persisted.',
                );

                $fresh = $model::query()->find($instance->getKey());

                $this->assertInstanceOf(
                    $model,
                    $fresh,
                    $model.'::factory() wrote a row that cannot be read back by primary key.',
                );
            } catch (\Throwable $e) {
                $failures[] = $model.': '.Str::limit($e->getMessage(), 300);
            }
        }

        $this->assertSame(
            [],
            $failures,
            "A factory could not write a valid row:\n".implode("\n", $failures),
        );
    }

    public function test_every_factory_file_on_disk_names_an_existing_model(): void
    {
        $orphans = [];

        foreach (Finder::create()->files()->in(database_path('factories'))->name('*Factory.php') as $file) {
            /** @var SplFileInfo $file */
            $factory = 'Database\\Factories\\'.$file->getBasename('.php');

            if (! class_exists($factory)) {
                $orphans[] = $file->getRelativePathname().' does not declare '.$factory;

                continue;
            }

            $model = 'App\\Models\\'.Str::beforeLast($file->getBasename('.php'), 'Factory');

            if (! class_exists($model)) {
                $orphans[] = $file->getRelativePathname().' has no model '.$model;
            }
        }

        $this->assertSame(
            [],
            $orphans,
            "A factory file does not correspond to a model:\n".implode("\n", $orphans),
        );
    }

    /**
     * Discover every App\Models class that uses the HasFactory trait.
     *
     * @return list<class-string<Model>>
     */
    private function modelsUsingHasFactory(): array
    {
        $models = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            /** @var SplFileInfo $file */
            $class = 'App\\Models\\'.Str::replace('/', '\\', Str::beforeLast($file->getRelativePathname(), '.php'));

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            if (in_array(HasFactory::class, $this->traitsOf($reflection), true)) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    /**
     * Trait names used by a class, including traits used by its parents and by
     * other traits.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return list<string>
     */
    private function traitsOf(ReflectionClass $reflection): array
    {
        $traits = [];

        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getTraitNames() as $trait) {
                $traits[] = $trait;

                foreach (class_uses($trait) ?: [] as $nested) {
                    $traits[] = $nested;
                }
            }
        }

        return array_values(array_unique($traits));
    }
}
