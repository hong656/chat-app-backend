<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::all());
    }

    public function register(Request $request){
        Log::info('Register Request Data:', $request->all());

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'User registered successfully'
        ], 201);

    }

    public function login(Request $request) {
        Log::info('Login Request Data:', $request->all());
    
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required',
        ]);
    
        $user = User::where('email', $request->email)->first();
    
        if (!empty($user)) {
            if (Hash::check($request->password, $user->password)) {

                $token = $user->createToken('Mytoken')->plainTextToken;
    
                return response()->json([
                    'status' => true,
                    'message' => 'User logged in successfully',
                    'token' => $token,
                    'user' => [
                        'name' => $user->name,
                        'email' => $user->email
                    ]
                ], 200);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid Password'
                ], 401);
            }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Email'
            ], 401);
        }
    }


    public function getUserById($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $user
        ], 200);
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Validate incoming data
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|nullable|string|min:8|confirmed',
        ]);

        // Update fields if provided
        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }

        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }

        if (array_key_exists('password', $validated) && !empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'User updated successfully',
            'data' => $user
        ]);
    }

    public function profile(){
        $userdata = auth()->user();
        
        return response()->json([
            'status' => true,
            'message' => 'User profile',
            'data' => $userdata
        ], 200);
    }

    public function logout(){
        auth()->user()->tokens()->delete();

        return response()->json([
            'status' => true,
            'message' => 'User logged out successfully'
        ], 200);
    }
}
