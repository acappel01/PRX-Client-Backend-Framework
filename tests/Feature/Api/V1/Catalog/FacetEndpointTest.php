<?php

namespace Tests\Feature\Api\V1\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Ingredient;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacetEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_facets_return_counts_for_published_products_only(): void
    {
        $peptides = ProductClass::factory()->create(['name' => 'Peptides']);
        $emptyClass = ProductClass::factory()->create(['name' => 'Unused']);
        $category = Category::factory()->create(['name' => 'Recovery']);
        $ingredient = Ingredient::factory()->create(['name' => 'BPC-157']);

        $published = Product::factory()->create([
            'status' => CatalogStatus::Published,
            'product_class_id' => $peptides->id,
            'retail_price' => 100,
            'sale_price' => 80,
            'is_in_stock' => true,
        ]);
        $published->categories()->attach($category->id);
        $published->ingredients()->attach($ingredient->id, ['position' => 0]);

        $draft = Product::factory()->create([
            'status' => CatalogStatus::Draft,
            'product_class_id' => $peptides->id,
        ]);

        Product::factory()->create([
            'status' => CatalogStatus::Published,
            'retail_price' => 250,
            'is_in_stock' => false,
        ]);

        $response = $this->getJson('/api/v1/catalog/facets')->assertOk();

        $this->assertSame(
            // `package_count` accompanies every facet row: one endpoint serves
            // both listings, and the stacks filter needs its own figure or it
            // offers options counted by products that return no packages.
            [['name' => 'Peptides', 'slug' => $peptides->slug, 'count' => 1, 'package_count' => 0]],
            $response->json('data.classes')
        );
        $this->assertSame([], collect($response->json('data.classes'))->where('slug', $emptyClass->slug)->values()->all());
        $this->assertSame('Recovery', $response->json('data.categories.0.name'));
        $this->assertSame('BPC-157', $response->json('data.ingredients.0.name'));
        $this->assertEquals(80, $response->json('data.price.min'));
        $this->assertEquals(250, $response->json('data.price.max'));
        $this->assertSame(1, $response->json('data.availability.in_stock'));
        $this->assertSame(1, $response->json('data.availability.out_of_stock'));
    }

    public function test_products_can_be_filtered_by_ingredient(): void
    {
        $ingredient = Ingredient::factory()->create(['name' => 'GHK-Cu']);
        $match = Product::factory()->create(['status' => CatalogStatus::Published, 'name' => 'Match']);
        $match->ingredients()->attach($ingredient->id, ['position' => 0]);
        Product::factory()->create(['status' => CatalogStatus::Published, 'name' => 'Other']);

        $this->assertSame(
            ['Match'],
            $this->getJson("/api/v1/catalog/products?ingredient={$ingredient->slug}")->json('data.*.name')
        );
    }
}
