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
 * SOPORTE (misión centro-recursos-soporte-y-tema, 14/10/2026). El mismo archivo puede traer además
 * `"soporte": true`: la página declara que sabe abrir una sesión de SOLO LECTURA. Es lo que decide si
 * un login maestro recibe un pase de soporte (`admite_soporte()`) o abre como invitado, como antes. Es
 * un campo opcional: una página vieja no lo trae y todo sigue andando igual.
 *
 * Una sola consulta HTTP llena las dos claves de cache (`disponible` y `soporte`): quien pregunta por
 * una y no la encuentra consulta la página y deja las dos al día.
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
     * Segundos que se recuerda que la página admite soporte. Cuando NO lo admite (página vieja) se
     * recuerda lo mismo que cuando no anda: así, apenas se publica la página nueva, el maestro lo nota
     * en 2 minutos y no en 10.
     *
     * @var int
     */
    const CACHE_SI_ADMITE_SOPORTE = 600;

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
        $clave = self::clave_de_disponibilidad($base);

        $guardado = Cache::get($clave);

        if (!is_null($guardado)) {
            return (int) $guardado === 1;
        }

        $estado = self::consultar($base);

        self::recordar($base, $estado);

        return $estado['anda'];
    }

    /**
     * Dice si la página declara que sabe abrir una sesión de solo lectura (`"soporte": true` en el
     * mismo `recursos-estado.json`). Solo vale `true` estricto: un `"true"` en texto o un `1` no
     * cuentan, y una página que no anda tampoco admite nada.
     *
     * Misma política de cache que `disponible()`, con clave propia. Si la clave de soporte no está
     * (por ejemplo, el cache es de antes de este cambio y solo tiene la de `disponible`), se consulta
     * la página de nuevo: una sola consulta que deja las dos al día.
     *
     * @return bool
     */
    public static function admite_soporte()
    {
        $base = self::url_de_la_pagina();

        $guardado = Cache::get(self::clave_de_soporte($base));

        if (!is_null($guardado)) {
            return (int) $guardado === 1;
        }

        $estado = self::consultar($base);

        self::recordar($base, $estado);

        return $estado['soporte'];
    }

    /**
     * Clave de cache de "la página anda". Lleva la URL: si cambia la config, no se arrastra el
     * resultado de la otra.
     *
     * @param  string $base URL base de la página.
     * @return string
     */
    protected static function clave_de_disponibilidad($base)
    {
        return 'centro_recursos:disponible:' . md5($base);
    }

    /**
     * Clave de cache de "la página admite soporte". Mismo criterio que la de disponibilidad.
     *
     * @param  string $base URL base de la página.
     * @return string
     */
    protected static function clave_de_soporte($base)
    {
        return 'centro_recursos:soporte:' . md5($base);
    }

    /**
     * Deja en el cache las dos respuestas de una misma consulta.
     *
     * @param  string $base   URL base de la página.
     * @param  array  $estado `['anda' => bool, 'soporte' => bool]`, como lo devuelve consultar().
     * @return void
     */
    protected static function recordar($base, array $estado)
    {
        Cache::put(
            self::clave_de_disponibilidad($base),
            $estado['anda'] ? 1 : 0,
            $estado['anda'] ? self::CACHE_SI_ANDA : self::CACHE_SI_NO_ANDA
        );

        Cache::put(
            self::clave_de_soporte($base),
            $estado['soporte'] ? 1 : 0,
            $estado['soporte'] ? self::CACHE_SI_ADMITE_SOPORTE : self::CACHE_SI_NO_ANDA
        );
    }

    /**
     * La consulta en sí. Nunca tira: un error de red es "no anda" (y, por lo tanto, sin soporte).
     *
     * @param  string $base URL base de la página.
     * @return array `['anda' => bool, 'soporte' => bool]`. `soporte` solo es true si además anda.
     */
    protected static function consultar($base)
    {
        $no_anda = ['anda' => false, 'soporte' => false];

        try {
            $respuesta = Http::timeout(self::TIMEOUT_SEGUNDOS)
                ->acceptJson()
                ->get($base . '/recursos-estado.json');

            if (!$respuesta->successful()) {
                Log::info('Centro de recursos: la página respondió ' . $respuesta->status() . ' al chequeo de estado.');
                return $no_anda;
            }

            $cuerpo = $respuesta->json();

            $anda = is_array($cuerpo) && isset($cuerpo['ok']) && $cuerpo['ok'] === true;

            if (!$anda) {
                return $no_anda;
            }

            return [
                'anda'    => true,
                'soporte' => isset($cuerpo['soporte']) && $cuerpo['soporte'] === true,
            ];
        } catch (\Throwable $e) {
            Log::info('Centro de recursos: la página no responde al chequeo de estado: ' . $e->getMessage());
            return $no_anda;
        }
    }
}
