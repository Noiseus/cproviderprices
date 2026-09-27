<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\AwaitImportNormalization;
use App\Jobs\NormalizeImportBatch;
use App\Models\ImportError;
use App\Models\NormalizedImportRecord;
use App\Models\RawImportRecord;
use App\Services\ImportLifecycleService;
use App\Services\ImportNormalizer;
use App\Support\NormalizationFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsNormalizationImport;
use Tests\TestCase;

final class ImportNormalizationTest extends TestCase
{
    use BuildsNormalizationImport;
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('cproviderprices_test', config('database.connections.pgsql.database'));
        $this->assertTrue(app()->environment('testing'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.ingestion.token' => 'private-test-token', 'services.ingestion.url' => 'http://ingestion:8001']);
    }

    private function success(bool $warning = false): void
    {
        Http::fake(fn (Request $r) => Http::response($this->normalizationResponse($r->data(), $warning), 200, ['X-Request-ID' => $r['correlation_id']]));
    }

    public function test_payload_auth_correlation_order_and_originals_are_preserved(): void
    {
        $run = $this->normalizationRun(2);
        $this->success();
        $this->app->call([$this->normalizationJob($run), 'handle']);
        $ids = RawImportRecord::query()->orderBy('source_row')->pluck('id')->all();
        Http::assertSent(fn (Request $r) => $r->url() === 'http://ingestion:8001/internal/v1/normalize'
            && $r->hasHeader('Authorization', 'Bearer private-test-token') && $r->hasHeader('X-Request-ID', $run->request_id)
            && $r['correlation_id'] === $run->request_id && array_column($r['records'], 'record_id') === $ids
            && $r['records'][0]['mapped']['supplier_sku'] === '00012' && $r['records'][0]['source_conventions']['produce_size_code'] === true);
        $result = NormalizedImportRecord::query()->firstOrFail();
        $this->assertSame('1.0.0', $result->parser_version);
        $this->assertSame('high', $result->confidence);
        $this->assertSame($run->request_id, $result->normalized_values['correlation_id']);
        $this->assertSame($result->raw_import_record_id, $result->normalized_values['original']['record_id']);
        $this->assertSame('MOZZ WHOLE MILK 4/5 LB', $result->rawImportRecord->mapped_values['description']);
        $this->assertSame(ImportStatus::Completed, $run->refresh()->status);
        $this->assertSame(2, $run->processed_count);
        $this->assertNotNull($run->finished_at);
    }

    public function test_warning_and_low_confidence_are_success_not_failure(): void
    {
        $run = $this->normalizationRun();
        $this->success(true);
        $this->app->call([$this->normalizationJob($run), 'handle']);
        $result = NormalizedImportRecord::query()->sole();
        $this->assertSame('low', $result->confidence);
        $this->assertSame('missing_quantity', $result->warnings[0]['code']);
        $this->assertSame(1, $run->refresh()->warning_count);
        $this->assertSame(0, $run->failed_count);
        $this->assertSame(ImportStatus::Completed, $run->status);
        $this->assertDatabaseCount('import_errors', 0);
    }

    public function test_handoff_is_bounded_and_begins_processing(): void
    {
        $run = $this->normalizationRun(30, ImportStatus::Queued);
        config(['imports.normalization_batch_rows' => 1000]);
        $this->app->call([new AwaitImportNormalization($run->organization_id, $run->id), 'handle']);
        Queue::assertPushed(NormalizeImportBatch::class, fn ($job) => count($job->rawIds) === 25);
        $this->assertSame(ImportStatus::Processing, $run->refresh()->status);
        $this->assertNotNull($run->started_at);
        Http::assertNothingSent();
    }

    public function test_byte_bound_splits_large_valid_rows(): void
    {
        $run = $this->normalizationRun(25, ImportStatus::Queued);
        // Synthetic trusted fixture setup; production never overwrites raw evidence.
        $mapped = array_fill_keys(ImportNormalizer::FIELDS, str_repeat('a', 4096));
        RawImportRecord::query()->where('import_run_id', $run->id)->update(['mapped_values' => json_encode($mapped)]);
        $this->app->call([new AwaitImportNormalization($run->organization_id, $run->id), 'handle']);
        Queue::assertPushed(NormalizeImportBatch::class, fn ($job) => count($job->rawIds) < 25 && count($job->rawIds) > 0);
    }

    public function test_replay_does_not_duplicate_or_overwrite_same_version(): void
    {
        $run = $this->normalizationRun(2);
        $job = $this->normalizationJob($run);
        $this->success();
        $this->app->call([$job, 'handle']);
        $before = NormalizedImportRecord::query()->orderBy('id')->get()->toArray();
        $this->app->call([$job, 'handle']);
        $this->assertSame($before, NormalizedImportRecord::query()->orderBy('id')->get()->toArray());
        Http::assertSentCount(1);
        $this->assertSame(2, $run->refresh()->processed_count);
    }

    public function test_old_version_is_retained_and_current_version_is_added(): void
    {
        $run = $this->normalizationRun();
        $raw = RawImportRecord::query()->sole();
        $old = NormalizedImportRecord::query()->create(['organization_id' => $run->organization_id, 'import_run_id' => $run->id,
            'raw_import_record_id' => $raw->id, 'parser_version' => '0.9.0', 'normalized_values' => ['historical' => true], 'warnings' => [], 'confidence' => 'low']);
        $raw->update(['status' => 'normalized']);
        $this->success();
        $this->app->call([$this->normalizationJob($run), 'handle']);
        $this->assertDatabaseCount('normalized_import_records', 2);
        $this->assertSame(['historical' => true], $old->refresh()->normalized_values);
        $this->assertSame(1, $run->refresh()->processed_count);
    }

    #[DataProvider('transientStatuses')]
    public function test_transient_failure_retries_without_creating_row_errors(int $status): void
    {
        $run = $this->normalizationRun();
        if ($status === 0) {
            Http::fake(['*' => Http::failedConnection('PRIVATE_SOURCE_SENTINEL private-test-token')]);
        } else {
            Http::fake(['*' => Http::response('PRIVATE_SOURCE_SENTINEL', $status)]);
        }
        $job = $this->normalizationJob($run)->withFakeQueueInteractions();
        try {
            $this->app->call([$job, 'handle']);
            $this->fail('A transient failure must reach the queue retry path.');
        } catch (NormalizationFailure $error) {
            $this->assertTrue($error->transient);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('PRIVATE_SOURCE', (string) $error);
        }
        $job->assertNotFailed();
        $this->assertSame(1, $run->refresh()->retry_count);
        $this->assertSame(ImportStatus::Processing, $run->status);
        $this->assertDatabaseCount('import_errors', 0);
        $this->assertDatabaseCount('normalized_import_records', 0);
        Http::assertSentCount(1);
        $this->assertSame([5, 15, 30], $job->backoff());
        $this->assertSame(3, $job->tries);
    }

    /** @return iterable<string, array{int}> */
    public static function transientStatuses(): iterable
    {
        foreach (['connection_timeout' => 0, 'rate_limit' => 429, 'bad_gateway' => 502, 'unavailable' => 503, 'gateway_timeout' => 504] as $name => $status) {
            yield $name => [$status];
        }
    }

    public function test_third_transient_attempt_exhausts_and_counts_only_scheduled_retries(): void
    {
        $run = $this->normalizationRun();
        Http::fake(['*' => Http::response('', 503)]);
        $job = $this->normalizationJob($run)->withFakeQueueInteractions();
        $fake = new FakeJob;
        $job->setJob($fake);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $fake->attempts = $attempt;
            try {
                $this->app->call([$job, 'handle']);
            } catch (NormalizationFailure) {
                $this->assertLessThan(3, $attempt);
            }
        }
        $job->assertFailed();
        $this->assertSame(2, $run->refresh()->retry_count);
        $this->assertSame(ImportStatus::Failed, $run->status);
        $this->assertDatabaseHas('import_errors', ['code' => 'service_retries_exhausted', 'source_row' => 2]);
        Http::assertSentCount(3);
        $this->app->call([$job, 'handle']);
        Http::assertSentCount(3);
    }

    #[DataProvider('permanentStatuses')]
    public function test_permanent_http_failure_is_not_retried(int $status): void
    {
        $run = $this->normalizationRun();
        Http::fake(['*' => Http::response('private-test-token PRIVATE_SOURCE_SENTINEL', $status)]);
        $job = $this->normalizationJob($run)->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);
        $job->assertFailed();
        $this->assertSame(0, $run->refresh()->retry_count);
        $this->assertSame(ImportStatus::Failed, $run->status);
        $this->assertSame(1, $run->failed_count);
        $this->assertStringNotContainsString('private-test-token', ImportError::query()->sole()->message);
    }

    /** @return iterable<string, array{int}> */
    public static function permanentStatuses(): iterable
    {
        foreach ([400, 401, 403, 413, 415, 422, 500, 302] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_success_is_rejected_atomically(string $defect): void
    {
        $run = $this->normalizationRun(2);
        Http::fake(function (Request $r) use ($defect) {
            $response = $this->normalizationResponse($r->data());
            match ($defect) {
                'version' => $response['parser_version'] = '2.0.0',
                'correlation' => $response['correlation_id'] = 'wrong',
                'missing' => array_pop($response['results']),
                'duplicate' => $response['results'][1] = $response['results'][0],
                'foreign' => $response['results'][0]['record_id'] = 'other-tenant',
                'confidence' => $response['results'][0]['confidence'] = 'excellent',
                'original' => $response['results'][0]['original']['mapped']['supplier_sku'] = 'changed',
                'measurement' => $response['results'][0]['measurements'] = [['value' => 5]],
                'warnings' => $response['results'][0]['warnings'] = [['code' => 'unknown', 'field' => 'unit', 'message' => 'bad']],
                'counter' => $response['warning'] = 10,
                'type' => $response['results'][0]['variable_weight'] = 'false',
                'extra' => $response['results'][0]['secret'] = 'not-allowed',
                'extra_batch' => $response['secret'] = 'not-allowed',
                default => throw new \LogicException('Unknown response defect.'),
            };

            return Http::response($response, 200, ['X-Request-ID' => $r['correlation_id']]);
        });
        $job = $this->normalizationJob($run)->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);
        $job->assertFailed();
        $this->assertDatabaseCount('normalized_import_records', 0);
        $this->assertSame(2, $run->refresh()->failed_count);
        $this->assertSame(0, $run->retry_count);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedResponses(): iterable
    {
        foreach (['version', 'correlation', 'missing', 'duplicate', 'foreign', 'confidence', 'original', 'measurement', 'warnings', 'counter', 'type', 'extra', 'extra_batch'] as $defect) {
            yield $defect => [$defect];
        }
    }

    public function test_empty_configuration_fails_closed_without_http(): void
    {
        config(['services.ingestion.token' => '']);
        $run = $this->normalizationRun();
        $this->app->call([$this->normalizationJob($run), 'handle']);
        Http::assertNothingSent();
        $this->assertDatabaseHas('import_errors', ['code' => 'invalid_service_configuration']);
    }

    public function test_canceled_import_blocks_handoff_delivery_retry_and_failed_callback(): void
    {
        $run = $this->normalizationRun();
        $job = $this->normalizationJob($run);
        Http::fake(['*' => Http::response('', 503)]);
        try {
            $this->app->call([$job, 'handle']);
        } catch (NormalizationFailure) {
        }
        app(ImportLifecycleService::class)->transition($run->organization, $run->id, ImportStatus::Canceled);
        $this->app->call([$job, 'handle']);
        $this->app->call([new AwaitImportNormalization($run->organization_id, $run->id), 'handle']);
        $job->failed(null);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
        $this->assertSame(ImportStatus::Canceled, $run->refresh()->status);
        $this->assertDatabaseCount('raw_import_records', 1);
        $this->assertDatabaseCount('import_errors', 0);
    }

    public function test_interrupted_import_resumes_only_remaining_rows_and_cancel_retains_history(): void
    {
        $run = $this->normalizationRun(3);
        $all = $this->normalizationJob($run);
        $first = new NormalizeImportBatch($run->organization_id, $run->id, [$all->rawIds[0]]);
        $this->success();
        $this->app->call([$first, 'handle']);
        $this->assertSame(1, $run->refresh()->processed_count);
        $this->assertSame(ImportStatus::Processing, $run->status);
        $this->app->call([new AwaitImportNormalization($run->organization_id, $run->id), 'handle']);
        Queue::assertPushed(NormalizeImportBatch::class, fn ($job) => $job->rawIds === array_slice($all->rawIds, 1));
        $this->app->call([$all, 'handle']);
        Http::assertSent(fn (Request $r) => count($r['records']) === 2 && $r['records'][0]['record_id'] === $all->rawIds[1]);
        $this->assertSame(3, $run->refresh()->processed_count);
        $this->assertDatabaseCount('normalized_import_records', 3);
    }

    public function test_mixed_permanent_failure_preserves_success_and_finalizes_partial(): void
    {
        $run = $this->normalizationRun(2);
        $ids = $this->normalizationJob($run)->rawIds;
        Http::fake(fn (Request $r) => $r['records'][0]['record_id'] === $ids[0]
            ? Http::response($this->normalizationResponse($r->data(), true), 200, ['X-Request-ID' => $r['correlation_id']])
            : Http::response('', 422));
        $this->app->call([new NormalizeImportBatch($run->organization_id, $run->id, [$ids[0]]), 'handle']);
        $this->app->call([new NormalizeImportBatch($run->organization_id, $run->id, [$ids[1]]), 'handle']);
        $this->assertSame(ImportStatus::PartiallyCompleted, $run->refresh()->status);
        $this->assertSame(1, $run->processed_count);
        $this->assertSame(1, $run->warning_count);
        $this->assertSame(1, $run->failed_count);
        $this->assertDatabaseCount('normalized_import_records', 1);
    }

    public function test_validation_errors_count_once_in_terminal_summary(): void
    {
        $run = $this->normalizationRun();
        foreach (['missing_sku', 'missing_description'] as $code) {
            ImportError::query()->create(['organization_id' => $run->organization_id, 'import_run_id' => $run->id, 'source_row' => 3, 'code' => $code, 'error_key' => $code, 'message' => 'Required field.']);
        }
        $this->success();
        $this->app->call([$this->normalizationJob($run), 'handle']);
        $this->assertSame(ImportStatus::PartiallyCompleted, $run->refresh()->status);
        $this->assertSame(1, $run->failed_count);
    }

    public function test_foreign_raw_ids_never_enter_request(): void
    {
        $run = $this->normalizationRun();
        $other = $this->normalizationRun();
        $own = $this->normalizationJob($run);
        $foreign = $this->normalizationJob($other)->rawIds[0];
        $own->rawIds[] = $foreign;
        $this->success();
        $this->app->call([$own, 'handle']);
        Http::assertSent(fn (Request $r) => count($r['records']) === 1 && $r['records'][0]['record_id'] !== $foreign);
        $this->assertSame('pending', RawImportRecord::query()->findOrFail($foreign)->status);
        $this->assertSame(0, $other->refresh()->processed_count);
    }

    public function test_logs_and_serialized_jobs_exclude_rows_credentials_and_responses(): void
    {
        $log = Log::spy();
        $run = $this->normalizationRun();
        $job = $this->normalizationJob($run);
        $this->success(true);
        $this->app->call([$job, 'handle']);
        $log->shouldHaveReceived('info')->withArgs(function (string $event, array $context) use ($run): bool {
            $this->assertSame('import.normalization.progress', $event);
            $this->assertSame($run->request_id, $context['request_id']);
            $serialized = json_encode($context);
            $this->assertStringNotContainsString('private-test-token', $serialized);
            $this->assertStringNotContainsString('MOZZ', $serialized);

            return $context['processed'] === 1 && $context['warning'] === 1;
        })->once();
        $this->assertStringNotContainsString('private-test-token', serialize($job));
        $this->assertStringNotContainsString('MOZZ', serialize($job));
    }

    public function test_cancellation_after_a_batch_keeps_normalized_history_and_stops_remaining_rows(): void
    {
        $run = $this->normalizationRun(2);
        $job = $this->normalizationJob($run);
        $this->success();
        $this->app->call([new NormalizeImportBatch($run->organization_id, $run->id, [$job->rawIds[0]]), 'handle']);
        app(ImportLifecycleService::class)->transition($run->organization, $run->id, ImportStatus::Canceled);
        $this->app->call([$job, 'handle']);
        $job->failed(null);
        $this->assertDatabaseCount('normalized_import_records', 1);
        $this->assertDatabaseCount('raw_import_records', 2);
        $this->assertSame(ImportStatus::Canceled, $run->refresh()->status);
        $this->assertSame(1, $run->processed_count);
        Http::assertSentCount(1);
    }

    public function test_invalid_local_shape_is_permanent_without_a_service_call(): void
    {
        $run = $this->normalizationRun();
        $run->update(['mapping_snapshot' => ['source_conventions' => ['volume_system' => 'imperial']]]);
        $job = $this->normalizationJob($run)->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);
        $job->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseHas('import_errors', ['code' => 'invalid_local_payload', 'source_row' => 2]);
        $this->assertSame(0, $run->refresh()->retry_count);
    }

    public function test_retry_recovers_and_keeps_failure_and_warning_counters_distinct(): void
    {
        $run = $this->normalizationRun();
        $calls = 0;
        Http::fake(function (Request $r) use (&$calls) {
            $calls++;

            return $calls === 1 ? Http::response('', 503) : Http::response($this->normalizationResponse($r->data(), true), 200, ['X-Request-ID' => $r['correlation_id']]);
        });
        $job = $this->normalizationJob($run);
        try {
            $this->app->call([$job, 'handle']);
        } catch (NormalizationFailure) {
        }
        $this->app->call([$job, 'handle']);
        $this->assertSame(ImportStatus::Completed, $run->refresh()->status);
        $this->assertSame(1, $run->retry_count);
        $this->assertSame(1, $run->warning_count);
        $this->assertSame(0, $run->failed_count);
        $this->assertDatabaseCount('import_errors', 0);
    }

    public function test_forged_run_tenant_pointer_cannot_read_or_mutate_another_run(): void
    {
        $own = $this->normalizationRun();
        $foreign = $this->normalizationRun();
        $job = $this->normalizationJob($foreign);
        $job->organizationId = $own->organization_id;
        try {
            $this->app->call([$job, 'handle']);
        } catch (NormalizationFailure) {
        }
        $job->failed(null);
        Http::assertNothingSent();
        $this->assertDatabaseCount('import_errors', 0);
        $this->assertSame(ImportStatus::Processing, $foreign->refresh()->status);
        $this->assertSame(0, $foreign->processed_count);
    }
}
