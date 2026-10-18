<?php

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\GitHubCredentialController;
use App\Http\Controllers\LaravelCloudController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()
        ->view('central')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache');
});

Route::prefix('api')->group(function (): void {
    Route::get('/projects/{project}/github-credential', [GitHubCredentialController::class, 'show']);
    Route::put('/projects/{project}/github-credential', [GitHubCredentialController::class, 'store']);
    Route::delete('/projects/{project}/github-credential', [GitHubCredentialController::class, 'destroy']);
    Route::get('/laravel-cloud-credential', [LaravelCloudController::class, 'credential']);
    Route::put('/laravel-cloud-credential', [LaravelCloudController::class, 'storeCredential']);
    Route::delete('/laravel-cloud-credential', [LaravelCloudController::class, 'destroyCredential']);
    Route::post('/projects/{project}/laravel-cloud/verify', [LaravelCloudController::class, 'verify']);
    Route::post('/projects/{project}/laravel-cloud/deploy-homologation', [LaravelCloudController::class, 'deployHomologation']);
    Route::post('/projects/{project}/laravel-cloud/migration-status', [LaravelCloudController::class, 'migrationStatus']);
    Route::post('/projects/{project}/laravel-cloud/deployment-status', [LaravelCloudController::class, 'deploymentStatus']);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::post('/projects/{project}/initialize', [ProjectController::class, 'initialize']);
    Route::post('/projects/{project}/identity', [ProjectController::class, 'configureIdentity']);
    Route::post('/projects/{project}/remote', [ProjectController::class, 'connectRemote']);
    Route::post('/projects/{project}/github-repository', [ProjectController::class, 'createGitHubRepository']);
    Route::post('/projects/{project}/integrate-remote-history', [ProjectController::class, 'integrateRemoteHistory']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::get('/projects/{project}/remote-branches', [ProjectController::class, 'remoteBranches']);
    Route::post('/projects/{project}/compare-remote-branches', [ProjectController::class, 'compareRemoteBranches']);
    Route::post('/projects/{project}/commit-diff', [ProjectController::class, 'commitDiff']);
    Route::post('/projects/{project}/run-commit-checks', [ProjectController::class, 'runCommitChecks']);
    Route::get('/projects/{project}/commit-checks/latest', [ProjectController::class, 'latestCommitCheck']);
    Route::get('/projects/{project}/commit-checks/{job}', [ProjectController::class, 'commitCheckStatus']);
    Route::post('/projects/{project}/commit-checks/{job}/cancel', [ProjectController::class, 'cancelCommitCheck']);
    Route::post('/projects/{project}/stage', [ProjectController::class, 'stage']);
    Route::post('/projects/{project}/commit', [ProjectController::class, 'commit']);
    Route::post('/projects/{project}/fetch', [ProjectController::class, 'fetch']);
    Route::post('/projects/{project}/push', [ProjectController::class, 'push']);
});
