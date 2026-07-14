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
        return apiResponse(Product::with(['category', 'user'])->get(), 200, 'Get products successfully.');
    }

    // GET /api/products/{id}  (public)
    public function show($id)
    {
        return apiResponse(Product::with(['category', 'user'])->findOrFail($id), 200, 'Get product successfully.');
    }

    // POST /api/products
    public function store(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'category_id' => 'required|exists:categories,id',
            'user_id'     => 'required|exists:users,id',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'stock'       => 'required|integer|min:0',
            'image'       => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        $existingProduct = Product::where('user_id', $data['user_id'])
            ->where('category_id', $data['category_id'])
            ->where('name', $data['name'])
            ->first();

        if ($existingProduct) {
            return apiResponse($existingProduct->load(['category', 'user']), 200, 'Product already exists.');
        }

        if ($req->hasFile('image')) {
            $data['image'] = $this->saveImage($req);
        }

        $product = Product::create($data);
        return apiResponse($product->load(['category', 'user']), 201, 'Product created successfully.');
    }

    // PUT /api/products/{id}  (admin)
    public function update(Request $req, $id)
    {
        $product = Product::findOrFail($id);

        $validator = Validator::make($req->all(), [
            'category_id' => 'sometimes|required|exists:categories,id',
            'name'        => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'sometimes|required|numeric|min:0',
            'stock'       => 'sometimes|required|integer|min:0',
            'image'       => 'nullable|image|max:2048',
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
        }

        $product->update($data);
        return apiResponse($product->load(['category', 'user']), 200, 'Product updated successfully.');
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

    private function saveImage(Request $req): string
    {
        $file     = $req->file('image');
        $filename = time() . '-' . $file->getClientOriginalName();
        $file->move(public_path('image'), $filename);
        return 'image/' . $filename;
    }
}
