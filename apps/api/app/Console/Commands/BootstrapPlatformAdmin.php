<?php

namespace App\Console\Commands;

use App\Services\AccessBootstrapService;
use Illuminate\Console\Command;
use Throwable;

final class BootstrapPlatformAdmin extends Command
{
    protected $signature = 'app:bootstrap-platform-admin
        {email : Email address for the first real platform administrator}
        {--password-env=PLATFORM_ADMIN_BOOTSTRAP_PASSWORD : Runtime environment variable containing the password}
        {--confirm : Required outside local development}';

    protected $description = 'Create or reconcile the first platform administrator without creating tenant memberships';

    public function handle(AccessBootstrapService $bootstrap): int
    {
        if (! in_array((string) config('app.env'), ['local', 'development'], true) && ! $this->option('confirm')) {
            $this->error('Explicit --confirm is required outside local development.');

            return self::FAILURE;
        }

        $environmentName = (string) $this->option('password-env');
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $environmentName) !== 1) {
            $this->error('The password environment variable name must use uppercase letters, numbers, and underscores.');

            return self::FAILURE;
        }

        $password = $bootstrap->passwordFromEnvironment($environmentName);
        if ($password === null && $this->input->isInteractive()) {
            $password = $this->secret('Platform administrator password');
        }

        if (! is_string($password) || trim($password) === '') {
            $this->error('Provide the platform administrator password through a runtime secret or '.$environmentName.'.');

            return self::FAILURE;
        }

        try {
            $bootstrap->assertSafePassword($password, $environmentName);
            $bootstrap->bootstrapPlatformAdmin((string) $this->argument('email'), $password);
        } catch (Throwable $exception) {
            $this->error('Platform administrator bootstrap did not complete safely: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Platform administrator bootstrap reconciled without creating organization memberships.');

        return self::SUCCESS;
    }
}
