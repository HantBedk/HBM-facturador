<?php

// PHP-FPM no suele heredar env de docker-compose; ver Dockerfile (env[VIEW_COMPILED_PATH]) y AppServiceProvider.
$compiled = env('VIEW_COMPILED_PATH');
if ($compiled === null || $compiled === '') {
    $compiled = file_exists('/.dockerenv')
        ? '/tmp/laravel-views'
        : storage_path('framework/views');
}

return [
    'paths' => [
        resource_path('views'),
    ],
    'compiled' => $compiled,
];
