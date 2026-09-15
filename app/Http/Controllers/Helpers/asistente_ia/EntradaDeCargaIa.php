<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\agenda\AgendaTareaHelper;

/**
 * Lectura del input que manda la IA a las herramientas proponer_* (misión asistente-ia-acciones).
 *
 * El input viene de un modelo de lenguaje: una clave puede faltar, venir como string en vez de
 * número o traer "dólares" con tilde. Acá se lee de una sola forma para las cinco propuestas, y cada
 * cosa que no se puede interpretar vuelve como respuesta de negocio (RespuestaDeCargaIa) con el
 * motivo concreto, nunca como una excepción que la IA leería como falla técnica.
 */
class EntradaDeCargaIa {

    /**
     * Valor de una clave, null si no vino.
     *
     * @param  array  $input
     * @param  string  $clave
     * @return mixed
     */
    static function valor(array $input, $clave) {

        return array_key_exists($clave, $input) ? $input[$clave] : null;
    }

    /**
     * true si el valor no trae nada (null, o un string en blanco).
     *
     * @param  mixed  $valor
     * @return bool
     */
    static function vacio($valor) {

        return is_null($valor) || (is_string($valor) && trim($valor) === '');
    }

    /**
     * Texto recortado de una clave ('' si no vino o no es texto).
     *
     * @param  array  $input
     * @param  string  $clave
     * @return string
     */
    static function texto(array $input, $clave) {

        $valor = self::valor($input, $clave);

        return is_scalar($valor) && !is_bool($valor) ? trim((string) $valor) : '';
    }

    /**
     * Moneda de la carga: 1 (pesos) si no vino, 2 (dólares) solo si la cuenta trabaja en dólares.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $valor  'pesos' | 'dolares' (con o sin tilde) | null
     * @return int|array  moneda_id, o la respuesta negativa.
     */
    static function moneda(ContextoDeCargaIa $contexto, $valor) {

        if (self::vacio($valor)) {

            return 1;
        }

        $texto = strtr(mb_strtolower(trim((string) $valor)), ['ó' => 'o', 'á' => 'a']);

        if (in_array($texto, ['pesos', 'peso', 'ars'], true)) {

            return 1;
        }

        if (in_array($texto, ['dolares', 'dolar', 'usd', 'u$s', 'us$'], true)) {

            if (!$contexto->usa_dolares) {

                return RespuestaDeCargaIa::error('La cuenta no trabaja con dólares: la carga va en pesos.');
            }

            return FormatoIaHelper::MONEDA_DOLARES;
        }

        return RespuestaDeCargaIa::error('La moneda tiene que ser pesos o dolares.');
    }

    /**
     * Fecha de la carga como día. Sin fecha es hoy (salvo que sea obligatoria). Usa el mismo parseo
     * que la Agenda (AgendaTareaHelper::parsear_fecha): AAAA-MM-DD, sin convertir zona horaria.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $valor
     * @param  string|null  $falta_si_vacia  Si viene, una fecha vacía devuelve `faltan` con este texto.
     * @return \Carbon\Carbon|array
     */
    static function fecha(ContextoDeCargaIa $contexto, $valor, $falta_si_vacia = null) {

        if (self::vacio($valor)) {

            if (!is_null($falta_si_vacia)) {

                return RespuestaDeCargaIa::faltan([$falta_si_vacia]);
            }

            return $contexto->hoy->copy()->startOfDay();
        }

        $fecha = AgendaTareaHelper::parsear_fecha(is_string($valor) ? trim($valor) : $valor);

        if (is_null($fecha)) {

            return RespuestaDeCargaIa::error('La fecha tiene que venir como AAAA-MM-DD.');
        }

        return $fecha;
    }

    /**
     * Monto mayor a 0, redondeado a centavos.
     *
     * @param  mixed  $valor
     * @param  string  $motivo  Texto del error si no es un número mayor a 0.
     * @return float|array
     */
    static function monto_positivo($valor, $motivo) {

        if (!is_numeric($valor) || (float) $valor <= 0) {

            return RespuestaDeCargaIa::error($motivo);
        }

        return round((float) $valor, 2);
    }
}
