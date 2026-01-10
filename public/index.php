<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return fn(array $context) => new Kernel(
    is_string($context['APP_ENV']) ? $context['APP_ENV'] : 'prod',
    (bool) $context['APP_DEBUG']
);
