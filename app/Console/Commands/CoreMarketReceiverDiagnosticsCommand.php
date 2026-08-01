<?php

namespace App\Console\Commands;

use App\Services\CoreMarketRuntimeDatabaseResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CoreMarketReceiverDiagnosticsCommand extends Command
{
    protected $signature = 'coremarket:receiver-diagnostics';

    protected $description = 'Run read-only diagnostics for the CorePilotOS runtime snapshot receiver';

    public function handle(CoreMarketRuntimeDatabaseResolver $resolver): int
    {
        $diagnostics = $resolver->resolve();
        $contents = $this->environmentContents();
        $canonical = (string) (config('coremarket.runtime_sync.token') ?: $this->envValue($contents, 'COREPILOT_RUNTIME_SYNC_TOKEN'));
        $legacy = (string) $this->envValue($contents, 'COREPILOT_SYNC_TOKEN');

        $this->info('CorePilotOS runtime receiver diagnostics');
        $this->table(['Field', 'Value'], [
            ['APP_ENV', app()->environment()],
            ['APP_URL', config('app.url') ?: '[missing]'],
            ['Default DB connection', $diagnostics['default_connection_name'] ?? '[unknown]'],
            ['Default DB database', $diagnostics['default_database_name'] ?? '[unknown]'],
            ['Runtime snapshot connection', $diagnostics['runtime_connection_name'] ?? '[unknown]'],
            ['Runtime snapshot database', $diagnostics['runtime_database_name'] ?? '[unknown]'],
            ['Runtime business_settings', ($diagnostics['has_business_settings_table'] ?? false) ? 'present' : 'missing'],
            ['COREPILOT_RUNTIME_SYNC_TOKEN', $canonical !== '' ? 'present' : 'missing'],
            ['Canonical token preview', $canonical !== '' ? $this->maskToken($canonical) : '[missing]'],
            ['Legacy COREPILOT_SYNC_TOKEN', $legacy !== '' ? 'present' : 'missing'],
            ['Tokens match', $canonical !== '' && $legacy !== '' ? (hash_equals($canonical, $legacy) ? 'yes' : 'no') : '[not comparable]'],
            ['Expected header', 'X-CorePilot-Sync-Token'],
            ['Preview endpoint', '/api/corepilot/runtime-snapshot/preview'],
            ['Apply endpoint', '/api/corepilot/runtime-snapshot/apply'],
        ]);

        if (! ($diagnostics['has_business_settings_table'] ?? false)) {
            $this->warn('Runtime snapshot storage is unavailable. Set COREMARKET_RUNTIME_DB_CONNECTION=mysql for single-database installs.');
        }
        if ($canonical !== '' && $legacy !== '' && ! hash_equals($canonical, $legacy)) {
            $this->warn('Canonical and legacy sync tokens differ. The receiver uses COREPILOT_RUNTIME_SYNC_TOKEN.');
        }
        $this->line('Read-only: no database or environment values were changed.');

        return self::SUCCESS;
    }

    private function environmentContents(): string
    {
        $path = (string) config('coremarket.client_setup.env_path', app()->environmentFilePath());

        return File::isFile($path) ? File::get($path) : '';
    }

    private function envValue(string $contents, string $key): ?string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches)) {
            return null;
        }

        $value = trim($matches[1], " \t\n\r\0\x0B\"'");

        return $value === '' ? null : $value;
    }

    private function maskToken(string $token): string
    {
        return strlen($token) <= 12 ? '[hidden]' : substr($token, 0, 6).'...'.substr($token, -6);
    }
}
