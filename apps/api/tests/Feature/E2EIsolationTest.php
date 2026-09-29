<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class E2EIsolationTest extends TestCase
{
    public static function unsafeTargets(): array
    {
        return [
            ['app:prepare-auth-e2e', 'cproviderprices_test', true],
            ['app:prepare-auth-e2e', 'cproviderprices_e2e', false],
            ['app:verify-import-e2e', 'cproviderprices_test', true],
            ['app:verify-import-e2e', 'cproviderprices_e2e', false],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function test_rejects_unsafe_targets_before_database_access(string $command, string $database, bool $isolated): void
    {
        // No RefreshDatabase: the command must refuse before any query or write.
        config(['database.connections.pgsql.database' => $database, 'e2e.isolated' => $isolated]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('isolated');
        Artisan::call($command);
    }
}
