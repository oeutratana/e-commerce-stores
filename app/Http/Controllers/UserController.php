<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function register(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',

        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $data = $validator->validated();
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        $user->token = $user->createToken('authToken')->plainTextToken;

        return apiResponse($user, 201, 'Register successfully.');
    }

    public function login(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $credentials = $validator->validated();

        if (! Auth::attempt($credentials)) {
            return apiResponse(null, 401, 'Invalid email or password.');
        }

        $user = Auth::user();
        $user->token = $user->createToken('authToken')->plainTextToken;

        return apiResponse($user, 200, 'Login successfully.');
    }

    public function profile(Request $req)
    {
        return apiResponse($req->user(), 200, 'Get profile successfully.');
    }

    public function updateProfile(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,'.$req->user()->id,
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return apiResponse($validator->errors(), 422, 'Validation failed.');
        }

        $req->user()->update($validator->validated());

        return apiResponse($req->user()->fresh(), 200, 'Profile updated successfully.');
    }

    public function getUser()
    {
        $data = User::all();

        return apiResponse($data, 200, 'Get data successfully...');
    }

    public function getUserById($id)
    {
        $data = User::findOrFail($id);

        return apiResponse($data, 200, 'Get data successfully...!');
    }
}
