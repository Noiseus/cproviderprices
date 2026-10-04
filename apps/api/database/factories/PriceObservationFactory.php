<?php

namespace Database\Factories;

use App\Enums\OfferAvailability;
use App\Models\PriceObservation;
use App\Models\SupplierOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceObservation> */
class PriceObservationFactory extends Factory
{
    protected $model = PriceObservation::class;

    public function definition(): array
    {
        $offer = SupplierOffer::factory()->create();
        $normalized = $offer->vendorItem->lastNormalizedImportRecord;

        return [
            'organization_id' => $offer->organization_id,
            'supplier_offer_id' => $offer->id,
            'import_run_id' => $normalized->import_run_id,
            'normalized_import_record_id' => $normalized->id,
            'case_price' => '24.500000',
            'currency' => 'USD',
            'source_price_evidence' => ['source_price' => '24.50', 'source_currency' => 'USD'],
            'source_pack_evidence' => ['packs' => []],
            'normalized_quantity' => '11339.809250000000',
            'normalized_unit' => 'g',
            'dimension' => 'weight',
            'availability' => OfferAvailability::Unknown,
            'observed_at' => now(),
            'formula_version' => 'price-comparison-1.0.0',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (PriceObservation $observation): void {
            $observation->supplierOffer()->update([
                'current_price_observation_id' => $observation->id,
            ]);
        });
    }
}
