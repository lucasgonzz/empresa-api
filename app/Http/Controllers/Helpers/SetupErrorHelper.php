<?php

namespace App\Http\Controllers\Helpers;

use App\Services\ImageAssignment\ImageServiceCallLogger;

/**
 * El error de un setup (admin-sync/user-setup y admin-sync/demo-setup) sin los secretos que vinieron
 * en el payload (misión serper-en-user-setup, 28/9/2026, revisión independiente).
 *
 * Por qué hace falta: los dos endpoints atrapan cualquier excepción de run(), la loguean y devuelven
 * su mensaje en el 500 (`'internal error: '.$mensaje`), y admin-api guarda ese texto en el lead
 * (leads.demo_setup_last_error / user_setup_last_error, hasta 2000 caracteres). Un QueryException de
 * Laravel 8 trae el SQL con los valores INTERPOLADOS, y el INSERT del dueño lleva serper_api_key y
 * google_custom_search_api_key: un INSERT que fallaba dejaba las dos claves en el log de la
 * instancia y en la base del admin.
 *
 * Qué se tacha (con ImageServiceCallLogger::sin_claves(), el mismo tachado del registro de consultas
 * de imágenes):
 *   - los valores de las claves del payload con nombre conocido (CLAVES_SECRETAS);
 *   - los de cualquier otro campo del payload cuyo NOMBRE tenga pinta de secreto (PATRON_SECRETO:
 *     hoy, demo_eventos_token), a cualquier profundidad: así el próximo secreto que sume el admin
 *     queda tapado sin que nadie se acuerde de volver acá;
 *   - y lo que sin_claves() ya tacha solo: las claves de config (SERPER_API_KEY,
 *     ANTHROPIC_API_KEY, GOOGLE_SEARCH_API_KEY, OPENAI_API_KEY) y las formas conocidas (`key=...`,
 *     `sk-ant-...`, `AIza...`).
 *
 * Como en sin_claves(), un valor de menos de 8 caracteres no se tacha: taparía pedazos de palabras
 * comunes del mensaje y no es una clave de verdad.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class SetupErrorHelper
{
    /**
     * Campos del payload que son secretos por nombre: las dos claves que manda el admin y que el
     * setup guarda en la fila del dueño.
     */
    const CLAVES_SECRETAS = ['serper_api_key', 'google_custom_search_api_key'];

    /**
     * Nombre de campo con pinta de secreto. Mismo criterio que EsquemaDeDatosIaHelper::COLUMNAS_SENSIBLES
     * (el que protege lo que lee el asistente), acotado a lo que puede ser una credencial, y con las
     * formas en castellano que el inglés no atrapa (`credenciales` no matchea `credential`).
     */
    const PATRON_SECRETO = '/pass|contrase|token|secret|api_key|apikey|clave|credential|credencial|_key$|^key$/i';

    /** Niveles de anidamiento del payload que se recorren como mucho (el payload es plano o casi). */
    const PROFUNDIDAD_MAXIMA = 5;

    /**
     * El texto (el mensaje o la traza de una excepción del setup) sin los secretos del payload ni las
     * claves que ya tacha sin_claves().
     *
     * Nunca lanza: si algo falla al tachar, devuelve un texto genérico en vez del crudo (un error al
     * limpiar no puede terminar mandando el secreto que se quería tapar).
     *
     * @param  string|null $texto
     * @param  array       $data   El payload del setup, tal cual lo recibió el endpoint.
     * @return string|null
     */
    public static function sin_secretos($texto, array $data)
    {
        try {
            return ImageServiceCallLogger::sin_claves($texto, self::secretos_del_payload($data));
        } catch (\Throwable $e) {
            return is_null($texto) ? null : 'No se pudo mostrar el detalle del error sin datos sensibles.';
        }
    }

    /**
     * Los valores del payload que no pueden salir en un mensaje: los de CLAVES_SECRETAS y los de
     * cualquier campo cuyo nombre matchee PATRON_SECRETO. Un campo secreto que trae un array marca
     * como secreto todo lo que tiene adentro.
     *
     * @param  array $data
     * @return array  Los valores, como texto y sin repetidos.
     */
    public static function secretos_del_payload(array $data)
    {
        $secretos = [];

        self::juntar_secretos($data, false, 0, $secretos);

        return array_values(array_unique($secretos));
    }

    /**
     * Recorre el payload juntando los valores secretos.
     *
     * @param  mixed $valor
     * @param  bool  $es_secreto  Si el valor cuelga de un campo con nombre de secreto.
     * @param  int   $profundidad
     * @param  array $secretos    Acumulador (por referencia).
     * @return void
     */
    protected static function juntar_secretos($valor, $es_secreto, $profundidad, array &$secretos)
    {
        if (is_array($valor)) {
            if ($profundidad >= self::PROFUNDIDAD_MAXIMA) {
                return;
            }

            foreach ($valor as $clave => $hijo) {
                $hijo_es_secreto = $es_secreto || (is_string($clave) && self::es_nombre_secreto($clave));

                self::juntar_secretos($hijo, $hijo_es_secreto, $profundidad + 1, $secretos);
            }

            return;
        }

        // Un booleano o un null no pueden estar escritos en el mensaje como una clave.
        if ($es_secreto && is_scalar($valor) && !is_bool($valor)) {
            $secretos[] = (string) $valor;
        }
    }

    /**
     * ¿El nombre de este campo es el de un secreto?
     *
     * @param  string $clave
     * @return bool
     */
    protected static function es_nombre_secreto($clave)
    {
        return in_array($clave, self::CLAVES_SECRETAS, true) || preg_match(self::PATRON_SECRETO, $clave) === 1;
    }
}
