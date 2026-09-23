<?php

namespace Database\Seeders;

use App\Models\SupplierDefinition;
use Illuminate\Database\Seeder;

final class SupplierDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'us-foods' => 'US Foods',
            'sysco' => 'Sysco',
            'performance-foodservice' => 'Performance Foodservice',
            'freshpoint' => 'FreshPoint',
            'vistar' => 'Vistar',
            'prestige' => 'Prestige',
        ] as $code => $name) {
            SupplierDefinition::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'is_active' => true,
            ]);
        }
    }
}
