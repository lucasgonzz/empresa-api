<?php

namespace Tests\Feature\ElLocal\Concerns;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/**
 * Deja la instancia configurada para «El local» con valores del test: nunca el ADMIN_API_URL del `.env.testing`.
 */
trait ConfiguraElLocal
{
    /**
     * @param  int $dueno  USER_ID de la instancia.
     * @return void
     */
    protected function configurar_el_local($dueno = 500)
    {
        config([
            'services.admin_api.url'         => 'https://admin.test/public',
            'services.admin_api.inbound_key' => self::CLAVE,
            'app.USER_ID'                    => $dueno,
        ]);
    }

    /**
     * Junta lo que se loguea de acá en adelante.
     *
     * @return Collection
     */
    protected function capturar_logs()
    {
        $logs = new Collection();

        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($logs) {
            $logs->push($evento->level . ': ' . $evento->message);
        });

        return $logs;
    }
}
