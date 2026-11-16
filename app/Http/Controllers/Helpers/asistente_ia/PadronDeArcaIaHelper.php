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

        try {

            $respuesta = $padron->consultar($digitos);

        } catch (\Throwable $e) {

            Log::warning('PadronDeArcaIaHelper: falló la consulta al padrón de ARCA', ['cuit' => $digitos, 'error' => $e->getMessage()]);

            $vacio['consultado'] = true;
            $vacio['error'] = 'ARCA no contestó';

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
