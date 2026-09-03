<?php

namespace Tests\Feature\Catalog;

use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Polymorphic pivots have no foreign key, so nothing in the database removes
 * them when their record goes. Primary keys are auto-increment, so an orphan
 * row is not merely untidy — the next record on that id inherits it.
 */
class MorphCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function categorisedProduct(): Product
    {
        $product = Product::factory()->create();
        $product->categories()->attach(Category::factory()->create());

        return $product;
    }

    private function categorizableRows(Product $product): int
    {
        return DB::table('categorizables')
            ->where('categorizable_type', $product->getMorphClass())
            ->where('categorizable_id', $product->getKey())
            ->count();
    }

    /**
     * The property that protects a restore: a trashed product must come back
     * with its classifications intact.
     */
    public function test_a_soft_delete_keeps_polymorphic_rows(): void
    {
        $product = $this->categorisedProduct();

        $product->delete();

        $this->assertSame(1, $this->categorizableRows($product));

        $product->restore();

        $this->assertCount(1, $product->fresh()->categories);
    }

    public function test_a_force_delete_clears_polymorphic_rows(): void
    {
        $product = $this->categorisedProduct();

        $product->forceDelete();

        $this->assertSame(0, $this->categorizableRows($product));
    }

    /**
     * catalog_relations is DOUBLE polymorphic and is the biggest morph table
     * keyed on these models. Both ends matter: the rail this record owns, and
     * every other record's rail pointing AT it. Missing the second leaves a
     * deleted product showing up in other products' "Related" rails.
     */
    public function test_a_force_delete_clears_both_ends_of_a_catalog_relation(): void
    {
        $subject = Product::factory()->create();
        $other = Product::factory()->create();

        // Inserted directly: the model exposes reads (relatedItems() returns a
        // Collection), and what is under test is the row lifecycle, not the API.
        DB::table('catalog_relations')->insert([
            [
                'source_type' => $subject->getMorphClass(), 'source_id' => $subject->getKey(),
                'related_type' => $other->getMorphClass(), 'related_id' => $other->getKey(),
                'relation_type' => 'related', 'position' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'source_type' => $other->getMorphClass(), 'source_id' => $other->getKey(),
                'related_type' => $subject->getMorphClass(), 'related_id' => $subject->getKey(),
                'relation_type' => 'related', 'position' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->assertSame(2, DB::table('catalog_relations')->count());

        $subject->forceDelete();

        $this->assertSame(
            0,
            DB::table('catalog_relations')
                ->where(fn ($q) => $q->where('source_id', $subject->getKey())->orWhere('related_id', $subject->getKey()))
                ->count()
        );
    }

    /**
     * The reason this matters. Without cleanup the orphan row is keyed on an id
     * the database will hand out again, and the next product to occupy it
     * inherits categories nobody assigned.
     */
    public function test_a_later_record_on_the_same_id_inherits_nothing(): void
    {
        $product = $this->categorisedProduct();
        $id = $product->getKey();

        $product->forceDelete();

        $reused = Product::factory()->create(['id' => $id]);

        $this->assertCount(0, $reused->categories);
    }
}
