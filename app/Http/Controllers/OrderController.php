<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    // GET /api/orders
    public function index(Request $req)
    {
        $orders = Order::with(['user', 'items.product', 'payment'])
            ->where('user_id', $req->user()->id)
            ->get();
        return apiResponse($orders, 200, 'Get orders successfully.');
    }

    // GET /api/orders/{id}
    public function show(Request $req, $id)
    {
        $order = Order::with(['user', 'items.product', 'payment'])
            ->where('id', $id)
            ->where('user_id', $req->user()->id)
            ->firstOrFail();
        return apiResponse($order, 200, 'Get order successfully.');
    }

    // POST /api/orders
    public function store(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'total_price' => 'required|numeric|min:0',
            'status'      => 'nullable|string|in:pending,processing,shipped,delivered,cancelled',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $order = Order::create([
            'user_id'     => $req->user()->id,
            'total_price' => $req->total_price,
            'status'      => $req->status ?? 'pending',
        ]);

        return apiResponse($order->load(['items.product', 'payment']), 201, 'Order created successfully.');
    }

    // PUT /api/orders/{id}
    public function update(Request $req, $id)
    {
        $order = Order::where('id', $id)->where('user_id', $req->user()->id)->firstOrFail();

        $validator = Validator::make($req->all(), [
            'total_price' => 'sometimes|required|numeric|min:0',
            'status'      => 'sometimes|required|string|in:pending,processing,shipped,delivered,cancelled',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $order->update($validator->validated());
        return apiResponse($order->load(['items.product', 'payment']), 200, 'Order updated successfully.');
    }

    // DELETE /api/orders/{id}
    public function destroy(Request $req, $id)
    {
        $order = Order::where('id', $id)->where('user_id', $req->user()->id)->firstOrFail();
        $order->delete();
        return apiResponse(null, 200, 'Order deleted successfully.');
    }
}