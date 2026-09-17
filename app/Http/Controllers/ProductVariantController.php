<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductVariantController extends Controller
{
    // GET /api/product-variants?product_id={id}  (public)
    public function index(Request $req)
    {
        $query = ProductVariant::with('product');

        if ($req->filled('product_id')) {
            $query->where('product_id', $req->product_id);
        }

        return apiResponse($query->get(), 200, 'Get product variants successfully.');
    }

    // GET /api/product-variants/{id}  (public)
    public function show($id)
    {
        $variant = ProductVariant::with('product')->findOrFail($id);

        return apiResponse($variant, 200, 'Get product variant successfully.');
    }

    // POST /api/product-variants  (admin)
    public function store(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'product_id' => 'required|exists:products,id',
            'size' => 'required|in:'.implode(',', ProductVariant::SIZES),
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        if (ProductVariant::where('product_id', $data['product_id'])
            ->where('size', $data['size'])
            ->exists()) {
            return apiResponse(null, 409, 'This size already exists for the product.');
        }

        $variant = ProductVariant::create($data);

        return apiResponse($variant->load('product'), 201, 'Product variant created successfully.');
    }

    // PUT /api/product-variants/{id}  (admin)
    public function update(Request $req, $id)
    {
        $variant = ProductVariant::findOrFail($id);

        $validator = Validator::make($req->all(), [
            'size' => 'sometimes|required|in:'.implode(',', ProductVariant::SIZES),
            'price' => 'sometimes|required|numeric|min:0',
            'stock' => 'sometimes|required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        if (isset($data['size']) && $data['size'] !== $variant->size
            && ProductVariant::where('product_id', $variant->product_id)
                ->where('size', $data['size'])
                ->exists()) {
            return apiResponse(null, 409, 'This size already exists for the product.');
        }

        $variant->update($data);

        return apiResponse($variant->load('product'), 200, 'Product variant updated successfully.');
    }

    // DELETE /api/product-variants/{id}  (admin)
    public function destroy($id)
    {
        $variant = ProductVariant::findOrFail($id);
        $variant->delete();

        return apiResponse(null, 200, 'Product variant deleted successfully.');
    }
}
