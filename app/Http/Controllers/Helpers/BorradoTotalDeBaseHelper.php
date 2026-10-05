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
 * `confirmar_base_de_datos` con el nombre EXACTO de la base que se va a vaciar.
 *
 * Una base vacía (el "sistema recién instalado y migrado, sin ningún usuario" que dejan
 * /instalar-cliente y /implementar) pasa sin ningún parámetro: el alta de clientes funciona igual
 * que siempre.
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
     * ¿El payload autoriza vaciar esta base aunque tenga datos?
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
     * Deja pasar solo si la base no tiene datos de negocio o si el payload autoriza el borrado.
     * Si no, tira BaseConDatosException SIN haber tocado nada.
     *
     * Las dos ramas con datos dejan rastro en el log (con el nombre de la base y los conteos, que
     * son para el servidor y no viajan en ninguna respuesta): el borrado total autorizado queda
     * registrado, y el rechazado también, para poder reconstruir quién golpeó una base viva. Por
     * eso el contexto lleva además la IP y el user agent del request (ver datos_del_request()):
     * el 5/10/2026 costó reconstruir quién había pegado, y la forense empieza por ahí.
     *
     * @param  array<string, mixed> $data Payload del setup.
     * @return void
     *
     * @throws BaseConDatosException Si la base tiene datos y el payload no autoriza el borrado.
     */
    public static function exigir_base_sin_datos_o_autorizacion(array $data)
    {
        $resumen = self::resumen_de_datos();

        // Base vacía: es el "instalar de cero" de siempre, sin ningún parámetro extra.
        if (empty($resumen)) {
            return;
        }

        $base = self::nombre_de_la_base();

        if (self::esta_autorizado($data)) {
            Log::warning('BorradoTotalDeBaseHelper: borrado total AUTORIZADO sobre una base con datos.', array_merge([
                'base'      => $base,
                'con_datos' => $resumen,
            ], self::datos_del_request()));

            return;
        }

        Log::warning(
            'BorradoTotalDeBaseHelper: se rechazó un setup que vaciaría una base con datos de negocio. '
            . 'Para forzarlo, el payload tiene que traer "' . self::FLAG . '" y "' . self::CONFIRMACION
            . '" con el nombre exacto de la base.',
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
