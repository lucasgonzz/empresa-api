<?php

namespace App\Services\AsistenteIa;

use App\Http\Controllers\Helpers\asistente_ia\AdjuntosIaHelper;
use Illuminate\Support\Str;

/**
 * `ofrecer_soporte`: el asistente personal de la prueba gratuita le ofrece al lead pasar una duda de
 * uso a Soporte (misión prueba-ayuda-unificada-en-soporte, E1, 10/11/2026).
 *
 * Decisión de Lucas: en la prueba toda la guía la da Soporte (Martín), que ve el sistema del cliente y
 * puede armarle un tour. Cuando el lead le pregunta al asistente «cómo hago X», el asistente contesta
 * corto con el manual y le ofrece seguir con Soporte. Esta herramienta es esa oferta, y NADA MÁS.
 *
 * 🔴 NO MANDA NADA. Solo deja en la respuesta un adjunto `{tipo: 'soporte', texto: <pregunta>}` que el
 * SPA dibuja como el botón «Seguir con Soporte». El envío a Soporte lo hace el lead con un clic, por el
 * mismo camino del compositor de Soporte. Que la IA abriera sola un ticket sería mandarle a Martín algo
 * que el lead no pidió (y gastarle un turno de Codex).
 *
 * La registra AsistenteIaService solo en la prueba, con `support_chat` y en el canal del sistema: en
 * WhatsApp o en la app no hay botón que tocar.
 */
class HerramientaDeOfrecerSoporte
{
    const NOMBRE = 'ofrecer_soporte';

    public static function definicion(): array
    {
        return [
            'name' => self::NOMBRE,
            'description' => 'Le ofrece a la persona pasar su duda de uso del sistema a Soporte, que ve su sistema y le puede armar un tour guiado. No manda nada: deja en tu respuesta un botón «Seguir con Soporte» que la persona toca si quiere. Usala cuando pregunte cómo se hace algo, dónde está una pantalla o cómo se usa una función, después de contestarle corto. Una sola vez por respuesta.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'pregunta' => [
                        'type' => 'string',
                        'description' => 'La duda de la persona, en primera persona y como ella la diría, para que Soporte la lea tal cual. Por ejemplo: «¿Cómo cargo una compra a un proveedor?». Sin datos del negocio que no hagan falta.',
                    ],
                ],
                'required' => ['pregunta'],
            ],
        ];
    }

    /**
     * El tool_result: el adjunto de la respuesta y la instrucción de cómo seguir. Lo junta
     * AsistenteIaService::juntar_adjuntos(), igual que las fotos de los artículos.
     *
     * @param  array  $entrada  Lo que mandó el modelo (`pregunta`).
     * @return array{adjuntos_de_la_respuesta?: array, como_sigo?: string, error?: string}
     */
    public static function ejecutar(array $entrada): array
    {
        $pregunta = isset($entrada['pregunta']) && is_scalar($entrada['pregunta']) && !is_bool($entrada['pregunta'])
            ? trim((string) $entrada['pregunta'])
            : '';

        if ($pregunta === '') {
            return ['error' => 'Falta la pregunta: pasá la duda de la persona en `pregunta`.'];
        }

        return [
            'adjuntos_de_la_respuesta' => AdjuntosIaHelper::normalizar([[
                'tipo'  => AdjuntosIaHelper::TIPO_SOPORTE,
                'texto' => $pregunta,
                /*
                 * El `mensaje_uuid` del envío a Soporte, aleatorio y fijado acá, una vez por oferta: el
                 * reintento del botón, el del globo y otro dispositivo mandan el mismo y no duplican la
                 * pregunta. Aleatorio y no derivado del id del mensaje, porque el admin guarda ese uuid
                 * UNIQUE entre todos los clientes y los ids se repiten entre instancias.
                 */
                'uuid'  => (string) Str::uuid(),
            ]]),
            'como_sigo' => 'La oferta ya queda como un botón «Seguir con Soporte» debajo de tu respuesta: no se mandó nada todavía. Cerrá tu respuesta preguntándole si quiere que Soporte le arme un tour, sin prometer horarios ni decir que ya se lo pasaste.',
        ];
    }
}
