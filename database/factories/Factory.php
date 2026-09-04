<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory as BaseFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Shared base for every application factory. Every persisting factory path
 * (create(), createOne(), createMany(), createQuietly(), and their *Quietly
 * siblings) funnels through Factory::create() in the base class — guarding
 * that one method here centrally protects all of them without touching each
 * factory individually.
 *
 * This exists because a manual `php artisan tinker` factory call
 * (User::factory()->create(); ClientFolder::factory()->create();) once wrote
 * permanent fake rows straight into the normal development MySQL database —
 * PHPUnit itself was never at fault (it already runs on an isolated SQLite
 * :memory: connection), but nothing stopped the exact same factory code from
 * also running, unguarded, against a real database outside a test. Factory
 * make() is never touched: it only builds an in-memory model and was never
 * the risk.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends BaseFactory<TModel>
 */
abstract class Factory extends BaseFactory
{
    /**
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     * @return Collection<int, TModel>|TModel
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        $this->guardAgainstUnsafeFactoryWrite();

        return parent::create($attributes, $parent);
    }

    /**
     * Factories may persist automatically during PHPUnit (APP_ENV=testing, always on isolated
     * SQLite :memory: per phpunit.xml) or when a developer has explicitly opted in via
     * ALLOW_FACTORY_WRITES=true for deliberate, disposable-database work. Every other case —
     * local, production, or anything else — is blocked before the INSERT ever runs.
     */
    private function guardAgainstUnsafeFactoryWrite(): void
    {
        if (app()->environment('testing') || config('cims.allow_factory_writes') === true) {
            return;
        }

        throw new RuntimeException(
            'Factory database writes are disabled outside the testing environment. '.
            'Set ALLOW_FACTORY_WRITES=true only when intentionally using a disposable development database.'
        );
    }
}
