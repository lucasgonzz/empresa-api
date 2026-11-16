<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\AfipConstanciaInscripcionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El padrón de ARCA para las altas y ediciones del asistente (misión asistente-diario-20261116, rama C,
 * ítem 30/10 #9).
 *
 * 🔴 EL CASO REAL (Ferre Total, conversación 29, mensajes 177-184, 30/10/2026). Pablo le pidió al agente
 * cambiarle el CUIT a un cliente para poder facturarle. El agente cambió el CUIT y dejó la razón social
 * vieja: la factura habría salido con el CUIT de uno y el nombre de otro, y Pablo tuvo que marcarlo. La
 * pantalla de Clientes no tiene ese problema porque al tipear el CUIT consulta el padrón
 * (ClientController::get_afip_information_by_cuit() → AfipConstanciaInscripcionController::
 * resolve_afip_lookup_for_client_modal()) y completa la razón social y la condición de IVA. Este helper
 * hace la MISMA consulta para la tarjeta del asistente, así la razón social nueva viaja en la misma
 * tarjeta que el CUIT nuevo y la persona confirma las dos cosas juntas.
 *
 * Lo que devuelve datos_del_cuit() (nunca lanza):
 *   - `consultado` false  → no se consultó (no hay padrón en este entorno: los tests sin doble). La tarjeta
 *                           queda como antes, sin aviso.
 *   - `error` no vacío    → se consultó y falló (ARCA caído, CUIT sin datos). La tarjeta lo dice.
 *   - `razon_social` y `iva_condition_id`/`condicion_iva` → lo que trajo el padrón, para la tarjeta.
 *
 * 🔴 EN LOS TESTS NUNCA SE SALE A ARCA. La consulta real pasa por el método de instancia consultar(); un
 * test la reemplaza con `app()->instance(PadronDeArcaIaHelper::class, $doble)`. Si se corre en tests SIN
 * doble, no se consulta nada (ver instancia()): un test viejo que edita un CUIT no tiene que intentar un
 * SOAP contra ARCA.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class PadronDeArcaIaHelper
{
    /**
     * Las entidades a las que se les completa la razón social desde el padrón: las mismas dos que la
     * pantalla consulta (Clientes y Proveedores).
     */
    const ENTIDADES = ['client', 'provider'];

    /**
     * La condición de IVA como la nombra el padrón → el nombre de la fila de `iva_conditions` (la que
     * siembra IvaConditionSeeder). «NO DETERMINADO» no está a propósito: si el padrón no lo sabe, no se
     * toca la condición que tenga el registro.
     */
    /**
     * Tope de la consulta al padrón desde el asistente, en segundos (revisión de la rama C). La pantalla de
     * Clientes espera lo que tarde ARCA (hasta 15 s por intento en el WS); el asistente no: un turno colgado
     * es peor que una tarjeta que dice que el padrón no contestó y pregunta la razón social.
     */
    const TOPE_SEGUNDOS = 8;

    /** Lo que dice la tarjeta cuando el padrón no contestó dentro del tope. */
    const MENSAJE_FUERA_DE_TIEMPO = 'el padrón no contestó a tiempo';

    const CONDICIONES = [
        'RESPONSABLE INSCRIPTO' => 'Responsable inscripto',
        'MONOTRIBUTO'           => 'Monotributista',
        'EXENTO'                => 'Exento',
    ];

    /**
     * Consulta el padrón de ARCA por un CUIT (11 dígitos) o un DNI (7 u 8), por el MISMO camino que la
     * pantalla de Clientes. Es de instancia para que un test la reemplace con un doble.
     *
     * @param  string  $digitos  Solo dígitos.
     * @return array  La forma de resolve_afip_lookup_for_client_modal(): hubo_un_error, error, afip_data.
     */
    public function consultar($digitos)
    {
        $controller = new AfipConstanciaInscripcionController();

        return $controller->resolve_afip_lookup_for_client_modal((string) $digitos);
    }

    /**
     * Los datos del padrón para un CUIT, listos para la tarjeta. Nunca lanza.
     *
     * @param  mixed  $cuit  El CUIT nuevo, como lo validó la carga (puede traer guiones).
     * @return array{consultado: bool, error: string|null, razon_social: string|null, condicion_iva: string|null, iva_condition_id: int|null}
     */
    public static function datos_del_cuit($cuit)
    {
        $vacio = [
            'consultado'       => false,
            'error'            => null,
            'razon_social'     => null,
            'condicion_iva'    => null,
            'iva_condition_id' => null,
        ];

        $digitos = preg_replace('/\D/', '', (string) $cuit);

        // Solo un CUIT completo: con un DNI el padrón A13 no trae condición de IVA y la pantalla tampoco la usa.
        if (strlen((string) $digitos) !== CuitIaHelper::LARGO_DE_CUIT) {

            return $vacio;
        }

        $padron = self::instancia();

        if (is_null($padron)) {

            return $vacio;
        }

        $tope = self::tope_de_segundos();
        $inicio = microtime(true);

        try {

            $respuesta = self::con_tope($tope, function () use ($padron, $digitos) {

                return $padron->consultar($digitos);
            });

        } catch (\Throwable $e) {

            Log::warning('PadronDeArcaIaHelper: falló la consulta al padrón de ARCA', ['cuit' => $digitos, 'error' => $e->getMessage()]);

            $vacio['consultado'] = true;
            $vacio['error'] = $e instanceof PadronFueraDeTiempoException ? self::MENSAJE_FUERA_DE_TIEMPO : 'ARCA no contestó';

            return $vacio;
        }

        /*
         * El tope también se mira al volver: sin pcntl (Windows, o un PHP sin la extensión) la consulta no se
         * puede cortar en el medio, y una respuesta que llegó pasado el tope se trata igual que una que no llegó
         * (el turno del asistente ya esperó de más; la tarjeta lo dice y la razón social se pregunta).
         */
        if (microtime(true) - $inicio > $tope) {

            Log::warning('PadronDeArcaIaHelper: el padrón de ARCA contestó pasado el tope', ['cuit' => $digitos, 'segundos' => round(microtime(true) - $inicio, 1)]);

            $vacio['consultado'] = true;
            $vacio['error'] = self::MENSAJE_FUERA_DE_TIEMPO;

            return $vacio;
        }

        $vacio['consultado'] = true;

        if (!is_array($respuesta) || !empty($respuesta['hubo_un_error']) || !isset($respuesta['afip_data']) || !is_array($respuesta['afip_data'])) {

            $error = is_array($respuesta) && isset($respuesta['error']) && is_scalar($respuesta['error']) ? trim((string) $respuesta['error']) : '';

            $vacio['error'] = $error === '' ? 'ARCA no devolvió datos para ese CUIT' : $error;

            return $vacio;
        }

        $datos = $respuesta['afip_data'];

        $razon_social = self::razon_social($datos);

        if ($razon_social === '') {

            $vacio['error'] = 'ARCA no devolvió la razón social de ese CUIT';

            return $vacio;
        }

        $vacio['razon_social'] = $razon_social;

        $condicion = isset($datos['condicion_iva']) && is_scalar($datos['condicion_iva']) ? mb_strtoupper(trim((string) $datos['condicion_iva'])) : '';

        if (isset(self::CONDICIONES[$condicion])) {

            $vacio['condicion_iva'] = self::CONDICIONES[$condicion];
            $vacio['iva_condition_id'] = self::id_de_condicion(self::CONDICIONES[$condicion]);
        }

        return $vacio;
    }

    /**
     * El id de una fila de `iva_conditions` por su nombre (sin mayúsculas), o null si no está.
     *
     * @param  string  $nombre  «Responsable inscripto», «Monotributista», «Consumidor final», «Exento».
     * @return int|null
     */
    public static function id_de_condicion($nombre)
    {
        try {

            $id = DB::table('iva_conditions')->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $nombre)])->value('id');

            return is_null($id) ? null : (int) $id;

        } catch (\Throwable $e) {

            return null;
        }
    }

    /**
     * La razón social que trae el padrón: la de la sociedad o, para una persona, «APELLIDO NOMBRE» (como la
     * arma ARCA en la constancia). Tal cual viene, con sus mayúsculas: si el dueño la quiere distinta, lo
     * pide y se respeta.
     *
     * @param  array  $datos  El `afip_data` de la consulta.
     * @return string
     */
    protected static function razon_social(array $datos)
    {
        if (isset($datos['razon_social']) && is_scalar($datos['razon_social']) && trim((string) $datos['razon_social']) !== '') {

            return trim((string) $datos['razon_social']);
        }

        $apellido = isset($datos['apellido']) && is_scalar($datos['apellido']) ? trim((string) $datos['apellido']) : '';
        $nombre = isset($datos['nombre']) && is_scalar($datos['nombre']) ? trim((string) $datos['nombre']) : '';

        return trim($apellido . ' ' . $nombre);
    }

    /**
     * El tope de la consulta al padrón desde el asistente, en segundos (revisión de la rama C). Se puede
     * cambiar con `services.afip.padron_tope_asistente` (los tests lo bajan); por defecto TOPE_SEGUNDOS. La
     * pantalla de Clientes no usa este tope: su código no se toca.
     *
     * @return float
     */
    public static function tope_de_segundos()
    {
        $tope = (float) config('services.afip.padron_tope_asistente', self::TOPE_SEGUNDOS);

        return $tope > 0 ? $tope : (float) self::TOPE_SEGUNDOS;
    }

    /**
     * Corre la consulta con un tope DURO cuando se puede: con pcntl (el worker de colas en Linux, que es donde
     * corre el turno del asistente) arma una alarma que corta la consulta a los `$tope` segundos tirando
     * PadronFueraDeTiempoException. Sin pcntl, la corre tal cual (el tope se mira al volver, en
     * datos_del_cuit()).
     *
     * 🔴 LA ALARMA ES DEL WORKER. `queue:work` usa SIGALRM para su propio `timeout` del job. Por eso: se guarda
     * su manejador y lo que le faltaba a su alarma; si le faltaba MENOS que el tope, no se arma nada (la del
     * worker corta antes y se respeta); y al terminar, salga bien o mal, se le devuelven su manejador y su
     * alarma con el tiempo que le quedaba.
     *
     * @param  float  $tope
     * @param  callable  $consulta
     * @return mixed
     */
    protected static function con_tope($tope, callable $consulta)
    {
        if (!function_exists('pcntl_alarm') || !function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal_get_handler') || !defined('SIGALRM')) {

            return call_user_func($consulta);
        }

        $segundos = max(1, (int) ceil($tope));

        $manejador_previo = pcntl_signal_get_handler(SIGALRM);
        $restante_previo = pcntl_alarm(0);

        // La alarma del worker vence antes que este tope: se le devuelve tal cual y se corre sin tope propio.
        if ($restante_previo > 0 && $restante_previo <= $segundos) {

            pcntl_alarm($restante_previo);

            return call_user_func($consulta);
        }

        $asincronas_previas = pcntl_async_signals(true);
        $inicio = time();

        pcntl_signal(SIGALRM, function () {
            throw new PadronFueraDeTiempoException('El padrón de ARCA no contestó a tiempo.');
        });

        pcntl_alarm($segundos);

        try {

            return call_user_func($consulta);

        } finally {

            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $manejador_previo);
            pcntl_async_signals($asincronas_previas);

            if ($restante_previo > 0) {

                pcntl_alarm(max(1, $restante_previo - (time() - $inicio)));
            }
        }
    }

    /**
     * El padrón a usar: el doble que haya registrado un test, o el real. En tests SIN doble, null (no se
     * sale a ARCA desde un test).
     *
     * @return PadronDeArcaIaHelper|null
     */
    protected static function instancia()
    {
        if (app()->bound(self::class)) {

            return app(self::class);
        }

        if (app()->runningUnitTests()) {

            return null;
        }

        return new self();
    }
}
