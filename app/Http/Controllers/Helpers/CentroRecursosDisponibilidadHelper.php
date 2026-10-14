<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ¿La página del centro de recursos ya responde? (misión centro-de-recursos-clientes, contrato C4).
 *
 * La página publica un archivo estático, `recursos-estado.json`, que dice `{"ok": true}`. Antes de
 * emitir un pase, esta API lo consulta. Es lo que hace compatible el orden de despliegue: si este
 * sistema sale con el botón antes de que la página exista, el botón avisa "va a estar disponible en
 * breve" en vez de abrir un dominio muerto.
 *
 * - Timeout de 3 segundos: el usuario está esperando con una pestaña en blanco abierta.
 * - Cache de 10 minutos si anda y de 2 si no anda: con la página caída nadie espera 3 segundos en
 *   cada clic, y cuando vuelve se nota rápido.
 * - Cualquier cosa que no sea un 2xx con `ok === true` cuenta como "no anda": un dominio sin DNS,
 *   un 500, una página de estacionamiento que devuelve HTML con 200.
 *
 * IMPORTANTE (PHP 7.4): nada de match, str_contains, nullsafe ni argumentos nombrados.
 */
class CentroRecursosDisponibilidadHelper
{
    /**
     * Segundos de espera de la consulta.
     *
     * @var int
     */
    const TIMEOUT_SEGUNDOS = 3;

    /**
     * Segundos que se recuerda que la página anda.
     *
     * @var int
     */
    const CACHE_SI_ANDA = 600;

    /**
     * Segundos que se recuerda que la página NO anda.
     *
     * @var int
     */
    const CACHE_SI_NO_ANDA = 120;

    /**
     * Default de la URL de la página, por si la config quedara vacía (un `CENTRO_RECURSOS_URL=` en
     * blanco en algún `.env`). El de verdad está escrito en `config/services.php`.
     *
     * @var string
     */
    const URL_POR_DEFECTO = 'https://recursos.comerciocity.com';

    /**
     * URL base de la página, sin barra final.
     *
     * @return string
     */
    public static function url_de_la_pagina()
    {
        $url = trim((string) config('services.centro_recursos.url'));

        if ($url === '') {
            $url = self::URL_POR_DEFECTO;
        }

        return rtrim($url, '/');
    }

    /**
     * Dice si la página responde, consultándola como mucho una vez por período de cache.
     *
     * @return bool
     */
    public static function disponible()
    {
        $base = self::url_de_la_pagina();

        /** La clave lleva la URL: si cambia la config, no se arrastra el resultado de la otra. */
        $clave = 'centro_recursos:disponible:' . md5($base);

        $guardado = Cache::get($clave);

        if (!is_null($guardado)) {
            return (int) $guardado === 1;
        }

        $anda = self::consultar($base);

        Cache::put($clave, $anda ? 1 : 0, $anda ? self::CACHE_SI_ANDA : self::CACHE_SI_NO_ANDA);

        return $anda;
    }

    /**
     * La consulta en sí. Nunca tira: un error de red es "no anda".
     *
     * @param  string $base URL base de la página.
     * @return bool
     */
    protected static function consultar($base)
    {
        try {
            $respuesta = Http::timeout(self::TIMEOUT_SEGUNDOS)
                ->acceptJson()
                ->get($base . '/recursos-estado.json');

            if (!$respuesta->successful()) {
                Log::info('Centro de recursos: la página respondió ' . $respuesta->status() . ' al chequeo de estado.');
                return false;
            }

            $cuerpo = $respuesta->json();

            return is_array($cuerpo) && isset($cuerpo['ok']) && $cuerpo['ok'] === true;
        } catch (\Throwable $e) {
            Log::info('Centro de recursos: la página no responde al chequeo de estado: ' . $e->getMessage());
            return false;
        }
    }
}
