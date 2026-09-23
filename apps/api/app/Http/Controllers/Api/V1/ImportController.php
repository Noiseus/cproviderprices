<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EntitlementKey;
use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignRequestId;
use App\Jobs\AwaitImportNormalization;
use App\Jobs\ValidateImportBatch;
use App\Models\ImportError;
use App\Models\ImportFile;
use App\Models\ImportMappingTemplate;
use App\Models\ImportRun;
use App\Models\Organization;
use App\Models\RawImportRecord;
use App\Models\SupplierAccount;
use App\Services\EntitlementService;
use App\Services\ImportAccess;
use App\Services\ImportFileReader;
use App\Services\ImportLifecycleService;
use App\Services\ImportMapping;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ImportController extends Controller
{
    public function __construct(private ImportAccess $access, private ImportLifecycleService $lifecycle) {}

    public function index(Request $request, TenantContext $context, Organization $organization): JsonResponse
    {
        $this->authorizeImport($request, $context);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = ImportRun::query()->where('organization_id', $context->organization->id)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json(['imports' => $page->getCollection()->map(fn (ImportRun $run): array => $this->payload($run))->all(),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, TenantContext $context, Organization $organization, string $import): JsonResponse
    {
        $this->authorizeImport($request, $context);

        return $this->response($this->access->run($context->organization, $import));
    }

    public function store(Request $request, TenantContext $context, Organization $organization, ImportFileReader $reader): JsonResponse
    {
        $this->authorizeImport($request, $context, true, true);
        $data = $request->validate(['restaurant_location_id' => ['required', 'uuid'], 'supplier_account_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'min:1', 'max:'.config('imports.max_kib')]]);
        $account = $this->access->account($context->organization, $data['supplier_account_id'], $data['restaurant_location_id']);
        $upload = $request->file('file');
        assert($upload instanceof UploadedFile);
        $name = $upload->getClientOriginalName();
        $originalPath = $upload->getClientOriginalPath();
        $format = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $upload->getMimeType();
        if (strlen($name) > 200 || preg_match('~[\\\\/\x00-\x1f\x7f]|\.\.~', $originalPath) || ! in_array($format, ['csv', 'xlsx'], true)
            || ! in_array($mime, $format === 'csv' ? ['text/plain', 'text/csv', 'application/csv'] : ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) {
            throw new ApiProblem('invalid_import_file', 'A safe CSV or XLSX file is required.', 422);
        }
        $fingerprint = hash_file('sha256', $upload->getPathname());
        $preview = $reader->inspect($upload->getPathname(), $format);
        $key = 'imports/'.$context->organization->id.'/'.Str::uuid().'.'.$format;
        $stored = false;
        try {
            $run = DB::transaction(function () use ($context, $account, $upload, $name, $format, $mime, $fingerprint, $preview, $key, &$stored): ImportRun {
                SupplierAccount::query()->whereKey($account->id)->where('organization_id', $context->organization->id)->lockForUpdate()->firstOrFail();
                $existing = ImportRun::query()->where('organization_id', $context->organization->id)->where('supplier_account_id', $account->id)->where('fingerprint', $fingerprint)->first();
                if ($existing !== null) {
                    throw new ApiProblem('duplicate_import', 'This file has already been imported for this supplier account.', 409, ['import_id' => $existing->id]);
                }
                $stream = fopen($upload->getPathname(), 'rb');
                if ($stream === false) {
                    throw new ApiProblem('import_storage_unavailable', 'Import storage is temporarily unavailable.', 503);
                }
                try {
                    $stored = Storage::disk('s3')->put($key, $stream, ['visibility' => 'private']);
                } finally {
                    fclose($stream);
                }
                if (! $stored) {
                    throw new ApiProblem('import_storage_unavailable', 'Import storage is temporarily unavailable.', 503);
                }
                $run = ImportRun::query()->create(['organization_id' => $context->organization->id, 'restaurant_location_id' => $account->restaurant_location_id,
                    'supplier_account_id' => $account->id, 'created_by' => $context->user->id, 'fingerprint' => $fingerprint,
                    'status' => ImportStatus::Uploaded, 'request_id' => AssignRequestId::for(request())]);
                ImportFile::query()->create(['organization_id' => $run->organization_id, 'import_run_id' => $run->id, 'original_filename' => $name,
                    'storage_key' => $key, 'size_bytes' => $upload->getSize(), 'media_type' => $mime, 'format' => $format, ...$preview]);
                $this->lifecycle->transition($context->organization, $run->id, ImportStatus::Validating);
                $run = $this->lifecycle->transition($context->organization, $run->id, ImportStatus::NeedsMapping);
                Audit::record('import.created', $context->user, $run->organization_id, 'import_run', $run->id, ['format' => $format, 'size_bytes' => $upload->getSize()]);

                return $run;
            });
        } catch (Throwable $error) {
            if ($stored) {
                Storage::disk('s3')->delete($key);
            }
            throw $error;
        }

        return $this->response($run, 201);
    }

    public function templates(Request $request, TenantContext $context, Organization $organization): JsonResponse
    {
        $this->authorizeImport($request, $context);
        $data = $request->validate(['supplier_account_id' => ['required', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $account = $this->access->account($context->organization, $data['supplier_account_id']);
        $page = ImportMappingTemplate::query()->where('organization_id', $context->organization->id)->where('supplier_account_id', $account->id)->orderBy('name')->paginate(20);

        return response()->json(['templates' => $page->getCollection()->map(fn (ImportMappingTemplate $template): array => [
            'id' => $template->id, 'name' => $template->name, 'headers' => $template->headers, 'mapping' => $template->mapping, 'source_conventions' => (object) $template->source_conventions,
        ])->all(), 'pagination' => ['current_page' => $page->currentPage(), 'per_page' => 20, 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function mapping(Request $request, TenantContext $context, Organization $organization, string $import, ImportMapping $mapping): JsonResponse
    {
        $this->authorizeImport($request, $context, true, true);
        $run = DB::transaction(function () use ($request, $context, $import, $mapping): ImportRun {
            $run = $this->access->run($context->organization, $import, true);
            $this->requireMappingState($run);
            if ($run->validation_started_at !== null) {
                throw new ApiProblem('mapping_locked', 'Mapping is immutable after validation starts.', 409);
            }
            SupplierAccount::query()->whereKey($run->supplier_account_id)->where('organization_id', $run->organization_id)->lockForUpdate()->firstOrFail();
            $mapping->confirm($request, $run, $this->access->file($run));
            Audit::record('import.mapping_confirmed', $context->user, $run->organization_id, 'import_run', $run->id);

            return $run;
        });

        return $this->response($run);
    }

    public function validateImport(Request $request, TenantContext $context, Organization $organization, string $import): JsonResponse
    {
        $this->authorizeImport($request, $context, true, true);
        $run = DB::transaction(function () use ($context, $import): ImportRun {
            $run = $this->access->run($context->organization, $import, true);
            $this->requireMappingState($run);
            if ($run->mapping_snapshot === null) {
                throw new ApiProblem('mapping_required', 'Confirm a mapping before validation.', 409);
            }
            if ($run->validation_completed_at === null) {
                if ($run->validation_started_at === null) {
                    $run->validation_started_at = now();
                    $run->save();
                    Audit::record('import.validation_started', $context->user, $run->organization_id, 'import_run', $run->id);
                }
                ValidateImportBatch::dispatch($run->organization_id, $run->id)->onConnection('redis')->afterCommit();
            }

            return $run;
        });

        return $this->response($run, 202);
    }

    public function start(Request $request, TenantContext $context, Organization $organization, string $import): JsonResponse
    {
        $this->authorizeImport($request, $context, true, true);
        $run = DB::transaction(function () use ($context, $import): ImportRun {
            $run = $this->access->run($context->organization, $import, true);
            if ($run->status !== ImportStatus::Queued) {
                $this->requireMappingState($run);
                if ($run->validation_completed_at === null || ! RawImportRecord::query()->where('organization_id', $run->organization_id)->where('import_run_id', $run->id)->exists()) {
                    throw new ApiProblem('import_not_ready', 'Validation must finish with at least one valid row before starting.', 409);
                }
                $run = $this->lifecycle->transition($context->organization, $run->id, ImportStatus::Queued);
                Audit::record('import.queued', $context->user, $run->organization_id, 'import_run', $run->id);
            }
            // A bounded pointer, not row data. Safe to redispatch after a broker outage.
            AwaitImportNormalization::dispatch($run->organization_id, $run->id)->onConnection('redis')->onQueue('import-normalization')->afterCommit();

            return $run;
        });

        return $this->response($run, 202);
    }

    public function cancel(Request $request, TenantContext $context, Organization $organization, string $import): JsonResponse
    {
        $this->authorizeImport($request, $context, true);
        $run = DB::transaction(function () use ($context, $import): ImportRun {
            $run = $this->access->run($context->organization, $import, true);
            if ($run->status !== ImportStatus::Canceled) {
                $run = $this->lifecycle->transition($context->organization, $run->id, ImportStatus::Canceled);
                Audit::record('import.canceled', $context->user, $run->organization_id, 'import_run', $run->id);
            }

            return $run;
        });

        return $this->response($run);
    }

    public function downloads(Request $request, TenantContext $context, Organization $organization, string $import): JsonResponse
    {
        $this->authorizeImport($request, $context);
        $run = $this->access->run($context->organization, $import);
        $file = $this->access->file($run);
        $parameters = ['organization' => $run->organization_id, 'import' => $run->id];
        $expires = now()->addMinutes(5);

        return response()->json(['expires_at' => $expires->toIso8601String(),
            'file_url' => URL::temporarySignedRoute('imports.file', $expires, [...$parameters, 'file' => $file->id], absolute: false),
            'errors_url' => URL::temporarySignedRoute('imports.errors', $expires, $parameters, absolute: false)]);
    }

    public function downloadFile(Request $request, TenantContext $context, Organization $organization, string $import, string $file): StreamedResponse
    {
        $this->authorizeImport($request, $context);
        $record = $this->access->file($this->access->run($context->organization, $import), $file);
        // Open before headers are sent, so storage failure uses the standard envelope.
        $stream = Storage::disk('s3')->readStream($record->storage_key);
        if (! is_resource($stream)) {
            throw new ApiProblem('import_storage_unavailable', 'Import storage is temporarily unavailable.', 503);
        }

        return response()->streamDownload(function () use ($stream): void {
            try { fpassthru($stream); } finally { fclose($stream); }
        }, $record->original_filename, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function downloadErrors(Request $request, TenantContext $context, Organization $organization, string $import): StreamedResponse
    {
        $this->authorizeImport($request, $context);
        $run = $this->access->run($context->organization, $import);

        return response()->streamDownload(function () use ($run): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) { return; }
            fputcsv($output, ['source_row', 'field', 'code', 'message'], escape: '');
            foreach (ImportError::query()->where('organization_id', $run->organization_id)->where('import_run_id', $run->id)->orderBy('source_row')->orderBy('id')->cursor() as $error) {
                $values = array_map(static fn ($value): string => preg_match('/^[=+@\-\t\r\n]/', (string) $value) ? "'".$value : (string) $value,
                    [$error->source_row, $error->field, $error->code, $error->message]);
                fputcsv($output, $values, escape: '');
            }
            fclose($output);
        }, 'import-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function authorizeImport(Request $request, TenantContext $context, bool $mutate = false, bool $feature = false): void
    {
        Gate::authorize($mutate ? 'create' : 'viewAny', [ImportRun::class, $context->organization]);
        if ($feature) {
            app(EntitlementService::class)->assertFeatureEnabled($context->organization, EntitlementKey::ManualImportsEnabled);
        }
        $request->validate(['organization_id' => ['prohibited']]);
    }

    private function requireMappingState(ImportRun $run): void
    {
        if ($run->status !== ImportStatus::NeedsMapping) {
            throw new ApiProblem('invalid_import_transition', 'The import cannot enter that state.', 409);
        }
    }

    private function response(ImportRun $run, int $status = 200): JsonResponse
    {
        $file = $this->access->file($run);

        return response()->json(['import' => $this->payload($run), 'file' => ['id' => $file->id, 'original_filename' => $file->original_filename,
            'size_bytes' => $file->size_bytes, 'format' => $file->format, 'headers' => $file->headers, 'sample_rows' => $file->sample_rows],
            'errors' => ImportError::query()->where('organization_id', $run->organization_id)->where('import_run_id', $run->id)->orderBy('source_row')->orderBy('id')
                ->limit(100)->get(['source_row', 'field', 'code', 'message'])->toArray()], $status);
    }

    /** @return array<string, mixed> */
    private function payload(ImportRun $run): array
    {
        $snapshot = $run->mapping_snapshot;
        if ($snapshot !== null) { $snapshot['source_conventions'] = (object) $snapshot['source_conventions']; }

        return ['id' => $run->id, 'restaurant_location_id' => $run->restaurant_location_id, 'supplier_account_id' => $run->supplier_account_id,
            'status' => $run->status->value, 'mapping_snapshot' => $snapshot, 'mapping_template_id' => $run->mapping_template_id,
            'validation_started_at' => $run->validation_started_at?->toIso8601String(), 'validation_completed_at' => $run->validation_completed_at?->toIso8601String(),
            'validated_through_row' => $run->validation_cursor, 'valid_rows' => RawImportRecord::query()->where('organization_id', $run->organization_id)->where('import_run_id', $run->id)->count(),
            'processed_count' => $run->processed_count, 'warning_count' => $run->warning_count, 'failed_count' => $run->failed_count,
            'created_at' => $run->created_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String()];
    }
}
