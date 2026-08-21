<?php

namespace Ultraviolettes\Constellation\Collectors;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Read once per snapshot, consumed twice: by the root `app` block (identity and
 * the server's environment_mismatch check) and by the `app` section (status and
 * incidents). Registered as a singleton so both readers see the same facts.
 */
final class AppFacts
{
    /** @var array<string, mixed>|null */
    private ?array $facts = null;

    public function __construct(private readonly Config $config) {}

    /**
     * The seven fields of the root `app` block.
     *
     * @return array<string, mixed>
     */
    public function identity(CarbonImmutable $now): array
    {
        $facts = $this->all();

        return [
            'name' => $facts['name'],
            'environment' => $facts['environment'],
            'url' => $facts['url'],
            'php_version' => $facts['php_version'],
            'laravel_version' => $facts['laravel_version'],
            'debug_mode' => $facts['debug_mode'],
            'server_time' => $now->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->facts ??= [
            'name' => (string) $this->config->get('app.name'),
            'environment' => (string) $this->config->get('app.env'),
            'url' => $this->config->get('constellation.app.send_url', true)
                ? (string) $this->config->get('app.url')
                : null,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'debug_mode' => (bool) $this->config->get('app.debug'),
            'pending_migrations' => $this->pendingMigrations(),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
        ];
    }

    public function isProduction(): bool
    {
        return app()->environment('production');
    }

    /**
     * Null means "could not look" — a database that is down must not read as
     * "no pending migration".
     */
    private function pendingMigrations(): ?int
    {
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return null;
            }

            $files = $migrator->getMigrationFiles(
                array_merge($migrator->paths(), [database_path('migrations')])
            );

            return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return null;
        }
    }
}
