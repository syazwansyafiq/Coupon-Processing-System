<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\TokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     *
     * Validates credentials and returns a Sanctum personal access token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = Auth::user();
        $deviceName = $request->input('device_name', $request->userAgent() ?? 'api');

        $token = $user->createToken($deviceName)->plainTextToken;

        return (new TokenResource($token, $user->name, $user->email))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * DELETE /api/auth/logout
     *
     * Revokes the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Token revoked.',
        ]);
    }
}
