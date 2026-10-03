<?php

namespace Tests\Feature;

use App\Enums\EntitlementKey;
use App\Enums\Role;
use App\Jobs\ValidateImportBatch;
use App\Models\ImportFile;
use App\Models\ImportRun;
use App\Models\NormalizedImportRecord;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\RawImportRecord;
use App\Models\Subscription;
use App\Models\SupplierAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsNormalizationImport;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

/** Opt-in, real PostgreSQL + MinIO + Redis + Laravel worker. Never a developer database. */
final class ImportInfrastructureTest extends TestCase
{
    use BuildsNormalizationImport;
    use BuildsTenantContext;
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('RUN_IMPORT_INFRASTRUCTURE') !== '1') {
            $this->markTestSkipped('Set RUN_IMPORT_INFRASTRUCTURE=1 with a dedicated PostgreSQL test database.');
        }
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('cproviderprices_test', config('database.connections.pgsql.database'));
        $this->assertTrue(app()->environment('testing'));
        $this->assertNotEmpty(config('services.ingestion.token'), 'Provide an ephemeral shared ingestion token for the real HTTP path.');
    }

    public function test_real_storage_queue_python_and_persistence_with_cancellation(): void
    {
        $prefix = 'phase-b-test:'.Str::uuid().':';
        config(['database.redis.options.prefix' => $prefix, 'queue.connections.redis.queue' => 'import-validation-test']);
        Redis::purge('default');
        $account = SupplierAccount::factory()->create();
        $organization = $account->organization;
        $user = User::factory()->create();
        $this->membership($user, $organization, Role::Buyer);
        $plan = Plan::factory()->create();
        PlanEntitlement::factory()->for($plan)->create(['key' => EntitlementKey::ManualImportsEnabled, 'value' => ['enabled' => true]]);
        Subscription::factory()->for($organization)->for($plan)->create();
        $this->asUser($user);
        $url = '/api/v1/organizations/'.$organization->id.'/imports';
        $keys = [];
        try {
            foreach (['a' => 'csv', 'b' => 'xlsx'] as $supplier => $format) {
                $name = 'import-supplier-'.$supplier.'.'.$format;
                $path = base_path('tests/Fixtures/'.$name);
                $id = $this->postJson($url, ['restaurant_location_id' => $account->restaurant_location_id, 'supplier_account_id' => $account->id,
                    'file' => new UploadedFile($path, $name, test: true)])->assertCreated()->json('import.id');
                $file = ImportFile::query()->where('import_run_id', $id)->sole();
                $keys[] = $file->storage_key;
                $this->assertSame(file_get_contents($path), Storage::disk('s3')->get($file->storage_key));
                $this->assertSame('private', Storage::disk('s3')->getVisibility($file->storage_key));
                $anonymousUrl = rtrim(config('filesystems.disks.s3.endpoint'), '/').'/'.config('filesystems.disks.s3.bucket').'/'.$file->storage_key;
                $this->assertSame(403, Http::get($anonymousUrl)->status());
                $download = $this->postJson($url.'/'.$id.'/downloads')->assertOk()->json('file_url');
                $this->assertSame(file_get_contents($path), $this->get($download)->assertOk()->streamedContent());
                $mapping = $supplier === 'a' ? ['supplier_sku' => 'Item', 'description' => 'Description', 'pack_size_text' => 'Pack'] : ['supplier_sku' => 'Vendor Code', 'description' => 'Product Text', 'pack_size_text' => 'Size Label'];
                $this->putJson($url.'/'.$id.'/mapping', ['mapping' => $mapping])->assertOk();
                $this->postJson($url.'/'.$id.'/validate')->assertAccepted();
                $this->assertSame(1, Queue::connection('redis')->size('import-validation-test'));
                $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'import-validation-test', '--once' => true, '--sleep' => 0, '--tries' => 3, '--timeout' => 60]));
                $this->assertNotNull(ImportRun::query()->findOrFail($id)->validation_completed_at);
                $this->assertSame(3, RawImportRecord::query()->where('import_run_id', $id)->count());
                $this->postJson($url.'/'.$id.'/start')->assertAccepted()->assertJsonPath('import.status', 'queued');
            }
            $this->assertSame(2, Queue::connection('redis')->size('import-normalization'));
            $this->assertSame(0, NormalizedImportRecord::query()->count());
            // Phase D consumes the former handoff through authenticated real Python HTTP.
            $last = ImportRun::query()->latest('id')->firstOrFail();
            $this->postJson($url.'/'.$last->id.'/cancel')->assertOk();
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'import-normalization', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 3, '--timeout' => 60]));
            $this->assertSame('canceled', $last->refresh()->status->value);
            $this->assertSame(1, ImportRun::query()->where('status', 'partially_completed')->count());
            $this->assertSame(6, RawImportRecord::query()->count());
            $this->assertSame(3, NormalizedImportRecord::query()->count());
            foreach (NormalizedImportRecord::query()->get() as $result) {
                $this->assertSame('1.0.0', $result->parser_version);
                $this->assertSame($result->importRun->request_id, $result->normalized_values['correlation_id']);
                $this->assertSame($result->raw_import_record_id, $result->normalized_values['original']['record_id']);
            }
            $mozz = NormalizedImportRecord::query()->whereHas('rawImportRecord', fn ($query) => $query->where('source_row', 4))->sole();
            $this->assertSame('9071.8474', $mozz->normalized_values['packs'][0]['case_total']);
            $this->assertDatabaseCount('failed_jobs', 0);
            // Exercise Laravel's real exhausted-job callback and configured failed-job store.
            $failedId = $this->postJson($url, ['restaurant_location_id' => $account->restaurant_location_id, 'supplier_account_id' => $account->id,
                'file' => UploadedFile::fake()->createWithContent('outage.csv', "SKU,Description\n1,PRIVATE_SOURCE_SENTINEL\n")])->assertCreated()->json('import.id');
            $this->putJson($url.'/'.$failedId.'/mapping', ['mapping' => ['supplier_sku' => 'SKU', 'description' => 'Description']])->assertOk();
            $failedRun = ImportRun::query()->findOrFail($failedId);
            $failedRun->validation_started_at = now();
            $failedRun->save();
            $failedFile = ImportFile::query()->where('import_run_id', $failedId)->sole();
            $keys[] = $failedFile->storage_key;
            Storage::disk('s3')->delete($failedFile->storage_key);
            $job = new ValidateImportBatch($organization->id, $failedId);
            $job->tries = 1; // Force exhaustion without waiting through production retry delays.
            Queue::connection('redis')->push($job, '', 'import-validation-test');
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'import-validation-test', '--once' => true, '--sleep' => 0, '--timeout' => 60]));
            $this->assertSame('failed', $failedRun->refresh()->status->value);
            $this->assertDatabaseHas('import_errors', ['import_run_id' => $failedId, 'code' => 'validation_unavailable']);
            $this->assertDatabaseCount('failed_jobs', 1);
            $failure = DB::table('failed_jobs')->sole();
            $this->assertStringNotContainsString('PRIVATE_SOURCE_SENTINEL', $failure->payload.$failure->exception);
            $this->assertStringNotContainsString($failedFile->storage_key, $failure->payload.$failure->exception);
        } finally {
            foreach ($keys as $key) {
                Storage::disk('s3')->delete($key);
            }
            // Isolated random Redis prefix; never flush shared Redis databases.
            Queue::connection('redis')->clear('import-validation-test');
            Queue::connection('redis')->clear('import-normalization');
            Redis::purge('default');
        }
    }

    public function test_real_redis_three_attempt_exhaustion_keeps_failed_jobs_safe(): void
    {
        config(['database.redis.options.prefix' => 'phase-d-test:'.Str::uuid().':']);
        Redis::purge('default');
        $run = $this->normalizationRun();
        $token = config('services.ingestion.token');
        Http::fake(['*' => Http::response('PRIVATE_RESPONSE_SENTINEL', 503)]);
        try {
            Queue::connection('redis')->push($this->normalizationJob($run), '', 'import-normalization');
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'import-normalization', '--once' => true, '--sleep' => 0, '--timeout' => 60]));
                $this->travel(31)->seconds();
            }
            Http::assertSentCount(3);
            $this->assertSame('failed', $run->refresh()->status->value);
            $this->assertSame(2, $run->retry_count);
            $this->assertSame(1, $run->failed_count);
            $this->assertDatabaseCount('failed_jobs', 1);
            $failure = DB::table('failed_jobs')->sole();
            $this->assertStringContainsString($run->id, $failure->payload);
            foreach ([$token, 'PRIVATE_RESPONSE_SENTINEL', 'MOZZ WHOLE MILK'] as $private) {
                $this->assertStringNotContainsString($private, $failure->payload.$failure->exception);
            }
        } finally {
            $this->travelBack();
            Queue::connection('redis')->clear('import-normalization');
            Redis::purge('default');
        }
    }
}
