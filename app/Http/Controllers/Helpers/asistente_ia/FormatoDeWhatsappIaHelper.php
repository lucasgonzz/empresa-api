<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * El saneo de formato de la respuesta del asistente por WhatsApp (A8 de la misión
 * asistente-truvari-pedidos-fallidos, 13/11/2026).
 *
 * En WhatsApp *texto* es negrita y _texto_ cursiva; el markdown de la pantalla no: «**Total**» se ve con
 * los cuatro asteriscos y «### Resumen» con las almohadillas. El prompt de WhatsApp ya lo dice
 * (AsistenteIaService::formato_de_whatsapp()); esto es la red para cuando el modelo igual lo escribe:
 *   - **x** → *x* y __x__ → _x_;
 *   - un título con # al empezar el renglón («## Resumen») → el título en negrita, sin las almohadillas;
 *   - los renglones que son solo una cerca de código (```) se sacan.
 * Solo para el canal whatsapp: el sistema y el MCP siguen con su texto tal cual.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class FormatoDeWhatsappIaHelper
{
    /**
     * @param  string  $texto
     * @return string
     */
    public static function sanear($texto)
    {
        $texto = (string) $texto;

        if ($texto === '') {
            return $texto;
        }

        /* Las cercas de código, solas en su renglón. */
        $texto = (string) preg_replace('/^[ \t]*```[^\n]*\n?/mu', '', $texto);

        /* Los títulos: «## Resumen del día» → «*Resumen del día*» (o sin asteriscos si ya traía). */
        $texto = (string) preg_replace_callback('/^[ \t]{0,3}#{1,6}[ \t]+(.+?)[ \t#]*$/mu', function ($m) {
            $titulo = trim($m[1]);

            return (strpos($titulo, '*') !== false) ? $titulo : '*' . $titulo . '*';
        }, $texto);

        /* Doble asterisco y doble guion bajo → los de WhatsApp. */
        /*
         * Revisión independiente (13/11/2026): solo un par limpio, en un mismo renglón y sin otro asterisco o
         * guion bajo adentro: «Código **AB*12**», «precio 10 ** 2» o «__init__» quedan como estaban. Ante la
         * duda, el texto del modelo no se toca (antes un ** suelto se borraba).
         */
        $texto = (string) preg_replace('/\*\*(?=[^\s*])([^*\n]+?)(?<=[^\s*])\*\*/u', '*$1*', $texto);
        $texto = (string) preg_replace('/(?<![\w])__(?=[^\s_])([^_\n]+?)(?<=[^\s_])__(?![\w])/u', '_$1_', $texto);

        /* Sacar una cerca del final deja el salto de línea de antes colgando. */
        return rtrim($texto);
    }
}
