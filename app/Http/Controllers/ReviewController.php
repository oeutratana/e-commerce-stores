<?php

namespace App\Http\Controllers;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    // GET /api/reviews  (public)
    public function index()
    {
        return apiResponse(Review::with(['user', 'product'])->get(), 200, 'Get reviews successfully.');
    }

    // GET /api/reviews/{id}  (public)
    public function show($id)
    {
        return apiResponse(Review::with(['user', 'product'])->findOrFail($id), 200, 'Get review successfully.');
    }

    // POST /api/reviews  (auth)
    public function store(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'product_id' => 'required|exists:products,id',
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $review = Review::create([
            'user_id'    => $req->user()->id,
            'product_id' => $req->product_id,
            'rating'     => $req->rating,
            'comment'    => $req->comment,
        ]);

        return apiResponse($review->load(['user', 'product']), 201, 'Review added successfully.');
    }

    // PUT /api/reviews/{id}  (auth, own review)
    public function update(Request $req, $id)
    {
        $review = Review::where('id', $id)->where('user_id', $req->user()->id)->firstOrFail();

        $validator = Validator::make($req->all(), [
            'rating'  => 'sometimes|required|integer|min:1|max:5',
            'comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $review->update($validator->validated());
        return apiResponse($review->load(['user', 'product']), 200, 'Review updated successfully.');
    }

    // DELETE /api/reviews/{id}  (auth, own review)
    public function destroy(Request $req, $id)
    {
        $review = Review::where('id', $id)->where('user_id', $req->user()->id)->firstOrFail();
        $review->delete();
        return apiResponse(null, 200, 'Review deleted successfully.');
    }
}