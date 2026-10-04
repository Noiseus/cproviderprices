<?php

namespace App\Services;

use App\Models\NormalizedImportRecord;
use App\Models\VendorItem;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;

final class VendorItemProjectionService
{
    public function project(NormalizedImportRecord $normalized): VendorItem
    {
        return DB::transaction(function () use ($normalized): VendorItem {
            $normalized->loadMissing('rawImportRecord.importRun');
            $raw = $normalized->rawImportRecord;
            $run = $raw->importRun;
            /** @var array<string, mixed> $mapped */
            $mapped = $raw->mapped_values;
            $sku = $this->text($mapped['supplier_sku'] ?? null);
            $description = $this->text($mapped['description'] ?? null);
            if ($sku === null || $description === null) {
                throw new \LogicException('A normalized record must retain its supplier SKU and description.');
            }
            $brand = $this->text($mapped['brand'] ?? null);
            $attributes = $this->attributes($normalized, $mapped);
            $identity = $this->identity($sku, $description, $brand, $attributes);
            $now = now();

            $vendor = VendorItem::query()
                ->where('organization_id', $normalized->organization_id)
                ->where('supplier_account_id', $run->supplier_account_id)
                ->where('identity_key', $identity)
                ->lockForUpdate()
                ->first();
            if ($vendor === null) {
                return VendorItem::query()->create([
                    'organization_id' => $normalized->organization_id,
                    'supplier_account_id' => $run->supplier_account_id,
                    'first_normalized_import_record_id' => $normalized->id,
                    'last_normalized_import_record_id' => $normalized->id,
                    'supplier_item_identifier' => $sku,
                    'identity_key' => $identity,
                    'raw_description' => $description,
                    'normalized_description' => CatalogText::normalize($description),
                    'brand' => $brand,
                    'normalized_attributes' => $attributes,
                    'parser_version' => $normalized->parser_version,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            }

            // Immutable raw and normalized records keep every observation. The
            // vendor item is the current safe projection, not a replacement for it.
            $vendor->update([
                'last_normalized_import_record_id' => $normalized->id,
                'raw_description' => $description,
                'normalized_description' => CatalogText::normalize($description),
                'brand' => $brand,
                'normalized_attributes' => $attributes,
                'parser_version' => $normalized->parser_version,
                'last_seen_at' => $now,
            ]);

            return $vendor->refresh();
        });
    }

    /** @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private function attributes(NormalizedImportRecord $normalized, array $mapped): array
    {
        /** @var array<string, mixed> $values */
        $values = $normalized->normalized_values;
        $dimensions = [];
        foreach (($values['measurements'] ?? []) as $measurement) {
            if (is_array($measurement) && is_string($measurement['dimension'] ?? null)) {
                $dimensions[] = $measurement['dimension'];
            }
        }
        foreach (($values['packs'] ?? []) as $pack) {
            if (is_array($pack) && is_array($pack['unit_size'] ?? null) && is_string($pack['unit_size']['dimension'] ?? null)) {
                $dimensions[] = $pack['unit_size']['dimension'];
            }
        }
        $dimensions = array_values(array_unique($dimensions));
        sort($dimensions, SORT_STRING);
        $identifiers = [];
        $gtin = $this->text($mapped['gtin'] ?? null);
        if ($gtin !== null && preg_match('/^[0-9]{8,14}$/', $gtin)) {
            $identifiers[] = ['type' => 'gtin', 'value' => $gtin];
        }

        return [
            'size_code' => is_string($values['size_code'] ?? null) ? $values['size_code'] : null,
            'variable_weight' => (bool) ($values['variable_weight'] ?? false),
            'measurements' => is_array($values['measurements'] ?? null) ? $values['measurements'] : [],
            'packs' => is_array($values['packs'] ?? null) ? $values['packs'] : [],
            'case_quantity' => is_string($values['case_quantity'] ?? null) ? $values['case_quantity'] : null,
            'dimensions' => $dimensions,
            'identifiers' => $identifiers,
            'parser_confidence' => $normalized->confidence,
            'parser_warnings' => array_map(static fn (array $warning): string => $warning['code'], $normalized->warnings),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function identity(string $sku, string $description, ?string $brand, array $attributes): string
    {
        // Supplier SKU remains private vendor-item identity; it is never written
        // to product_identifiers or treated as a global product identifier.
        if (trim($sku) !== '') {
            return 'sku:'.mb_strtoupper(trim($sku));
        }

        return 'evidence:'.CatalogText::fingerprint([
            'description' => CatalogText::normalize($description),
            'brand' => $brand === null ? null : CatalogText::normalize($brand),
            'size_code' => $attributes['size_code'] ?? null,
            'packs' => $attributes['packs'] ?? [],
            'measurements' => $attributes['measurements'] ?? [],
        ]);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
