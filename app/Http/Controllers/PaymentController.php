<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    public function index(Request $req)
    {
        $payments = Payment::with('order')
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->get();

        return apiResponse($payments, 200, 'Get payments successfully...');
    }

    public function show(Request $req, $id)
    {
        $payment = Payment::with('order')
            ->where('id', $id)
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->firstOrFail();

        return apiResponse($payment, 200, 'Get payment successfully...');
    }

    public function store(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|string',
            'payment_status' => 'nullable|string',
            'transaction_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        $req->user()->orders()->where('id', $data['order_id'])->firstOrFail();

        $payment = Payment::create($data);

        return apiResponse($payment->load('order'), 201, 'Add payment successfully...');
    }

    public function update(Request $req, $id)
    {
        $payment = Payment::where('id', $id)
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->firstOrFail();

        $validator = Validator::make($req->all(), [
            'order_id' => 'sometimes|required|exists:orders,id',
            'payment_method' => 'sometimes|required|string',
            'payment_status' => 'sometimes|required|string',
            'transaction_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        if (array_key_exists('order_id', $data)) {
            $req->user()->orders()->where('id', $data['order_id'])->firstOrFail();
        }

        $payment->update($data);

        return apiResponse($payment->load('order'), 200, 'Update payment successfully...');
    }

    public function destroy(Request $req, $id)
    {
        Payment::where('id', $id)
            ->whereHas('order', function ($query) use ($req) {
                $query->where('user_id', $req->user()->id);
            })
            ->firstOrFail()
            ->delete();

        return apiResponse(null, 200, 'Delete payment successfully...');
    }
}
