<?php

namespace App\Services\Traits;

/**
 * Reglas de REGISTRO que comparten todos los textos que la IA le escribe al dueño del
 * negocio: los tres resúmenes de sugerencias (stock, compras, ofertas) y el system prompt
 * del asistente del chat.
 *
 * -------------------------------------------------------------------------------------
 * POR QUÉ ESTO VIVE EN UN SOLO LUGAR Y NO COPIADO EN CADA PROMPT
 * -------------------------------------------------------------------------------------
 *
 * Es el bloque que Lucas ajusta a mano cuando un mensaje le suena mal, y ya pasó una vez
 * (19/8/2026). Copiado en cuatro archivos, el próximo ajuste entra en el que se estaba
 * mirando y los otros tres se quedan con el registro viejo: el resumen de stock sale bien
 * y el de compras sigue diciendo "al pedo". La duplicación acá no es "un poco de repetición",
 * es tono divergente entre módulos del mismo asistente.
 *
 * Lo que NO va acá: el rol (encargado de depósito / de compras / de marketing), el formato
 * de salida y el largo. Eso es propio de cada prompt y cambia por motivos distintos.
 *
 * -------------------------------------------------------------------------------------
 * DE DÓNDE SALE CADA PROHIBICIÓN
 * -------------------------------------------------------------------------------------
 *
 * De un mensaje real que Lucas trajo el 19/8/2026 (resumen de una sugerencia de stock).
 * No son tics inventados: cada uno estaba en ese texto.
 *
 *   "Mirá, el sistema te armó 80 líneas... Ahora, acá viene lo picante: hay varios
 *    productos que figuran como urgentes... así que capaz no son tan críticos...
 *    Estamos hablando de cestos de basura... Te diría que arranques con lo que realmente
 *    se está vendiendo... así optimizamos el flete y no mandamos cosas al pedo."
 *
 * Seis familias distintas, no una: muletilla de apertura (Mirá, Ahora), coloquialismo de
 * suspenso (acá viene lo picante), atenuador hablado (capaz), relleno (Estamos hablando
 * de), hedge (Te diría que) y vulgarismo (al pedo).
 *
 * 🔴 El tuteo SE QUEDA. El pedido de Lucas fue "amigable y simple, que no le hable con
 * mucho vocabulario técnico, pero que tampoco sea tan informal": lo que sobra es el
 * coloquialismo, no el trato de vos. Todo el sistema tutea; usted acá sonaría a otro
 * producto. Si alguien viene a "simplificar" esto sacando el tuteo, está resolviendo un
 * problema que nadie planteó.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
trait TonoDeRedaccionIa
{
    /**
     * Reglas de registro, como líneas "- ..." separadas por salto de línea y SIN salto
     * final. Ese formato es el que ya usan los bloques "Reglas:" de los resúmenes y la
     * sección "Cómo hablás:" del system prompt del chat, así que entra en los dos sin
     * adaptación.
     *
     * @return string
     */
    protected function reglas_de_tono(): string
    {
        return "- Tono amable, claro y directo. Le hablás al dueño del negocio: nada de jerga técnica ni de nombres internos del sistema.\n"
            . "- Cordial pero no informal. Prohibidos los coloquialismos y las expresiones vulgares: nada de \"al pedo\", \"lo picante\", \"posta\", \"capaz\", \"un montón\", \"arrancá con\".\n"
            . "- Nada de muletillas para abrir una oración: \"Mirá\", \"Ahora\", \"Che\", \"Bueno\", \"Estamos hablando de\", \"Te diría que\".\n"
            . "- Cada oración aporta un dato o una recomendación concreta. Sin relleno ni frases de transición.";
    }
}
