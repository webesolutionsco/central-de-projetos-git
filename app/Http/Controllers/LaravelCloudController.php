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

    public function destroyCredential(LaravelCloudCredentialStore $credentials): JsonResponse
    {
        try {
            $credentials->remove();

            return response()->json(['configured' => false, 'message' => 'Token do Laravel Cloud removido.']);
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

    public function deployHomologation(Request $request, LaravelCloudReader $cloud, string $project): JsonResponse
    {
        $request->validate(['confirmed' => ['accepted']]);

        $environment = config('central.cloud_environments.'.$project);
        $projectConfiguration = config('central.projects.'.$project);
        if (! is_array($environment) || ! is_array($projectConfiguration)) {
            return response()->json(['message' => 'Este projeto ainda não possui ambiente Laravel Cloud configurado.'], 422);
        }

        $workingDirectory = rtrim((string) config('central.projects_root'), "\\/").DIRECTORY_SEPARATOR.$projectConfiguration['directory'];
        if (! is_dir($workingDirectory)) {
            return response()->json(['message' => 'A pasta autorizada deste projeto não está disponível para publicação.'], 422);
        }

        try {
            return response()->json(['publication' => $cloud->deployToHomologation(
                (string) $environment['application'],
                (string) $environment['environment'],
                (string) $environment['branch'],
                $workingDirectory,
            )]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function migrationStatus(Request $request, LaravelCloudReader $cloud, string $project): JsonResponse
    {
        $data = $request->validate(['command_id' => ['nullable', 'string', 'max:100']]);
        $environment = config('central.cloud_environments.'.$project);
        $projectConfiguration = config('central.projects.'.$project);
        if (! is_array($environment) || ! is_array($projectConfiguration)) {
            return response()->json(['message' => 'Este projeto ainda não possui ambiente Laravel Cloud configurado.'], 422);
        }

        $workingDirectory = rtrim((string) config('central.projects_root'), "\\/").DIRECTORY_SEPARATOR.$projectConfiguration['directory'];
        if (! is_dir($workingDirectory)) {
            return response()->json(['message' => 'A pasta autorizada deste projeto não está disponível para consulta.'], 422);
        }

        try {
            return response()->json(['migration_status' => $cloud->migrationStatus(
                (string) $environment['application'],
                (string) $environment['environment'],
                (string) $environment['branch'],
                $workingDirectory,
                $data['command_id'] ?? null,
            )]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function deploymentStatus(LaravelCloudReader $cloud, string $project): JsonResponse
    {
        $environment = config('central.cloud_environments.'.$project);
        $projectConfiguration = config('central.projects.'.$project);
        if (! is_array($environment) || ! is_array($projectConfiguration)) {
            return response()->json(['message' => 'Este projeto ainda não possui ambiente Laravel Cloud configurado.'], 422);
        }

        $workingDirectory = rtrim((string) config('central.projects_root'), "\\/").DIRECTORY_SEPARATOR.$projectConfiguration['directory'];
        if (! is_dir($workingDirectory)) {
            return response()->json(['message' => 'A pasta autorizada deste projeto não está disponível para consulta.'], 422);
        }

        try {
            return response()->json(['deployment' => $cloud->deploymentStatus(
                (string) $environment['application'],
                (string) $environment['environment'],
                (string) $environment['branch'],
                $workingDirectory,
            )]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
