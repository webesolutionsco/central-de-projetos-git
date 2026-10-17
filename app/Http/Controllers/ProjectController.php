<?php

namespace App\Http\Controllers;

use App\Services\LocalProjectReader;
use App\Services\ProjectRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class ProjectController extends Controller
{
    public function index(ProjectRegistry $projects): JsonResponse
    {
        return response()->json([
            'projects' => collect($projects->all())
                ->map(fn (array $project, string $id): array => [
                    'id' => $id,
                    'label' => $project['label'],
                    'source' => $project['source'],
                ])
                ->values()
                ->all(),
            'available_directories' => $projects->availableDirectories(),
            'projects_root' => config('central.projects_root'),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request, ProjectRegistry $projects): JsonResponse
    {
        $data = $request->validate([
            'directory' => ['required', 'string', 'max:255'],
            'label' => ['required', 'string', 'max:80'],
        ]);

        try {
            return response()->json(['project' => $projects->add($data['directory'], $data['label'])], 201);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function show(Request $request, LocalProjectReader $reader, string $project): JsonResponse
    {
        try {
            return response()->json(['project' => $reader->details($project), 'checked_at' => now()->toIso8601String()]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
    }

    public function stage(Request $request, LocalProjectReader $reader, string $project): JsonResponse
    {
        $data = $request->validate(['files' => ['required', 'array', 'min:1'], 'files.*' => ['string', 'max:1024']]);
        try {
            return response()->json($reader->stage($project, $data['files']));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }
    }

    public function initialize(LocalProjectReader $reader, string $project): JsonResponse
    {
        try {
            return response()->json(['project' => $reader->initialize($project)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function configureIdentity(Request $request, LocalProjectReader $reader, string $project): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254']]);
        try {
            return response()->json($reader->configureIdentity($project, $data['name'], $data['email']));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function connectRemote(Request $request, LocalProjectReader $reader, string $project): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:300']]);
        try {
            return response()->json($reader->connectRemote($project, $data['url']));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function createGitHubRepository(Request $request, LocalProjectReader $reader, string $project): JsonResponse
    {
        $data = $request->validate([
            'owner' => ['nullable', 'string', 'max:39'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:350'],
            'private' => ['required', 'boolean'],
        ]);
        try {
            return response()->json($reader->createGitHubRepository(
                $project,
                $data['owner'] ?? '',
                $data['name'],
                $data['description'],
                $data['private'],
            ), 201);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function integrateRemoteHistory(LocalProjectReader $reader, string $project): JsonResponse
    {
        try {
            return response()->json($reader->integrateRemoteHistory($project));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function commit(Request $request, LocalProjectReader $reader, string $project): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:500']]);
        try {
            return response()->json(['commit' => $reader->commit($project, $data['message'])]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function fetch(LocalProjectReader $reader, string $project): JsonResponse
    {
        try {
            return response()->json(['project' => $reader->fetch($project)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function push(LocalProjectReader $reader, string $project): JsonResponse
    {
        try {
            return response()->json($reader->push($project));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
