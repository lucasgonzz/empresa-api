<?php

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

/*
|--------------------------------------------------------------------------
| Precarga del logger (PHP 8.4)
|--------------------------------------------------------------------------
|
| Con PHP 8.4, `Illuminate\Log\Logger` y `Illuminate\Events\Dispatcher` emiten una
| deprecación al compilarse (parámetro implícitamente nullable). Si se compilan por
| primera vez DENTRO del handler de errores de Laravel, la deprecación lo reentra y
| muere con `Class "Illuminate\Log\Logger" not found`: el login devuelve 500
| (Ferremas, Candyguay y Arfren, 23-24/10/2026). Cargarlas acá, antes de que el kernel
| registre el handler, lo evita aunque el `vendor/` se reemplace con uno sin parchear.
|
*/

class_exists(\Illuminate\Log\Logger::class);
class_exists(\Illuminate\Events\Dispatcher::class);

$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
