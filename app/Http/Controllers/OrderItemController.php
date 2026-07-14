<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderItemController extends Controller
{
    public function index(Request $req)
    {
        $orderItems = OrderItem::with(['order', 'product'])
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->get();

        return apiResponse($orderItems, 200, 'Get order items successfully...');
    }

    public function show(Request $req, $id)
    {
        $orderItem = OrderItem::with(['order', 'product'])
            ->where('id', $id)
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->firstOrFail();

        return apiResponse($orderItem, 200, 'Get order item successfully...');
    }

    public function store(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'order_id' => 'required|exists:orders,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        $req->user()->orders()->where('id', $data['order_id'])->firstOrFail();

        $orderItem = OrderItem::create($data);

        return apiResponse($orderItem->load(['order', 'product']), 201, 'Add order item successfully...');
    }

    public function update(Request $req, $id)
    {
        $orderItem = OrderItem::where('id', $id)
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->firstOrFail();

        $validator = Validator::make($req->all(), [
            'order_id' => 'sometimes|required|exists:orders,id',
            'product_id' => 'sometimes|required|exists:products,id',
            'quantity' => 'sometimes|required|integer|min:1',
            'price' => 'sometimes|required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        if (array_key_exists('order_id', $data)) {
            $req->user()->orders()->where('id', $data['order_id'])->firstOrFail();
        }

        $orderItem->update($data);

        return apiResponse($orderItem->load(['order', 'product']), 200, 'Update order item successfully...');
    }

    public function destroy(Request $req, $id)
    {
        OrderItem::where('id', $id)
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->firstOrFail()
            ->delete();

        return apiResponse(null, 200, 'Delete order item successfully...');
    }
}
