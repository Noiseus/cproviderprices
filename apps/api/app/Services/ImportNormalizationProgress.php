<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Models\ImportError;
use App\Models\ImportRun;
use App\Models\NormalizedImportRecord;
use App\Models\RawImportRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/** Call mutations only while holding the import-run row lock. */
final class ImportNormalizationProgress
{
    /** @return Builder<RawImportRecord> */
    public function remaining(ImportRun $run): Builder
    {
        return RawImportRecord::query()->where('organization_id', $run->organization_id)->where('import_run_id', $run->id)
            ->where('status', '!=', 'failed')->whereNotIn('id', NormalizedImportRecord::query()
            ->select('raw_import_record_id')->where('organization_id', $run->organization_id)->where('import_run_id', $run->id)->where('parser_version', ImportNormalizer::VERSION));
    }

    public function update(ImportRun $run): void
    {
        $results = NormalizedImportRecord::query()->where('organization_id', $run->organization_id)->where('import_run_id', $run->id)->where('parser_version', ImportNormalizer::VERSION);
        $run->processed_count = (clone $results)->count();
        $run->warning_count = (clone $results)->whereRaw('jsonb_array_length(warnings) > 0')->count();
        // Validation errors have no raw row; processing errors do. Count distinct source rows,
        // not messages, and never accumulate counters by delivery count.
        $run->failed_count = ImportError::query()->where('organization_id', $run->organization_id)->where('import_run_id', $run->id)->whereNotNull('source_row')->distinct()->count('source_row');
        $run->save();
        $remaining = $this->remaining($run)->count();
        if ($remaining === 0 && $run->status === ImportStatus::Processing) {
            $next = $run->processed_count === 0 ? ImportStatus::Failed : ($run->failed_count > 0 ? ImportStatus::PartiallyCompleted : ImportStatus::Completed);
            app(ImportLifecycleService::class)->transition($run->organization, $run->id, $next);
        }
        Log::info('import.normalization.progress', ['event' => 'import.normalization.progress', 'correlation_id' => $run->request_id,
            'organization_id' => $run->organization_id, 'import_id' => $run->id,
            'processed' => $run->processed_count, 'warning' => $run->warning_count, 'failed' => $run->failed_count,
            'remaining' => $remaining, 'retry' => $run->retry_count]);
    }

    public function failRow(ImportRun $run, RawImportRecord $raw, string $reason): void
    {
        ImportError::query()->firstOrCreate(['organization_id' => $run->organization_id, 'import_run_id' => $run->id,
            'error_key' => 'normalization:'.ImportNormalizer::VERSION.':'.$raw->id], [
                'source_row' => $raw->source_row, 'code' => $reason,
                // Correlation remains available through the immutable run reference, never supplier data.
                'message' => 'Normalization could not complete. Reference: '.$run->request_id,
            ]);
        $raw->update(['status' => 'failed']);
    }
}
