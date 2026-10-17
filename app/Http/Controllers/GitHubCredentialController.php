<?php

namespace App\Http\Controllers;

use App\Services\GitHubCredentialStore;
use App\Services\LocalProjectReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GitHubCredentialController extends Controller
{
    public function show(string $project, LocalProjectReader $reader, GitHubCredentialStore $credentials): JsonResponse
    {
        try {
            return response()->json($credentials->statusForOrigin($this->origin($project, $reader)));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function store(Request $request, string $project, LocalProjectReader $reader, GitHubCredentialStore $credentials): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:500']]);

        try {
            $origin = $this->origin($project, $reader);
            $credentials->saveForOrigin($origin, $data['token']);

            return response()->json([
                'configured' => true,
                'scope' => $credentials->statusForOrigin($origin)['scope'],
                'message' => 'Token salvo de forma criptografada apenas para este repositório.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(string $project, LocalProjectReader $reader, GitHubCredentialStore $credentials): JsonResponse
    {
        try {
            $credentials->removeForOrigin($this->origin($project, $reader));

            return response()->json(['configured' => false, 'message' => 'Token específico deste repositório removido.']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    private function origin(string $project, LocalProjectReader $reader): string
    {
        $origin = $reader->details($project)['origin'] ?? null;
        if (! is_string($origin) || $origin === '') {
            throw new RuntimeException('Este projeto ainda não possui um repositório GitHub conectado.');
        }

        return $origin;
    }
}
