<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\User;

/**
 * «Ya no me quedan preguntas» con el onboarding sin terminar (misión onboarding-pausa-y-temas-20261125).
 *
 * 🔴 EL CASO REAL (demo3, 25/11/2026, msg 192). El dueño llevaba 17 de los 32 temas y el asistente le escribió «Como
 * ya no me quedan preguntas del negocio, cancelé el recordatorio de mañana». Era falso: la base decía que faltaban 15.
 * Ninguna guarda contrastaba esa afirmación contra las filas (la de «me lo anoto» no aplicaba: sí hubo
 * guardar_en_contexto).
 *
 * La guarda sólo corre con el onboarding `en_curso` y temas por responder, DESPUÉS de las herramientas del turno (los
 * temas que el turno marcó ya cuentan). Misma mecánica que AnotacionPrometidaIaHelper: un único reintento con una nota
 * que trae el número y las claves reales, y si sigue, se cambian esas oraciones por un texto verdadero.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class OnboardingCierreFalsoIaHelper
{
    /** Identificador de la guarda en AsistenteIaService::GUARDAS_DE_VERACIDAD. */
    const GUARDA = 'onboarding_cierre';

    /** Lo que afirma que ya no quedan preguntas ni temas, normalizado (sin tildes, sin signos). */
    const AFIRMA = '/\b(?:ya )?no me queda(?:n)? (?:mas |ningun |ninguna )?(?:preguntas?|temas?)\b|\bya (?:conozco|se|tengo) todo (?:lo que necesitaba )?(?:de|del|sobre) (?:tu |el )?negocio\b|\b(?:termine|terminamos|completamos|complete) (?:las |todas las )?preguntas\b/';

    /**
     * true si alguna oración afirma que el onboarding terminó (las preguntas, como «¿te quedan dudas?», no cuentan).
     *
     * @param  string  $saneado  El texto ya saneado.
     * @return bool
     */
    public static function afirma_que_termino($saneado)
    {
        return VeracidadDelTurnoIaHelper::alguna_oracion($saneado, function ($oracion) {
            return self::oracion_afirma_que_termino($oracion);
        });
    }

    /**
     * true si el dueño tiene el onboarding en curso y la base dice que faltan temas.
     *
     * @param  \App\Models\User|null  $dueno
     * @return bool
     */
    public static function faltan_temas($dueno)
    {
        if (!($dueno instanceof User) || !ConocimientoDelNegocioIaHelper::hay_tablas()) {

            return false;
        }

        if (OnboardingDelNegocioIaHelper::estado_de($dueno) !== OnboardingDelNegocioIaHelper::EN_CURSO) {

            return false;
        }

        return count(OnboardingDelNegocioIaHelper::progreso($dueno)['faltantes']) > 0;
    }

    /**
     * La nota del reintento: el número y las claves que faltan, de la base.
     *
     * @param  \App\Models\User  $dueno
     * @return string
     */
    public static function nota_para_reintentar(User $dueno)
    {
        $avance = OnboardingDelNegocioIaHelper::progreso($dueno);

        return '[El sistema revisó tu respuesta: decís que ya no te quedan preguntas, pero según la base el onboarding NO terminó: llevás '
            . $avance['listos'] . ' de ' . $avance['total'] . ' temas y faltan ' . count($avance['faltantes']) . ' (' . implode(', ', $avance['faltantes']) . '). '
            . 'No digas que terminaste ni cancelés el recordatorio. Si el dueño te contó alguno de esos temas en este mensaje, marcalo con guardar_en_contexto (temas); '
            . 'si no, seguí con el próximo, con una sola pregunta. '
            . VeracidadDelTurnoIaHelper::FRASE_DE_LA_RESPUESTA_COMPLETA;
    }

    /**
     * El texto sin las oraciones que dicen que terminó, con una frase verdadera al final.
     *
     * @param  string  $texto
     * @param  \App\Models\User|null  $dueno
     * @return string
     */
    public static function texto_honesto($texto, $dueno)
    {
        $faltan = ($dueno instanceof User) ? count(OnboardingDelNegocioIaHelper::progreso($dueno)['faltantes']) : 0;

        $aviso = $faltan > 0
            ? 'Todavía me faltan ' . $faltan . ' temas para conocer bien tu negocio; seguimos de a poco.'
            : '';

        return VeracidadDelTurnoIaHelper::sin_las_oraciones($texto, function ($oracion) {
            return self::oracion_afirma_que_termino($oracion);
        }, $aviso);
    }

    /**
     * true si la oración afirma (no pregunta) que no quedan preguntas.
     *
     * @param  string  $oracion
     * @return bool
     */
    protected static function oracion_afirma_que_termino($oracion)
    {
        if (VeracidadDelTurnoIaHelper::es_pregunta($oracion)) {

            return false;
        }

        return (bool) preg_match(self::AFIRMA, VeracidadDelTurnoIaHelper::normalizar($oracion));
    }
}
