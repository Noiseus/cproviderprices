<?php

namespace App\Services;

use App\Models\ImportRun;
use App\Models\RawImportRecord;
use App\Support\NormalizationFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class ImportNormalizer
{
    public const VERSION = '1.0.0';

    public const FIELDS = ['supplier_sku', 'description', 'brand', 'pack_size_text', 'quantity', 'weight', 'volume', 'unit', 'price', 'currency', 'gtin'];

    /** @return array<string, mixed> */
    public function record(ImportRun $run, RawImportRecord $raw): array
    {
        $mapped = array_fill_keys(self::FIELDS, null);
        /** @var array<string, mixed> $source */
        $source = $raw->mapped_values;
        foreach ($source as $key => $value) {
            if (! array_key_exists($key, $mapped) || ! is_string($value) || mb_strlen($value) > 4096) {
                throw new NormalizationFailure('invalid_local_payload');
            }
            $mapped[$key] = $value;
        }
        foreach (['supplier_sku', 'description'] as $key) {
            if (! is_string($mapped[$key]) || trim($mapped[$key]) === '') {
                throw new NormalizationFailure('invalid_local_payload');
            }
        }
        $conventions = $run->mapping_snapshot['source_conventions'] ?? [];
        if (! is_array($conventions) || array_diff(array_keys($conventions), ['volume_system', 'produce_size_code', 'pound_sign_is_weight']) !== []) {
            throw new NormalizationFailure('invalid_local_payload');
        }
        $conventions += ['volume_system' => null, 'produce_size_code' => false, 'pound_sign_is_weight' => false];
        foreach (['produce_size_code', 'pound_sign_is_weight'] as $key) {
            // Phase B's Laravel boolean validator also admits 0/1 and "0"/"1".
            if (! in_array($conventions[$key], [true, false, 0, 1, '0', '1'], true)) {
                throw new NormalizationFailure('invalid_local_payload');
            }
            $conventions[$key] = (bool) $conventions[$key];
        }
        if (! in_array($conventions['volume_system'], [null, 'us_liquid'], true)) {
            throw new NormalizationFailure('invalid_local_payload');
        }

        return ['record_id' => $raw->id, 'correlation_id' => $run->request_id, 'mapped' => $mapped, 'source_conventions' => $conventions];
    }

    /** @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function normalize(string $correlation, array $records): array
    {
        $url = config('services.ingestion.url');
        $token = config('services.ingestion.token');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || ! preg_match('~^https?://[^/?#@]+(?::[0-9]+)?$~D', $url) || ! is_string($token) || trim($token) === '' || preg_match('/[\r\n]/', $token)) {
            throw new NormalizationFailure('invalid_service_configuration');
        }
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $correlation) || count($records) < 1 || count($records) > 100) {
            throw new NormalizationFailure('invalid_local_payload');
        }
        $body = json_encode(['correlation_id' => $correlation, 'records' => $records], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false || strlen($body) > 1048576) {
            throw new NormalizationFailure('invalid_local_payload');
        }
        try {
            $response = Http::withToken($token)->acceptJson()->withHeaders(['X-Request-ID' => $correlation])
                ->connectTimeout(3)->timeout(min(30, max(1, (int) config('services.ingestion.timeout'))))
                ->withoutRedirecting()->withBody($body, 'application/json')->post($url.'/internal/v1/normalize');
        } catch (ConnectionException) {
            throw new NormalizationFailure('service_unavailable', true);
        }
        if (in_array($response->status(), [429, 502, 503, 504], true)) {
            throw new NormalizationFailure('service_unavailable', true);
        }
        if ($response->status() !== 200) {
            throw new NormalizationFailure(in_array($response->status(), [401, 403], true) ? 'service_authentication' : 'service_rejected_request');
        }
        $data = $response->json();
        if (! is_array($data) || ($data['parser_version'] ?? null) !== self::VERSION || ($data['correlation_id'] ?? null) !== $correlation
            || $response->header('X-Request-ID') !== $correlation || ($data['processed'] ?? null) !== count($records)
            || ($data['failed'] ?? null) !== 0 || ! is_array($data['results'] ?? null) || ! array_is_list($data['results']) || count($data['results']) !== count($records)) {
            throw new NormalizationFailure('invalid_service_response');
        }
        $this->shape($data, ['parser_version', 'correlation_id', 'results', 'processed', 'warning', 'failed']);
        $expected = array_column($records, null, 'record_id');
        $seen = [];
        $warning = 0;
        foreach ($data['results'] as $result) {
            $id = is_array($result) ? ($result['record_id'] ?? null) : null;
            if (! is_string($id) || ! isset($expected[$id]) || isset($seen[$id]) || ($result['parser_version'] ?? null) !== self::VERSION
                || ($result['correlation_id'] ?? null) !== $correlation || ! $this->sameOriginal($result['original'] ?? null, $expected[$id])) {
                throw new NormalizationFailure('invalid_service_response');
            }
            $this->validateResult($result);
            $seen[$id] = true;
            $warning += $result['warnings'] === [] ? 0 : 1;
        }
        if (($data['warning'] ?? null) !== $warning) {
            throw new NormalizationFailure('invalid_service_response');
        }

        return $data['results'];
    }

    /** @param array<string, mixed> $result */
    private function validateResult(array $result): void
    {
        $this->shape($result, ['parser_version', 'record_id', 'correlation_id', 'original', 'measurements', 'packs', 'case_quantity', 'size_code', 'variable_weight', 'warnings', 'confidence']);
        if (! in_array($result['confidence'], ['high', 'medium', 'low'], true) || ! is_bool($result['variable_weight'])) {
            throw new NormalizationFailure('invalid_service_response');
        }
        $this->text($result['case_quantity'], true);
        $this->text($result['size_code'], true);
        foreach (['measurements', 'packs', 'warnings'] as $key) {
            if (! is_array($result[$key]) || ! array_is_list($result[$key])) {
                throw new NormalizationFailure('invalid_service_response');
            }
        }
        foreach ($result['measurements'] as $measurement) {
            $this->measurement($measurement);
        }
        foreach ($result['packs'] as $pack) {
            $this->shape($pack, ['field', 'original_text', 'original_count', 'pack_count', 'unit_size', 'case_total', 'case_total_unit']);
            foreach (['field', 'original_text', 'original_count', 'pack_count'] as $key) {
                $this->text($pack[$key]);
            }
            $this->text($pack['case_total'], true);
            $this->measurement($pack['unit_size']);
            if (! in_array($pack['case_total_unit'], [null, 'g', 'mL', 'ea'], true)) {
                throw new NormalizationFailure('invalid_service_response');
            }
        }
        foreach ($result['warnings'] as $warning) {
            $this->shape($warning, ['code', 'field', 'message']);
            $this->text($warning['field']);
            $this->text($warning['message']);
            if (! in_array($warning['code'], ['missing_quantity', 'ambiguous_quantity', 'ambiguous_unit', 'ambiguous_pack', 'ambiguous_size_code', 'unit_system_required', 'unsupported_unit', 'unsafe_conversion', 'conflicting_values', 'variable_weight'], true)) {
                throw new NormalizationFailure('invalid_service_response');
            }
        }
    }

    private function measurement(mixed $value): void
    {
        $this->shape($value, ['field', 'original_text', 'original_value', 'original_unit', 'dimension', 'value', 'unit', 'normalized_value', 'normalized_unit']);
        foreach (['field', 'original_text', 'original_value', 'original_unit', 'value'] as $key) {
            $this->text($value[$key]);
        }
        $this->text($value['unit'], true);
        $this->text($value['normalized_value'], true);
        if (! in_array($value['dimension'], [null, 'weight', 'volume', 'count'], true) || ! in_array($value['normalized_unit'], [null, 'g', 'mL', 'ea'], true)) {
            throw new NormalizationFailure('invalid_service_response');
        }
    }

    /** @param list<string> $keys */
    private function shape(mixed $value, array $keys): void
    {
        if (! is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value)) !== []) {
            throw new NormalizationFailure('invalid_service_response');
        }
    }

    private function text(mixed $value, bool $nullable = false): void
    {
        if (! is_string($value) && ! ($nullable && $value === null)) {
            throw new NormalizationFailure('invalid_service_response');
        }
    }

    /** JSON object ordering is irrelevant, but original scalar types are not. */
    private function sameOriginal(mixed $actual, mixed $expected): bool
    {
        if (! is_array($expected)) {
            return $actual === $expected;
        }
        if (! is_array($actual) || count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || ! $this->sameOriginal($actual[$key], $value)) {
                return false;
            }
        }

        return true;
    }
}
