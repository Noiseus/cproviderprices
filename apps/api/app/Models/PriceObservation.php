<?php

namespace App\Models;

use App\Enums\OfferAvailability;
use Carbon\CarbonImmutable;
use Database\Factories\PriceObservationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable, tenant-private source fact used for price comparison history.
 *
 * @property string $case_price
 * @property string|null $normalized_quantity
 * @property array<string, mixed> $source_price_evidence
 * @property array<string, mixed> $source_pack_evidence
 * @property OfferAvailability $availability
 * @property CarbonImmutable|null $source_updated_at
 * @property CarbonImmutable $observed_at
 * @property CarbonImmutable|null $imported_at
 */
class PriceObservation extends Model
{
    /** @use HasFactory<PriceObservationFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Price observations are immutable source facts.');
        });
        static::deleting(function (): never {
            throw new \LogicException('Price observations are immutable source facts.');
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'supplier_offer_id',
        'import_run_id',
        'normalized_import_record_id',
        'case_price',
        'currency',
        'source_price_evidence',
        'source_pack_evidence',
        'normalized_quantity',
        'normalized_unit',
        'dimension',
        'availability',
        'source_updated_at',
        'observed_at',
        'imported_at',
        'formula_version',
        'match_evidence',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'case_price' => 'decimal:6',
            'normalized_quantity' => 'decimal:12',
            'source_price_evidence' => 'array',
            'source_pack_evidence' => 'array',
            'availability' => OfferAvailability::class,
            'source_updated_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'imported_at' => 'immutable_datetime',
            'match_evidence' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<SupplierOffer, $this> */
    public function supplierOffer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class);
    }

    /** @return BelongsTo<ImportRun, $this> */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }

    /** @return BelongsTo<NormalizedImportRecord, $this> */
    public function normalizedImportRecord(): BelongsTo
    {
        return $this->belongsTo(NormalizedImportRecord::class);
    }
}
