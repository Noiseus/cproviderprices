<?php

namespace App\Console\Commands;

use App\Services\AccessBootstrapService;
use Illuminate\Console\Command;
use Throwable;

final class BootstrapLocalDemo extends Command
{
    protected $signature = 'app:bootstrap-local-demo';

    protected $description = 'Create or reconcile the persistent local CProviderPrices demo accounts';

    public function handle(AccessBootstrapService $bootstrap): int
    {
        if (! in_array((string) config('app.env'), ['local', 'development'], true)) {
            $this->error('Local demo bootstrap is restricted to local or development environments.');

            return self::FAILURE;
        }

        try {
            $passwords = $bootstrap->localDemoPasswordsFromEnvironment();
            $result = $bootstrap->bootstrapLocalDemo($passwords);
        } catch (Throwable $exception) {
            $this->error('Local demo bootstrap did not complete safely: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Local demo access reconciled: %d accounts, %s, and %s.',
            count($result['accounts']),
            $result['organization']->name,
            $result['location']->name,
        ));

        return self::SUCCESS;
    }
}
