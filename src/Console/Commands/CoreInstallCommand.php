<?php

namespace SmartTill\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use SmartTill\Core\Services\CoreAccessBootstrapService;
use SmartTill\Core\Services\CoreGeoBootstrapService;
use SmartTill\Core\Services\CoreUnitBootstrapService;

class CoreInstallCommand extends Command
{
    protected $signature = 'core:install';

    protected $description = 'Install SMART TiLL core package';

    public function handle(): int
    {
        if (! class_exists(\App\Models\Store::class)) {
            $this->error('Missing required model: App\\Models\\Store');

            return self::FAILURE;
        }

        if (! Schema::hasTable('stores')) {
            $this->error('Missing required table: stores');

            return self::FAILURE;
        }

        $this->info('Running migrations...');

        $exitCode = Artisan::call('migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $this->line(Artisan::output());

        if ($exitCode !== self::SUCCESS) {
            $this->error('Migration failed during core install.');

            return self::FAILURE;
        }

        $this->info('Bootstrapping countries, currencies, and timezones...');
        app(CoreGeoBootstrapService::class)->ensureGeoData();

        $this->info('Bootstrapping universal units...');
        app(CoreUnitBootstrapService::class)->ensureUnitData();

        $this->info('Bootstrapping permissions and Super Admin roles...');
        app(CoreAccessBootstrapService::class)->ensureCoreAccess();

        $this->info('Core package installed successfully.');

        return self::SUCCESS;
    }
}
