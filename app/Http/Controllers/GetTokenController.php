<?php

namespace App\Http\Controllers;

use App\Http\Resources\TokenResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class GetTokenController extends Controller
{
    /**
     * POST /api/token
     *
     * Exchange the API key for a Sanctum Bearer token.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $provided = $request->input('api_key') ?? $request->header('X-Api-Key');

        Log::info('coupon.http.get_token.attempt', [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if (! $provided || ! hash_equals((string) config('app.api_key'), (string) $provided)) {
            Log::warning('coupon.http.get_token.rejected', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid API key.'], Response::HTTP_UNAUTHORIZED);
        }

        $user = User::where('email', config('app.api_user_email', 'test@example.com'))->firstOrFail();

        $token = $user->createToken('api')->plainTextToken;

        Log::info('coupon.http.get_token.issued', ['user_id' => $user->id]);

        return (new TokenResource($token, $user->name, $user->email))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
