<?php

namespace App\Http\Controllers;

use App\Services\LaravelCloudCredentialStore;
use App\Services\LaravelCloudReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class LaravelCloudController extends Controller
{
    public function credential(LaravelCloudCredentialStore $credentials): JsonResponse
    {
        return response()->json($credentials->status());
    }

    public function storeCredential(Request $request, LaravelCloudCredentialStore $credentials): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:500']]);
        try {
            $credentials->save($data['token']);
            return response()->json(['configured' => true, 'message' => 'Token do Laravel Cloud salvo de forma criptografada.']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function verify(LaravelCloudReader $cloud, string $project): JsonResponse
    {
        $environment = config('central.cloud_environments.'.$project);
        if (! is_array($environment)) {
            return response()->json(['message' => 'Este projeto ainda não possui ambiente Laravel Cloud configurado.'], 422);
        }

        try {
            return response()->json(['environment' => $cloud->verify(
                (string) $environment['application'],
                (string) $environment['environment'],
                (string) $environment['branch'],
            )]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
