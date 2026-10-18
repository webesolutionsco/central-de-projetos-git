<?php

return [
    /* Apenas projetos explicitamente autorizados são inspecionados nesta fase. */
    'projects_root' => env('CENTRAL_PROJECTS_ROOT', 'C:\\xampp\\htdocs'),
    'projects_registry_path' => storage_path('app/private/authorized-projects.json'),
    // A interface pode guardar token e executar Git: por padrão, só responde neste computador.
    'local_only' => env('CENTRAL_LOCAL_ONLY', true),
    // O Apache/XAMPP pode não herdar o PATH do Windows; prefira o binário explícito.
    'git_binary' => env('CENTRAL_GIT_BINARY', 'git'),
    // Os testes devem usar o PHP de linha de comando, nunca o executável do Apache que atende esta página.
    'php_binary' => env('CENTRAL_PHP_BINARY', 'C:\\xampp\\php\\php.exe'),
    'node_binary' => env('CENTRAL_NODE_BINARY', 'C:\\Program Files\\nodejs\\node.exe'),
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
