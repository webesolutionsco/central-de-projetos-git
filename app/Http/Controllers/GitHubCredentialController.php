<?php

namespace App\Http\Controllers;

use App\Services\GitHubCredentialStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GitHubCredentialController extends Controller
{
    public function show(GitHubCredentialStore $credentials): JsonResponse
    {
        return response()->json($credentials->status());
    }

    public function store(Request $request, GitHubCredentialStore $credentials): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:500']]);
        try {
            $credentials->save($data['token']);
            return response()->json(['configured' => true, 'message' => 'Token do GitHub salvo de forma criptografada.']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(GitHubCredentialStore $credentials): JsonResponse
    {
        try {
            $credentials->remove();
            return response()->json(['configured' => false, 'message' => 'Token do GitHub removido.']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
