<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Forma de las respuestas "de negocio" de las herramientas de carga del asistente de IA (misión
 * asistente-ia-acciones, §3.3 del plan): `{"ok": false, "faltan": [...], "opciones": {...},
 * "error": "motivo concreto"}`.
 *
 * Que falte un dato, que haya dos opciones o que la persona no tenga permiso NO es una falla técnica:
 * esta respuesta viaja SIN `is_error`, y la IA la usa para preguntar (faltan) o para contar el motivo
 * tal cual (error). `is_error` queda solo para excepciones. Las cuatro claves van siempre, para que
 * la IA no tenga que adivinar la forma según el caso.
 */
class RespuestaDeCargaIa {

    /**
     * Falta un dato para armar la carga: la IA lo pregunta, ofreciendo las opciones por su nombre.
     *
     * @param  array<int,string>  $faltan  Qué falta, dicho como para preguntarlo ("cómo se pagó").
     * @param  array  $opciones  Listas para ofrecer (cajas, métodos de pago, cuentas...).
     * @return array
     */
    static function faltan(array $faltan, array $opciones = []) {

        return [
            'ok'       => false,
            'faltan'   => array_values($faltan),
            'opciones' => self::objeto($opciones),
            'error'    => null,
        ];
    }

    /**
     * La carga no se puede hacer así: la IA cuenta el motivo tal cual y no agrega otro.
     *
     * @param  string  $motivo
     * @param  array  $opciones
     * @return array
     */
    static function error($motivo, array $opciones = []) {

        return [
            'ok'       => false,
            'faltan'   => [],
            'opciones' => self::objeto($opciones),
            'error'    => (string) $motivo,
        ];
    }

    /**
     * true si el valor es una de estas respuestas negativas.
     *
     * @param  mixed  $respuesta
     * @return bool
     */
    static function es_negativa($respuesta) {

        return is_array($respuesta) && array_key_exists('ok', $respuesta) && $respuesta['ok'] === false;
    }

    /**
     * `opciones` viaja siempre como objeto JSON: un array PHP vacío se serializaría como `[]`.
     *
     * @param  array  $opciones
     * @return array|\stdClass
     */
    protected static function objeto(array $opciones) {

        return count($opciones) ? $opciones : new \stdClass();
    }
}
