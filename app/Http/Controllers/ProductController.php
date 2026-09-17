<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    // GET /api/products  (public)
    public function index()
    {
        $products = Product::with(['category', 'user', 'variants'])->get()->map(function ($product) {
            return $this->productResponse($product);
        });

        return apiResponse($products, 200, 'Get products successfully.');
    }

    // GET /api/products/{id}  (public)
    public function show($id)
    {
        $product = Product::with(['category', 'user', 'variants'])->findOrFail($id);

        return apiResponse($this->productResponse($product), 200, 'Get product successfully.');
    }

    // POST /api/products
    public function store(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'image' => $req->hasFile('image') ? 'image|max:2048' : 'nullable|url|max:2048',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();
        $data['user_id'] = $req->user()->id;

        $existingProduct = Product::where('user_id', $data['user_id'])
            ->where('category_id', $data['category_id'])
            ->where('name', $data['name'])
            ->first();

        if ($existingProduct) {
            return apiResponse($this->productResponse($existingProduct->load(['category', 'user', 'variants'])), 200, 'Product already exists.');
        }

        if ($req->hasFile('image')) {
            $data['image'] = $this->saveImage($req);
        } elseif (! empty(trim((string) $req->input('image')))) {
            $data['image'] = trim((string) $req->input('image'));
        }

        $product = Product::create($data);

        return apiResponse($this->productResponse($product->load(['category', 'user', 'variants'])), 201, 'Product created successfully.');
    }

    // PUT /api/products/{id}  (admin)
    public function update(Request $req, $id)
    {
        $product = Product::findOrFail($id);

        $validator = Validator::make($req->all(), [
            'category_id' => 'sometimes|required|exists:categories,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'stock' => 'sometimes|required|integer|min:0',
            'image' => $req->hasFile('image') ? 'image|max:2048' : 'nullable|url|max:2048',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        if ($req->hasFile('image')) {
            if ($product->image && File::exists(public_path($product->image))) {
                File::delete(public_path($product->image));
            }
            $data['image'] = $this->saveImage($req);
        } elseif (! empty(trim((string) $req->input('image')))) {
            $data['image'] = trim((string) $req->input('image'));
        }

        $product->update($data);

        return apiResponse($this->productResponse($product->load(['category', 'user', 'variants'])), 200, 'Product updated successfully.');
    }

    // DELETE /api/products/{id}  (admin)
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        if ($product->image && File::exists(public_path($product->image))) {
            File::delete(public_path($product->image));
        }

        $product->delete();

        return apiResponse(null, 200, 'Product deleted successfully.');
    }

    private function productResponse(Product $product): array
    {
        return [
            'id' => $product->id,
            'category_id' => $product->category_id,
            'user_id' => $product->user_id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $product->price,
            'stock' => $product->stock,
            'image' => $product->image
                ? ((str_starts_with($product->image, 'http://') || str_starts_with($product->image, 'https://'))
                    ? $product->image
                    : asset($product->image))
                : null,
            'category' => $product->relationLoaded('category') ? $product->category : null,
            'user' => $product->relationLoaded('user') ? $product->user : null,
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
            'variants' => $product->relationLoaded('variants') ? $product->variants : null,
        ];
    }

    private function saveImage(Request $req): string
    {
        $file = $req->file('image');
        $filename = time().'-'.$file->getClientOriginalName();
        $file->move(public_path('image'), $filename);

        return 'image/'.$filename;
    }
}
