<?php

return [
    /* Apenas projetos explicitamente autorizados são inspecionados nesta fase. */
    'projects_root' => env('CENTRAL_PROJECTS_ROOT', 'C:\\xampp\\htdocs'),
    'projects_registry_path' => storage_path('app/private/authorized-projects.json'),
    // A interface pode guardar token e executar Git: por padrão, só responde neste computador.
    'local_only' => env('CENTRAL_LOCAL_ONLY', true),
    // O Apache/XAMPP pode não herdar o PATH do Windows; prefira o binário explícito.
    'git_binary' => env('CENTRAL_GIT_BINARY', 'git'),
    'cloud_cli' => base_path('vendor/bin/cloud'),
    'cloud_environments' => [
        'webe' => ['application' => 'webesolutions', 'environment' => 'homologacao', 'branch' => 'homologacao'],
    ],
    'projects' => [
        'webe' => ['directory' => 'webe', 'label' => 'WEBE'],
        'webesolutions' => ['directory' => 'webesolutions', 'label' => 'WEBE Solutions'],
        'central' => ['directory' => basename(base_path()), 'label' => 'Central de Projetos Git'],
    ],
];
