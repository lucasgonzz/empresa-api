<?php

namespace App\Services\ImageSearch;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Elige el proveedor de búsqueda de imágenes de una asignación inteligente (misión
 * imagenes-catalogo-completo, 27/9/2026): Serper si hay una clave de Serper para el dueño, y si no,
 * Google Custom Search con las credenciales del dueño.
 *
 * De dónde sale la clave de Serper (misión serper-en-user-setup, 28/9/2026), en este orden:
 *   1. la del COMERCIO: `users.serper_api_key` de la fila del dueño. La manda el admin en el payload
 *      de user-setup y de demo-setup (campo opcional `serper_api_key`, igual que la de Google) y la
 *      guardan UserSetupHelper y DemoSetupHelper al crear al dueño (clave_serper_del_payload());
 *   2. la del SERVIDOR: `config('services.serper.api_key')` (SERPER_API_KEY del .env), el respaldo.
 * La del dueño le gana a la del .env: así una instalación nueva sale buscando con Serper sin que
 * nadie entre al servidor a tocar el .env ni a reiniciar las colas. Un admin viejo no la manda: la
 * columna queda en null y todo sigue como antes, con la del .env.
 *
 * La elección del PROVEEDOR se guarda en la asignación al crearla (image_assignment_runs.proveedor)
 * y el job la respeta en cada tramo: una corrida larga no cambia de proveedor a mitad de camino. La
 * CLAVE, en cambio, se resuelve de nuevo en cada tramo (el job relee al dueño y el motor arma el
 * proveedor con para()): si se la cambian con la corrida en marcha, el tramo siguiente usa la nueva.
 *
 * 🔴 La clave no sale de acá más que hacia el header de la búsqueda (SerperImageSearchProvider):
 * User::$hidden la saca de toda respuesta que vaya al navegador e ImageServiceCallLogger::sin_claves()
 * la tacha de los errores que se registran.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class ImageSearchProviderFactory
{
    /**
     * Largo máximo de una clave de Serper que se acepta del payload del setup: el de la columna
     * users.serper_api_key (migración 2026_09_28_100000). Las claves de Serper son alfanuméricas de
     * 32 a 64 caracteres (el admin las valida con esa forma): una más larga no es una clave, y
     * guardarla reventaría el INSERT del dueño con la base recién vaciada (ver clave_serper_del_payload()).
     */
    const LARGO_MAXIMO_CLAVE_SERPER = 100;

    /**
     * La clave de Serper con la que se busca para este dueño: la suya si la tiene cargada, si no la
     * del servidor. '' si no hay ninguna de las dos.
     *
     * Espera al DUEÑO, que es la fila donde el setup guarda la clave: todos los que llaman ya lo
     * tienen a mano (una asignación es del dueño y los endpoints lo resuelven con userId()). Si la
     * columna todavía no existe (el código llegó antes que la migración), el atributo da null y se
     * cae a la del servidor, como antes.
     *
     * @param  \App\Models\User|null $owner  Null = solo la del servidor (el comportamiento de antes).
     * @return string
     */
    public static function clave_serper_para(User $owner = null)
    {
        if (!is_null($owner)) {
            // La del comercio, recortada: una fila con solo espacios cuenta como vacía.
            $del_dueno = trim((string) $owner->serper_api_key);

            if ($del_dueno !== '') {
                return $del_dueno;
            }
        }

        return trim((string) config('services.serper.api_key'));
    }

    /**
     * ¿Hay una clave de Serper para este dueño, la suya o la del servidor?
     *
     * @param  \App\Models\User|null $owner  Null = solo mira la del servidor, como antes de la misión
     *                                       serper-en-user-setup.
     * @return bool
     */
    public static function serper_configurado(User $owner = null)
    {
        return self::clave_serper_para($owner) !== '';
    }

    /**
     * El nombre del proveedor que le toca a una asignación nueva de este dueño: Serper si hay clave
     * para él (la suya o la del servidor), si no Google.
     *
     * @param  \App\Models\User $owner
     * @return string  'serper' | 'google'
     */
    public static function nombre_para(User $owner)
    {
        return self::serper_configurado($owner) ? 'serper' : 'google';
    }

    /**
     * El proveedor para este dueño: el que se pide por nombre (el de una asignación ya creada) o,
     * sin nombre, el que le toca hoy.
     *
     * Serper se arma con la clave ya resuelta para el dueño (clave_serper_para()): el proveedor no
     * vuelve a mirar config cuando recibe una. Si no hay ninguna recibe '' y cada búsqueda falla con
     * "no hay clave" sin salir a la red; el job corta antes de llegar a eso
     * (ProcessImageAssignmentRunJob::handle()).
     *
     * @param  \App\Models\User $owner
     * @param  string|null      $nombre  'serper' | 'google' | null.
     * @return \App\Services\ImageSearch\ImageSearchProvider
     */
    public static function para(User $owner, $nombre = null)
    {
        $nombre = is_null($nombre) ? self::nombre_para($owner) : (string) $nombre;

        if ($nombre === 'serper') {
            return new SerperImageSearchProvider(self::clave_serper_para($owner));
        }

        return new GoogleCustomSearchImageProvider($owner);
    }

    /**
     * La clave de Serper de un payload de user-setup o de demo-setup, lista para guardar en
     * users.serper_api_key: recortada, o null si no vino, vino vacía o no tiene forma de clave.
     *
     * La usan UserSetupHelper::create_user() y DemoSetupHelper::create_demo_user(): un solo lugar,
     * así los dos caminos de alta no pueden quedar distintos. Sin fallback propio a propósito (a
     * diferencia de la clave de Google): null = "el admin no mandó nada" y el buscador usa la del
     * .env del servidor.
     *
     * Por qué filtra en vez de guardar lo que venga: esto corre con la base recién vaciada por el
     * `migrate:fresh` del setup. Un valor más largo que la columna reventaría el INSERT del dueño y
     * dejaría el sistema sin usuario; uno con espacios o caracteres de control no es una clave y,
     * guardado, le ganaría a una buena del .env. En los dos casos se descarta, se avisa en el log
     * (sin el valor: es un secreto) y la instalación sigue.
     *
     * @param  array $data  El payload del setup, tal cual lo pasa el endpoint de admin-sync.
     * @return string|null
     */
    public static function clave_serper_del_payload(array $data)
    {
        // No vino (admin viejo, o la clave sin cargar en el admin): null, sin ruido.
        if (!isset($data['serper_api_key'])) {
            return null;
        }

        $valor = $data['serper_api_key'];

        if (!is_string($valor)) {
            Log::warning('[ImagenesInteligentes] La clave de Serper del setup no vino como texto: no se guarda y el buscador usa la del servidor.', [
                'tipo' => gettype($valor),
            ]);

            return null;
        }

        $clave = trim($valor);

        if ($clave === '') {
            return null;
        }

        // Más larga que la columna, o con espacios / caracteres de control en el medio: no es una clave.
        if (strlen($clave) > self::LARGO_MAXIMO_CLAVE_SERPER || preg_match('/[\x00-\x20\x7F]/', $clave)) {
            Log::warning('[ImagenesInteligentes] La clave de Serper del setup no tiene forma de clave: no se guarda y el buscador usa la del servidor.', [
                'largo' => strlen($clave),
            ]);

            return null;
        }

        return $clave;
    }
}
