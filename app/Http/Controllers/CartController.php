<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    public function index(Request $req)
    {
        $carts = Cart::with('product')->where('user_id', $req->user()->id)->get();

        return apiResponse($carts, 200, 'Get carts successfully.');
    }

    public function show(Request $req, $id)
    {
        $cart = Cart::with('product')
            ->where('id', $id)
            ->where('user_id', $req->user()->id)
            ->firstOrFail();

        return apiResponse($cart, 200, 'Get cart successfully.');
    }

    public function store(Request $req)
    {
        $this->normalizeProductIdField($req);

        $validator = Validator::make($req->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return apiResponse([
                'errors' => $validator->errors(),
                'available_products' => Product::select('id', 'name', 'stock')->get(),
            ], 422, 'Validation failed. Use one of the available product IDs.');
        }

        $data = $validator->validated();

        $existing = Cart::where('user_id', $req->user()->id)
            ->where('product_id', $data['product_id'])
            ->first();

        if ($existing) {
            $existing->increment('quantity', $data['quantity']);

            return apiResponse($existing->fresh('product'), 200, 'Cart quantity updated.');
        }

        $cart = Cart::create([
            'user_id' => $req->user()->id,
            'product_id' => $data['product_id'],
            'quantity' => $data['quantity'],
        ]);

        return apiResponse($cart->load('product'), 201, 'Added to cart successfully.');
    }

    public function update(Request $req, $id)
    {
        $cart = Cart::where('id', $id)->where('user_id', $req->user()->id)->firstOrFail();

        $validator = Validator::make($req->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $cart->update(['quantity' => $req->quantity]);

        return apiResponse($cart->load('product'), 200, 'Cart updated successfully.');
    }

    public function destroy(Request $req, $id)
    {
        $cart = Cart::where('id', $id)->where('user_id', $req->user()->id)->firstOrFail();
        $cart->delete();

        return apiResponse(null, 200, 'Removed from cart successfully.');
    }

    private function normalizeProductIdField(Request $req): void
    {
        foreach (['productId', 'product'] as $key) {
            if (! $req->filled('product_id') && $req->filled($key)) {
                $req->merge(['product_id' => $req->input($key)]);
            }
        }
    }
}
