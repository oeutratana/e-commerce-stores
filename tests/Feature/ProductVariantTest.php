<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => 1]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'category_id' => Category::create(['name' => 'Clothing'])->id,
            'user_id' => User::factory()->create()->id,
            'name' => 'T-Shirt',
            'description' => 'A comfortable shirt',
            'price' => 21.00,
            'stock' => 100,
        ]);
    }

    public function test_admin_can_create_a_product_variant(): void
    {
        $product = $this->makeProduct();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/product-variants', [
                'product_id' => $product->id,
                'size' => 'large',
                'price' => 23.00,
                'stock' => 50,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.size', 'large')
            ->assertJsonPath('data.price', 23);

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'size' => 'large',
            'price' => 23.00,
        ]);
    }

    public function test_duplicate_size_for_same_product_is_rejected(): void
    {
        $product = $this->makeProduct();
        $admin = $this->admin();

        ProductVariant::create([
            'product_id' => $product->id,
            'size' => 'small',
            'price' => 19.00,
            'stock' => 10,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/product-variants', [
                'product_id' => $product->id,
                'size' => 'small',
                'price' => 21.00,
                'stock' => 10,
            ]);

        $response->assertStatus(409);
    }

    public function test_payload_requires_valid_size(): void
    {
        $product = $this->makeProduct();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/product-variants', [
                'product_id' => $product->id,
                'size' => 'extra-large',
                'price' => 20.00,
                'stock' => 5,
            ]);

        $response->assertStatus(422);
    }

    public function test_public_can_list_variants_by_product(): void
    {
        $product = $this->makeProduct();

        ProductVariant::create([
            'product_id' => $product->id,
            'size' => 'small',
            'price' => 19.00,
            'stock' => 10,
        ]);

        $response = $this->getJson('/api/product-variants?product_id='.$product->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
