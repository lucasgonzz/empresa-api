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
     * Un `error` con su CÓDIGO (misión agente-mejoras-uso-real, 29/10/2026, §4 E3.2): la misma respuesta
     * de error() más la clave `codigo`, para los motivos que el modelo tiene que poder distinguir sin
     * interpretar el texto ("sin_whatsapp_de_clientes", "sin_direccion_de_tienda", "articulo_no_publicado",
     * "articulo_sin_slug"...). Las cuatro claves de siempre siguen yendo; `codigo` es una quinta.
     *
     * @param  string  $codigo
     * @param  string  $motivo
     * @param  array  $opciones
     * @return array
     */
    static function error_con_codigo($codigo, $motivo, array $opciones = []) {

        $respuesta = self::error($motivo, $opciones);

        $respuesta['codigo'] = (string) $codigo;

        return $respuesta;
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
