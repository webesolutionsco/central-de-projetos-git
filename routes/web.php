<?php

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\GitHubCredentialController;
use App\Http\Controllers\LaravelCloudController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('central');
});

Route::prefix('api')->group(function (): void {
    Route::get('/github-credential', [GitHubCredentialController::class, 'show']);
    Route::put('/github-credential', [GitHubCredentialController::class, 'store']);
    Route::delete('/github-credential', [GitHubCredentialController::class, 'destroy']);
    Route::get('/laravel-cloud-credential', [LaravelCloudController::class, 'credential']);
    Route::put('/laravel-cloud-credential', [LaravelCloudController::class, 'storeCredential']);
    Route::post('/projects/{project}/laravel-cloud/verify', [LaravelCloudController::class, 'verify']);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::post('/projects/{project}/initialize', [ProjectController::class, 'initialize']);
    Route::post('/projects/{project}/identity', [ProjectController::class, 'configureIdentity']);
    Route::post('/projects/{project}/remote', [ProjectController::class, 'connectRemote']);
    Route::post('/projects/{project}/github-repository', [ProjectController::class, 'createGitHubRepository']);
    Route::post('/projects/{project}/integrate-remote-history', [ProjectController::class, 'integrateRemoteHistory']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::post('/projects/{project}/stage', [ProjectController::class, 'stage']);
    Route::post('/projects/{project}/commit', [ProjectController::class, 'commit']);
    Route::post('/projects/{project}/fetch', [ProjectController::class, 'fetch']);
    Route::post('/projects/{project}/push', [ProjectController::class, 'push']);
});
