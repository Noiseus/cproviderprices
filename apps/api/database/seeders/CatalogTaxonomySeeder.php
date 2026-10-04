<?php

namespace Database\Seeders;

use App\Models\CanonicalProduct;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\ProductAlias;
use App\Support\CatalogText;
use Illuminate\Database\Seeder;

final class CatalogTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $produce = Category::query()->updateOrCreate(['code' => 'produce'], ['slug' => 'produce', 'english_name' => 'Produce']);
        $tomatoes = Category::query()->updateOrCreate(['code' => 'fresh-tomatoes'], ['parent_id' => $produce->id, 'slug' => 'fresh-tomatoes', 'english_name' => 'Fresh Tomatoes']);
        CategoryTranslation::query()->updateOrCreate(['category_id' => $tomatoes->id, 'locale' => 'es'], ['name' => 'Tomates frescos']);

        $products = [
            'tomato' => ['name' => 'Tomato', 'attributes' => ['family' => 'tomato', 'form' => 'fresh'], 'aliases' => [['en', 'tomato'], ['es', 'tomate']]],
            'beefsteak-tomato' => ['name' => 'Beefsteak Tomato', 'attributes' => ['family' => 'tomato', 'form' => 'fresh', 'variety' => 'beefsteak'], 'aliases' => [['en', 'beef tomato'], ['en', 'beefsteak tomato'], ['es', 'tomate beefsteak'], ['es', 'tomate bistec']]],
            'roma-tomato' => ['name' => 'Roma Tomato', 'attributes' => ['family' => 'tomato', 'form' => 'fresh', 'variety' => 'roma'], 'aliases' => [['en', 'roma tomato'], ['es', 'tomate roma']]],
        ];
        foreach ($products as $key => $product) {
            $canonical = CanonicalProduct::query()->updateOrCreate(['category_id' => $tomatoes->id, 'normalized_key' => CatalogText::normalize($key)], ['english_name' => $product['name'], 'attributes' => $product['attributes'], 'is_active' => true]);
            foreach ($product['aliases'] as [$locale, $alias]) {
                ProductAlias::query()->updateOrCreate(['canonical_product_id' => $canonical->id, 'product_variant_id' => null, 'locale' => $locale, 'normalized_alias' => CatalogText::normalize($alias)], ['alias' => $alias]);
            }
        }
    }
}
