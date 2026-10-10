<?php

namespace App\Http\Controllers\Helpers;

use App\Exceptions\BaseConDatosException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Guarda de "no vaciar una base que ya tiene datos de negocio" para todo lo que corre un
 * `migrate:fresh` (hoy: `UserSetupHelper::run()`).
 *
 * POR QUÉ EXISTE (el caso real, 5/10/2026)
 * ----------------------------------------
 * `UserSetupHelper::run()` arranca con `migrate:fresh`, y la ruta POST /api/admin-sync/user-setup
 * no pedía ninguna clave. El 5/10/2026 un test de admin-api que apuntaba a una URL de producción
 * (la de Panchito) hizo un POST real a esa ruta y dejó la base de ese cliente en cero: 102.754
 * ventas y 6.952 artículos. Se restauró del respaldo de las 03:22. La puerta no distinguía un
 * "instalar de cero" de un POST perdido: las dos cosas llegaban igual y las dos borraban todo.
 *
 * QUÉ HACE
 * --------
 * Antes de borrar, mira si la base tiene datos de negocio (TABLAS_DE_NEGOCIO). Si tiene, se niega
 * con una BaseConDatosException y no toca nada. Hay una sola forma de pasarla por encima, y es
 * deliberadamente incómoda: el payload tiene que traer `forzar_borrado_total` Y
 * `confirmar_base_de_datos` con el nombre EXACTO de la base que se va a vaciar, Y la puerta tiene
 * que haber verificado la clave de admin de la instancia (X-Admin-Api-Key == ADMIN_API_INBOUND_KEY,
 * con ClaveDeAdminEnSetup::trae_la_clave_de_admin()) y pasarla como `$con_clave_de_admin = true`.
 *
 * Una base vacía (el "sistema recién instalado y migrado, sin ningún usuario" que dejan
 * /instalar-cliente y /implementar) pasa sin ningún parámetro: el alta de clientes funciona igual
 * que siempre.
 *
 * 🔴 POR QUÉ EL FORZADO PIDE LA CLAVE DE ADMIN SIEMPRE (misión cerrar-forzado-user-setup, 10/10/2026)
 * --------------------------------------------------------------------------------------------------
 * Con solo el flag y el nombre, POST /api/admin-sync/user-setup vaciaba una producción: la clave de
 * la ruta está apagada en toda la flota (ADMIN_SYNC_SETUP_REQUIRE_API_KEY) y el nombre de la base NO
 * es una credencial, se deduce del cliente (`panchito` en el VPS, `u767360347_...` en el shared). Dos
 * campos adivinables no pueden alcanzar para borrar todo.
 *
 * La clave se pide para FORZAR aunque la variable siga apagada, y eso no rompe a nadie: admin-api
 * nunca manda esos dos campos (`ImplementationUserSetupService` los saca y `RunUserSetupService`
 * arma el payload campo por campo), así que todo lo que hoy forzaba sin clave era alguien que no
 * tenía por qué. La variable general queda como está porque gobierna el setup NORMAL (base vacía), y
 * prenderla hoy rompería el alta de clientes: una instalación nueva nace sin ADMIN_API_INBOUND_KEY.
 *
 * Quien de verdad necesita re-correr el setup sobre una base con datos (la raíz del pool) lo hace por
 * SSH al servidor del cliente, disparando el POST desde ahí con la clave que lee de SU propio `.env`:
 * la clave no viaja a ningún lado. Si la instancia no tiene clave cargada, primero se la carga desde
 * el admin (`POST claude/clients/{id}/catalogo/clave`); sin clave nadie fuerza por HTTP (falla
 * cerrado). Y si el que llama trae el nombre bien pero sin la clave, el log lo dice con una línea
 * propia; la respuesta, no (ver exigir_base_sin_datos_o_autorizacion()).
 *
 * 🔴 POR QUÉ LA GUARDA VIVE ADENTRO DE `UserSetupHelper::run()` Y NO EN LOS CONTROLADORES
 * -------------------------------------------------------------------------------------
 * `run()` es el único punto por el que pasan las dos puertas de user-setup (la de admin-sync y
 * el formulario web) y por el que va a pasar la próxima que alguien agregue (un comando, un job,
 * otro endpoint). Una guarda en el controlador cierra la puerta que existe hoy y deja abierta la
 * de mañana: es la misma lección de DemoSetupLockHelper ("un candado que cierra dos de tres
 * puertas no cierra nada"). Si te dan ganas de moverla a los controladores "por prolijidad",
 * releé esto.
 *
 * 🔴 POR QUÉ EL CRITERIO ES ESTRICTO (cualquier fila en `users` ya cuenta)
 * ------------------------------------------------------------------------
 * Un falso bloqueo (re-correr el setup sobre un sistema recién instalado que ya tiene su dueño)
 * cuesta mandar un parámetro más. Un falso permiso cuesta una base de producción. No se refina
 * con "más de N usuarios" ni "con alguna venta": la asimetría es de ese tamaño. Las demás tablas
 * de la lista son cinturón y tirantes por si alguien borró los usuarios y quedó el negocio.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class BorradoTotalDeBaseHelper
{
    /** Campo del payload que pide el borrado total aunque la base tenga datos. */
    const FLAG = 'forzar_borrado_total';

    /** Campo del payload que tiene que traer el nombre exacto de la base que se va a vaciar. */
    const CONFIRMACION = 'confirmar_base_de_datos';

    /**
     * Tablas cuyo contenido es "datos de negocio": con una sola fila en cualquiera, la base NO
     * está vacía. Una tabla que no existe (base sin migrar) cuenta como vacía.
     *
     * 🔴 Criterio para agregar una: tiene que ser un dato de negocio INEQUÍVOCO (lo cargó o lo generó
     * el cliente al operar) y NINGÚN seeder ni paso de `UserSetupHelper::run()` puede poblarla. Si
     * una instalación recién hecha ya la dejara con filas, un re-run legítimo se rechazaría siempre.
     * Hay un test que exige que todas existan (un nombre mal escrito sería una tabla ausente, o sea
     * "vacía" en silencio: la guarda dejaría de ver esos datos sin avisar), y otro que prueba cada
     * tabla por separado.
     *
     * - `current_acounts` es la errata real del esquema (una sola "c"), no un error de tipeo acá.
     * - Se suman a las del negocio central: `afip_tickets` (comprobantes fiscales emitidos),
     *   `buyers` (compradores de la tienda), `cheques` y `stock_movements`, por si alguien borró los
     *   usuarios y quedó el negocio.
     */
    const TABLAS_DE_NEGOCIO = [
        'users', 'articles', 'sales', 'clients', 'providers',
        'current_acounts', 'budgets', 'orders', 'provider_orders',
        'afip_tickets', 'buyers', 'cheques', 'stock_movements',
    ];

    /**
     * Nombre de la base de la conexión por defecto: la misma que usa `migrate:fresh`, o sea la
     * que se vaciaría.
     *
     * @return string
     */
    public static function nombre_de_la_base()
    {
        return (string) DB::connection()->getDatabaseName();
    }

    /**
     * Qué tablas de negocio tienen filas, y cuántas.
     *
     * Usa `exists()` antes de `count()` para no contar una tabla gigante cuando alcanza con saber
     * si tiene algo. Una tabla inexistente se saltea (base sin migrar = vacía). Un error real de
     * conexión NO se traga: se propaga, y el que llama contesta 500 sin haber borrado nada.
     * Tragárselo y devolver "vacía" sería exactamente el falso permiso que esta guarda evita.
     *
     * @return array<string, int> Tabla => cantidad de filas, solo las que tienen alguna.
     */
    public static function resumen_de_datos()
    {
        $resumen = [];

        // Una sola conexión para todo (la por defecto, la misma que vaciaría `migrate:fresh`): el
        // schema builder se pide a ella y no a la fachada Schema, que cachea el primero que resolvió.
        $conexion = DB::connection();
        $schema   = $conexion->getSchemaBuilder();

        foreach (self::TABLAS_DE_NEGOCIO as $tabla) {
            if (!$schema->hasTable($tabla)) {
                continue;
            }

            if (!$conexion->table($tabla)->exists()) {
                continue;
            }

            $resumen[$tabla] = (int) $conexion->table($tabla)->count();
        }

        return $resumen;
    }

    /**
     * ¿El payload PIDE y CONFIRMA vaciar esta base aunque tenga datos?
     *
     * 🔴 Es condición necesaria, no suficiente: para forzar de verdad hace falta además la clave de
     * admin verificada, que este método no mira (es del request, no del payload) y que exige
     * `exigir_base_sin_datos_o_autorizacion()`. Por eso no alcanza con que esto dé true.
     *
     * 🔴 El flag Y la confirmación del nombre, a propósito. Con un solo campo, un payload armado
     * con un spread de `setup_data` (como el de `ImplementationUserSetupService::build_payload()`
     * de admin-api) o un test que reenvía lo que le llega podría prender el borrado sin que nadie
     * lo haya querido. Pedir además el nombre exacto de la base obliga a que quien llama sepa a
     * qué base le está pegando, que es justo lo que le faltó al test que vació Panchito.
     *
     * Estricto con los valores: el flag es `true`, `1`, `'1'` o `'true'` (nada de `'false'`, `0`,
     * `''`, `'si'`, null ni ausente, que un `(bool)` o un `!empty()` dejarían pasar o confundirían)
     * y el nombre se compara con `hash_equals`, sensible a mayúsculas.
     *
     * 🔴 Dos aclaraciones para no sobreestimar lo que esto protege:
     * - Por HTTP, el middleware global `TrimStrings` le recorta los espacios al nombre ANTES de que
     *   llegue acá: " panchito" o "panchito " autorizan igual que "panchito" (verificado en vivo). Es
     *   aceptable, porque de todos modos hay que saber el nombre exacto; con el dato crudo (sin
     *   pasar por HTTP) un espacio de más sí lo rechaza.
     * - La confirmación NO es autenticación: es un freno contra los accidentes, no contra un
     *   atacante. El nombre de la base suele coincidir con el subdominio del cliente (la base de
     *   Panchito se llama `panchito`), o sea que cualquiera que sepa a qué cliente le pega lo puede
     *   adivinar. Lo que la hace valer es que un POST perdido, un payload armado con un spread o un
     *   test mal apuntado NO traen el nombre de la base correcta por casualidad. La defensa contra
     *   alguien que apunta a propósito es la clave de admin, que para forzar se pide SIEMPRE (aunque
     *   ADMIN_SYNC_SETUP_REQUIRE_API_KEY esté apagada): ver exigir_base_sin_datos_o_autorizacion().
     *
     * @param  array<string, mixed> $data Payload del setup.
     * @return bool
     */
    public static function esta_autorizado(array $data)
    {
        $flag = array_key_exists(self::FLAG, $data) ? $data[self::FLAG] : null;

        if (!in_array($flag, [true, 1, '1', 'true'], true)) {
            return false;
        }

        $confirmacion = array_key_exists(self::CONFIRMACION, $data) ? $data[self::CONFIRMACION] : null;
        $base         = self::nombre_de_la_base();

        // hash_equals exige dos strings; una confirmación que no lo es (array, número) no confirma nada.
        if (!is_string($confirmacion) || $confirmacion === '' || $base === '') {
            return false;
        }

        return hash_equals($base, $confirmacion);
    }

    /**
     * Deja pasar solo si la base no tiene datos de negocio, o si el payload pide y confirma el
     * borrado (esta_autorizado()) Y la puerta verificó la clave de admin (`$con_clave_de_admin`
     * === true). Si no, tira BaseConDatosException SIN haber tocado nada.
     *
     * Las tres ramas con datos dejan rastro en el log (con el nombre de la base y los conteos, que
     * son para el servidor y no viajan en ninguna respuesta): el borrado total autorizado queda
     * registrado, el rechazado también, y el que trae el nombre correcto pero no la clave tiene su
     * línea propia, para poder reconstruir quién golpeó una base viva. Por eso el contexto lleva
     * además la IP y el user agent del request (ver datos_del_request()): el 5/10/2026 costó
     * reconstruir quién había pegado, y la forense empieza por ahí.
     *
     * Los dos rechazos tiran la MISMA excepción (mismo mensaje, mismas tablas), así que el 409 es
     * idéntico con el nombre de la base acertado o errado: la respuesta no sirve para adivinarlo.
     *
     * @param  array<string, mixed> $data               Payload del setup.
     * @param  bool                 $con_clave_de_admin Si la puerta que llama YA verificó la clave de
     *                                                  admin de la instancia
     *                                                  (ClaveDeAdminEnSetup::trae_la_clave_de_admin()).
     *                                                  Solo `true` estricto cuenta: `1`, `'1'` o
     *                                                  `'true'` no. Por defecto false: quien no la
     *                                                  pasa a propósito no puede forzar.
     * @return void
     *
     * @throws BaseConDatosException Si la base tiene datos y no se autorizó el borrado (payload que
     *                               pide y confirma + clave de admin verificada).
     */
    public static function exigir_base_sin_datos_o_autorizacion(array $data, $con_clave_de_admin = false)
    {
        $resumen = self::resumen_de_datos();

        // Base vacía: es el "instalar de cero" de siempre, sin ningún parámetro extra.
        if (empty($resumen)) {
            return;
        }

        $base = self::nombre_de_la_base();

        $pide_y_confirma = self::esta_autorizado($data);

        // Las dos cosas: el payload pide y confirma el nombre, Y la clave de admin vino y es la de esta
        // instancia. `=== true` a propósito: un `'1'` que se coló desde un payload no es una clave.
        if ($pide_y_confirma && $con_clave_de_admin === true) {
            Log::warning('BorradoTotalDeBaseHelper: borrado total AUTORIZADO sobre una base con datos.', array_merge([
                'base'      => $base,
                'con_datos' => $resumen,
            ], self::datos_del_request()));

            return;
        }

        if ($pide_y_confirma) {
            /*
             * 🔴 El payload trae el flag Y el nombre EXACTO de la base, pero no la clave de admin. No es
             * un POST perdido ni un spread: quien llamó sabía a qué base le pegaba, y ningún llamador
             * legítimo arma esto (admin-api nunca manda esos dos campos y la raíz fuerza desde el
             * servidor del cliente, con la clave). Por eso tiene su propia línea, distinta del rechazo
             * de siempre: es la que hay que buscar en el log para ver si alguien está probando.
             *
             * La RESPUESTA, en cambio, es la misma que la de cualquier otro rechazo (misma excepción,
             * mismo mensaje, mismas tablas): si dijera "acertaste el nombre, te falta la clave",
             * serviría de oráculo para adivinar cómo se llama la base. El motivo real vive solo acá.
             */
            Log::warning(
                'BorradoTotalDeBaseHelper: se rechazó un setup que pidió el borrado total con el nombre '
                . 'correcto de la base pero SIN la clave de admin. Quien llamó sabía cómo se llama la base: '
                . 'revisar de dónde vino. No se tocó nada.',
                array_merge([
                    'base'      => $base,
                    'con_datos' => $resumen,
                ], self::datos_del_request())
            );

            throw new BaseConDatosException($base, $resumen);
        }

        Log::warning(
            'BorradoTotalDeBaseHelper: se rechazó un setup que vaciaría una base con datos de negocio. '
            . 'Para forzarlo, el payload tiene que traer "' . self::FLAG . '" y "' . self::CONFIRMACION
            . '" con el nombre exacto de la base, y el request la clave de admin de la instancia '
            . '(X-Admin-Api-Key).',
            array_merge([
                'base'      => $base,
                'con_datos' => $resumen,
            ], self::datos_del_request())
        );

        throw new BaseConDatosException($base, $resumen);
    }

    /**
     * Quién pegó: la IP y el user agent del request HTTP en curso, para el log (forense de un
     * rechazo o de un borrado total autorizado). Si no hay request HTTP, los dos valen null.
     *
     * 🔴 SOLO esos dos datos. Nunca los headers (ahí viaja X-Admin-Api-Key, la clave de admin) ni el
     * payload (trae las claves de Serper y de Google del cliente): este contexto va al log de la
     * instancia y no se tacha. El user agent se recorta a 120 caracteres para que un cliente
     * malicioso no pueda inflar el log.
     *
     * "Sin request" incluye la consola: ahí Laravel arma un request FICTICIO (SetRequestForConsole:
     * 127.0.0.1 y el agente "Symfony") que no es nadie, y registrarlo sería mentir. Los tests corren
     * en consola pero hacen requests HTTP de verdad, por eso `runningUnitTests()` los exceptúa.
     *
     * @return array{ip: string|null, user_agent: string|null}
     */
    protected static function datos_del_request()
    {
        $datos = ['ip' => null, 'user_agent' => null];

        if (!app()->bound('request')) {
            return $datos;
        }

        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return $datos;
        }

        $request = app('request');

        $datos['ip'] = $request->ip();

        $agente = $request->userAgent();

        if (is_string($agente)) {
            $datos['user_agent'] = mb_substr($agente, 0, 120, 'UTF-8');
        }

        return $datos;
    }
}
