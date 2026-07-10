<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CoreMarketRuntimeDatabaseResolver
{
    public function resolve(): array
    {
        $connectionName = $this->runtimeConnectionName();
        $defaultConnection = (string) config('database.default', 'mysql');

        $databaseName = null;
        $hasBusinessSettingsTable = false;
        $businessSettingsCount = null;
        $connectionConfigured = is_array(config("database.connections.{$connectionName}"));

        try {
            $databaseName = DB::connection($connectionName)->getDatabaseName();
        } catch (\Throwable $exception) {
            $databaseName = null;
        }

        try {
            $hasBusinessSettingsTable = Schema::connection($connectionName)->hasTable('business_settings');
        } catch (\Throwable $exception) {
            $hasBusinessSettingsTable = false;
        }

        if ($hasBusinessSettingsTable) {
            try {
                $businessSettingsCount = DB::connection($connectionName)->table('business_settings')->count();
            } catch (\Throwable $exception) {
                $businessSettingsCount = null;
            }
        }

        return [
            'app_environment' => app()->environment(),
            'config_cached' => app()->configurationIsCached(),
            'default_connection_name' => $defaultConnection,
            'default_database_name' => $this->databaseNameForConnection($defaultConnection),
            'runtime_connection_name' => $connectionName,
            'runtime_database_name' => $databaseName,
            'connection_configured' => $connectionConfigured,
            'has_business_settings_table' => $hasBusinessSettingsTable,
            'business_settings_count' => $businessSettingsCount,
            'forbidden_database_detected' => $this->isForbiddenDatabase($databaseName),
        ];
    }

    public function requireWritableRuntimeConnection(): array
    {
        $diagnostics = $this->resolve();

        if ($diagnostics['forbidden_database_detected']) {
            throw new RuntimeException(sprintf(
                'Unsafe CoreMarket runtime DB context: %s. Connection [%s], has_table=%s.',
                $diagnostics['runtime_database_name'] ?: '[unknown]',
                $diagnostics['runtime_connection_name'],
                $diagnostics['has_business_settings_table'] ? 'yes' : 'no'
            ));
        }

        if (! $diagnostics['runtime_database_name'] || ! $diagnostics['has_business_settings_table']) {
            throw new RuntimeException(sprintf(
                'CoreMarket runtime snapshot storage is unavailable. Connection [%s], database [%s], has_table=%s.',
                $diagnostics['runtime_connection_name'],
                $diagnostics['runtime_database_name'] ?: '[unknown]',
                $diagnostics['has_business_settings_table'] ? 'yes' : 'no'
            ));
        }

        return $diagnostics;
    }

    public function runtimeConnectionName(): string
    {
        return (string) config('coremarket.runtime_snapshot.connection', 'coremarket_runtime');
    }

    public function isForbiddenDatabase(?string $databaseName): bool
    {
        if (! is_string($databaseName) || trim($databaseName) === '') {
            return false;
        }

        return in_array(
            strtolower(trim($databaseName)),
            array_map('strtolower', config('coremarket.runtime_snapshot.forbidden_databases', [])),
            true
        );
    }

    protected function databaseNameForConnection(string $connectionName): ?string
    {
        try {
            return DB::connection($connectionName)->getDatabaseName();
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
