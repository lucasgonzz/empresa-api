<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\ActualizacionDelSistemaHelper;
use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PreventRequestsDuringMaintenance extends Middleware
{
    /**
     * The URIs that should be reachable while maintenance mode is enabled.
     *
     * Misión aviso-actualizacion-sistema (25/11/2026): el admin renueva el estado de actualización
     * (`PUT api/admin-sync/actualizacion-del-sistema`, y el pedido de OK) mientras el deploy tiene el
     * sistema en `php artisan down`, ya con el código nuevo y las migraciones corridas. Sólo ese
     * subárbol: el resto de `admin-sync` sigue cortado como siempre.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/admin-sync/actualizacion-del-sistema',
        'api/admin-sync/actualizacion-del-sistema/*',
    ];

    /**
     * Durante el `down` del deploy contesta la marca "estamos actualizando" (503 JSON con `Retry-After`) en
     * vez del 503 genérico `{"message":"Service Unavailable"}`, para que el SPA muestre el aviso en lugar de
     * creer que se cayó la conexión.
     *
     * El `down` del pipeline va sin `--render` ni `--secret`, así que la request llega hasta acá y el
     * framework tira un `HttpException` 503. Sólo se reemplaza ESE error: se envuelve `$next` para saber si
     * la excepción salió del mantenimiento o de más adentro de la aplicación (un `abort(503)` de otra
     * parte se deja pasar tal cual). Los `redirect`/`template` de un `down` con flags no se tocan.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     * @return mixed
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function handle($request, Closure $next)
    {
        $paso_a_la_aplicacion = false;

        try {
            return parent::handle($request, function ($solicitud) use ($next, &$paso_a_la_aplicacion) {
                $paso_a_la_aplicacion = true;

                return $next($solicitud);
            });
        } catch (HttpException $excepcion) {
            if ($paso_a_la_aplicacion || $excepcion->getStatusCode() !== 503) {
                throw $excepcion;
            }

            return ActualizacionDelSistemaHelper::respuesta_marca();
        }
    }
}
