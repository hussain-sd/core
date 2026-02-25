<?php

namespace SmartTill\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use SmartTill\Core\Services\CoreAccessBootstrapService;
use SmartTill\Core\Services\CoreGeoBootstrapService;

class NativeCoreInstallCommand extends Command
{
    protected $signature = 'native:core:install';

    protected $description = 'Install SMART TiLL core package for NativePHP sqlite runtime';

    public function handle(): int
    {
        if (! class_exists(\App\Models\Store::class)) {
            $this->error('Missing required model: App\\Models\\Store');

            return self::FAILURE;
        }

        if (! array_key_exists('native:migrate', app(Kernel::class)->all())) {
            $this->error('Missing required command: native:migrate (install nativephp/desktop first).');

            return self::FAILURE;
        }

        $this->info('Running NativePHP migrations...');

        $exitCode = Artisan::call('native:migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $this->line(Artisan::output());

        if ($exitCode !== self::SUCCESS) {
            $this->error('Native migration failed during core install.');

            return self::FAILURE;
        }

        if (! Schema::connection('nativephp')->hasTable('stores')) {
            $this->error('Missing required table on nativephp connection: stores');

            return self::FAILURE;
        }

        $this->info('Bootstrapping countries, currencies, and timezones on nativephp...');
        app(CoreGeoBootstrapService::class)->ensureGeoData('nativephp');

        $this->info('Bootstrapping permissions and Super Admin roles on nativephp...');
        app(CoreAccessBootstrapService::class)->ensureCoreAccess('nativephp');

        $this->info('NativePHP core package install completed successfully.');

        return self::SUCCESS;
    }
}
