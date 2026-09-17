<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::all()->map(function ($category) {
            return $this->categoryResponse($category);
        });

        return apiResponse($categories, 200, 'Get categories successfully...');
    }

    public function show($id)
    {
        $category = Category::findOrFail($id);

        return apiResponse($this->categoryResponse($category), 200, 'Get category successfully...');
    }

    public function store(Request $req)
    {
        $this->logIncomingPayload('store', $req);

        $this->normalizeInput($req);

        $validator = Validator::make($req->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => $req->hasFile('image') ? 'image|max:2048' : 'nullable|url|max:2048',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();
        $data['description'] = $this->resolveDescription($req);

        if ($req->hasFile('image')) {
            $data['image'] = $this->saveImage($req);
        } elseif (! empty(trim((string) $req->input('image')))) {
            $data['image'] = trim((string) $req->input('image'));
        }

        $category = Category::create($data);

        return apiResponse($this->categoryResponse($category), 201, 'Add category successfully...');
    }

    public function update(Request $req, $id)
    {
        $category = Category::findOrFail($id);

        $this->logIncomingPayload('update', $req);

        $this->normalizeInput($req);

        $validator = Validator::make($req->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'image' => $req->hasFile('image') ? 'image|max:2048' : 'nullable|url|max:2048',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();

        if ($req->has('description') || $req->has('descriptin')) {
            $data['description'] = $this->resolveDescription($req);
        }

        if ($req->hasFile('image')) {
            // Delete old physical image file before saving new one
            if ($category->image && File::exists(public_path($category->image))) {
                File::delete(public_path($category->image));
            }
            $data['image'] = $this->saveImage($req);
        } elseif (! empty(trim((string) $req->input('image')))) {
            $data['image'] = trim((string) $req->input('image'));
        }

        $category->update($data);

        return apiResponse($this->categoryResponse($category), 200, 'Update category successfully...');
    }

    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        // Remove the linked image file from public storage directory
        if ($category->image && File::exists(public_path($category->image))) {
            File::delete(public_path($category->image));
        }

        $category->delete();

        return apiResponse(null, 200, 'Delete category successfully...');
    }

    /**
     * Save the uploaded image with a safe, collision-free filename
     * and return the relative path stored on the model.
     */
    private function saveImage(Request $req): string
    {
        $file = $req->file('image');
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $filename = time().'-'.Str::random(8).'.'.$extension;

        $destination = public_path('image');
        if (! File::exists($destination)) {
            File::makeDirectory($destination, 0755, true);
        }

        $file->move($destination, $filename);

        return 'image/'.$filename;
    }

    /**
     * Pull the value directly out of every layer Laravel exposes
     * request data through, in priority order. This is a defensive
     * fallback for cases where validated()/all() unexpectedly doesn't
     * carry a field through.
     */
    private function resolveDescription(Request $req)
    {
        $candidates = [
            $req->post('description'),
            $req->input('description'),
            $req->request->get('description'),
            $req->input('descriptin'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * TEMPORARY diagnostic logging - remove once resolved. Writes to
     * storage/logs/laravel.log so we can see exactly what Laravel
     * receives for a failing request.
     */
    private function logIncomingPayload($action, Request $req)
    {
        Log::info('CategoryController@'.$action.' incoming payload', [
            'all' => $req->all(),
            'post_bag' => $req->request->all(),
            'has_desc' => $req->has('description'),
            'input_desc' => $req->input('description'),
            'content_type' => $req->header('Content-Type'),
        ]);
    }

    /**
     * Normalize incoming request keys so that stray whitespace or
     * inconsistent casing (e.g. "Description ", "DESCRIPTION") still
     * map correctly onto the fields the validator expects.
     *
     * This replaces the previous "check a list of possible typo keys"
     * approach, which only covered one hardcoded typo and silently
     * failed for anything else (including plain whitespace issues).
     */
    private function normalizeInput(Request $req): void
    {
        $all = $req->all();
        $normalized = [];

        foreach ($all as $key => $value) {
            $cleanKey = strtolower(trim($key));
            $normalized[$cleanKey] = is_string($value) ? trim($value) : $value;
        }

        // Only merge back the fields we actually care about validating,
        // so we don't clobber file inputs or unrelated keys.
        $fieldsToNormalize = ['name', 'description'];
        $merge = [];

        foreach ($fieldsToNormalize as $field) {
            if (array_key_exists($field, $normalized)) {
                $merge[$field] = $normalized[$field];
            }
        }

        if (! empty($merge)) {
            $req->merge($merge);
        }
    }

    private function categoryResponse(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'image' => $category->image
                ? ((str_starts_with($category->image, 'http://') || str_starts_with($category->image, 'https://'))
                    ? $category->image
                    : asset($category->image))
                : null,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
        ];
    }
}
