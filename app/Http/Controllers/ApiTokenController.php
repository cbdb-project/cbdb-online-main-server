<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ApiTokenController extends Controller {
    /**
     * 顯示使用者的 API tokens
     */
    public function index(Request $request) {
        return response()->json(
            $request->user()->tokens->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ];
            })
        );
    }

    /**
     * 創建新的 API token
     */
    public function store(Request $request) {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array'],
            'expires_in' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ]);

        $abilities = $request->input('abilities', ['*']);
        $expiresAt = $request->has('expires_in') && $request->input('expires_in')
            ? now()->addDays($request->input('expires_in'))
            : null;

        $token = $request->user()->createToken(
            $request->input('name'),
            $abilities,
            $expiresAt
        );

        // If this is a JSON request, return JSON response
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Token 創建成功',
                'token' => [
                    'id' => $token->accessToken->id,
                    'name' => $token->accessToken->name,
                    'abilities' => $token->accessToken->abilities,
                    'created_at' => $token->accessToken->created_at->toIso8601String(),
                    'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                    // 只在創建時返回完整 token（之後無法再次查看）
                    'plainTextToken' => $token->plainTextToken,
                ],
            ]);
        }

        // Otherwise, redirect back with the token in session flash
        return redirect()->route('profile.edit')
            ->with('token', $token->plainTextToken)
            ->with('success', 'API Token 創建成功，請妥善保存');
    }

    /**
     * 撤銷 API token
     */
    public function destroy(Request $request, $tokenId) {
        $token = $request->user()->tokens()->findOrFail($tokenId);
        $token->delete();

        return response()->json([
            'message' => 'Token 已撤銷',
        ]);
    }

    /**
     * 撤銷所有 tokens
     */
    public function destroyAll(Request $request) {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => '所有 Tokens 已撤銷',
        ]);
    }
}
