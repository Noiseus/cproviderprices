<?php

namespace App\Services;

use App\Models\ImportFile;
use App\Models\ImportMappingTemplate;
use App\Models\ImportRun;
use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ImportMapping
{
    public const FIELDS = ['supplier_sku', 'description', 'brand', 'pack_size_text', 'quantity', 'weight', 'volume', 'unit', 'price', 'currency', 'gtin'];

    public function confirm(Request $request, ImportRun $run, ImportFile $file): void
    {
        $data = $request->validate([
            'mapping' => ['required_without:template_id', 'array:'.implode(',', self::FIELDS)],
            'mapping.*' => ['required', 'string', Rule::in($file->headers)],
            'mapping.supplier_sku' => ['required_without:template_id', 'string'],
            'mapping.description' => ['required_without:template_id', 'string'],
            'template_id' => ['sometimes', 'uuid', 'prohibits:mapping,source_conventions,template_name'],
            'template_name' => ['sometimes', 'string', 'max:100'],
            'source_conventions' => ['sometimes', 'array:volume_system,produce_size_code,pound_sign_is_weight'],
            'source_conventions.volume_system' => ['sometimes', Rule::in(['us_liquid'])],
            'source_conventions.produce_size_code' => ['sometimes', 'boolean'],
            'source_conventions.pound_sign_is_weight' => ['sometimes', 'boolean'],
        ]);
        $template = null;
        if (isset($data['template_id'])) {
            $template = ImportMappingTemplate::query()->where('organization_id', $run->organization_id)
                ->where('supplier_account_id', $run->supplier_account_id)->whereKey($data['template_id'])->first();
            if (! $template instanceof ImportMappingTemplate) {
                throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
            }
            if ($template->headers !== $file->headers) {
                throw new ApiProblem('mapping_headers_mismatch', 'The template headers do not match this file.', 422);
            }
            $mapping = $template->mapping;
            $conventions = $template->source_conventions;
        } else {
            $mapping = $data['mapping'];
            $conventions = $data['source_conventions'] ?? [];
        }
        if (count(array_unique($mapping)) !== count($mapping)) {
            throw new ApiProblem('invalid_mapping', 'Each source column can be mapped only once.', 422);
        }
        if (isset($data['template_name'])) {
            // The controller locks the account before saving any reusable template.
            $template = ImportMappingTemplate::query()->updateOrCreate([
                'organization_id' => $run->organization_id, 'supplier_account_id' => $run->supplier_account_id,
                'name' => $data['template_name'],
            ], ['headers' => $file->headers, 'mapping' => $mapping, 'source_conventions' => $conventions]);
        }
        $run->mapping_template_id = $template?->id;
        $run->mapping_snapshot = ['version' => 1, 'headers' => $file->headers, 'mapping' => $mapping, 'source_conventions' => $conventions];
        $run->save();
    }
}
