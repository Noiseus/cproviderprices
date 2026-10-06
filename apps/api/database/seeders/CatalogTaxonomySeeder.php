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
        $poultry = Category::query()->updateOrCreate(['code' => 'poultry'], ['slug' => 'poultry', 'english_name' => 'Poultry']);
        $oils = Category::query()->updateOrCreate(['code' => 'oils'], ['slug' => 'oils', 'english_name' => 'Oils']);
        $cheese = Category::query()->updateOrCreate(['code' => 'cheese'], ['slug' => 'cheese', 'english_name' => 'Cheese']);
        $pantry = Category::query()->updateOrCreate(['code' => 'pantry'], ['slug' => 'pantry', 'english_name' => 'Pantry']);
        $beverages = Category::query()->updateOrCreate(['code' => 'beverages'], ['slug' => 'beverages', 'english_name' => 'Beverages']);
        CategoryTranslation::query()->updateOrCreate(['category_id' => $poultry->id, 'locale' => 'es'], ['name' => 'Aves']);
        CategoryTranslation::query()->updateOrCreate(['category_id' => $oils->id, 'locale' => 'es'], ['name' => 'Aceites']);
        CategoryTranslation::query()->updateOrCreate(['category_id' => $cheese->id, 'locale' => 'es'], ['name' => 'Quesos']);

        $products = [
            'tomato' => ['name' => 'Tomato', 'attributes' => ['family' => 'tomato', 'form' => 'fresh'], 'aliases' => [['en', 'tomato'], ['es', 'tomate']]],
            'beefsteak-tomato' => ['name' => 'Beefsteak Tomato', 'attributes' => ['family' => 'tomato', 'form' => 'fresh', 'variety' => 'beefsteak'], 'aliases' => [['en', 'beef tomato'], ['en', 'beefsteak tomato'], ['es', 'tomate beefsteak'], ['es', 'tomate bistec']]],
            'roma-tomato' => ['name' => 'Roma Tomato', 'attributes' => ['family' => 'tomato', 'form' => 'fresh', 'variety' => 'roma'], 'aliases' => [['en', 'roma tomato'], ['es', 'tomate roma']]],
            'grape-tomato' => ['name' => 'Grape Tomato', 'attributes' => ['family' => 'tomato', 'form' => 'fresh', 'variety' => 'grape'], 'aliases' => [['en', 'grape tomato'], ['es', 'tomate uva']]],
            'cherry-tomato' => ['name' => 'Cherry Tomato', 'attributes' => ['family' => 'tomato', 'form' => 'fresh', 'variety' => 'cherry'], 'aliases' => [['en', 'cherry tomato'], ['es', 'tomate cherry']]],
            'vine-ripe-tomato' => ['name' => 'Vine-ripe Tomato', 'attributes' => ['family' => 'tomato', 'form' => 'fresh', 'variety' => 'vine-ripe'], 'aliases' => [['en', 'vine-ripe tomato'], ['es', 'tomate en rama']]],
        ];
        foreach ($products as $key => $product) {
            $canonical = CanonicalProduct::query()->updateOrCreate(['category_id' => $tomatoes->id, 'normalized_key' => CatalogText::normalize($key)], ['english_name' => $product['name'], 'attributes' => $product['attributes'], 'is_active' => true]);
            foreach ($product['aliases'] as [$locale, $alias]) {
                ProductAlias::query()->updateOrCreate(['canonical_product_id' => $canonical->id, 'product_variant_id' => null, 'locale' => $locale, 'normalized_alias' => CatalogText::normalize($alias)], ['alias' => $alias]);
            }
        }

        foreach ([
            ['category' => $poultry, 'key' => 'chicken-breast', 'name' => 'Chicken Breast', 'aliases' => [['en', 'chicken breast'], ['es', 'pechuga de pollo']]],
            ['category' => $oils, 'key' => 'canola-oil', 'name' => 'Canola Oil', 'aliases' => [['en', 'canola oil'], ['es', 'aceite de canola']]],
            ['category' => $cheese, 'key' => 'mozzarella-cheese', 'name' => 'Mozzarella Cheese', 'aliases' => [['en', 'mozzarella cheese'], ['es', 'queso mozzarella']]],
            ['category' => $pantry, 'key' => 'all-purpose-flour', 'name' => 'All-Purpose Flour', 'aliases' => [['en', 'all purpose flour'], ['es', 'harina de trigo']]],
            ['category' => $beverages, 'key' => 'sparkling-water', 'name' => 'Sparkling Water', 'aliases' => [['en', 'sparkling water'], ['es', 'agua con gas']]],
        ] as $product) {
            $canonical = CanonicalProduct::query()->updateOrCreate(
                ['category_id' => $product['category']->id, 'normalized_key' => CatalogText::normalize($product['key'])],
                ['english_name' => $product['name'], 'attributes' => [], 'is_active' => true],
            );
            foreach ($product['aliases'] as [$locale, $alias]) {
                ProductAlias::query()->updateOrCreate(
                    ['canonical_product_id' => $canonical->id, 'product_variant_id' => null, 'locale' => $locale, 'normalized_alias' => CatalogText::normalize($alias)],
                    ['alias' => $alias],
                );
            }
        }
    }
}
