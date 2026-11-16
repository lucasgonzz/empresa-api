<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Esos», «estos», «los dos»: la referencia se resuelve contra la ÚLTIMA respuesta del asistente (misión
 * asistente-diario-20261116, rama C, ítem 31/10 #5).
 *
 * 🔴 EL CASO REAL (Ferre Total, conversación 33, mensajes 325-327, 31/10/2026). El agente le avisó a Pablo
 * que el taladro y el reflector habían quedado con stock negativo; Pablo contestó «Poné el stock de esos
 * productos en 0». El agente le habló de una foto vieja («no llego a ver qué producto es ese código 10354»),
 * le pidió decir a qué productos y le listó «seis productos» que eran siete. El ajuste no se hizo. «Esos» eran
 * los dos que acababa de nombrar.
 *
 * Lo que hace nota(): si el mensaje que se contesta usa una referencia así y hay una respuesta anterior del
 * asistente, arma una nota del sistema que viaja pegada a ese mensaje (como la de la confirmación
 * determinista) diciéndole al modelo a qué se refiere: los ARTÍCULOS del negocio cuyo nombre aparece en esa
 * respuesta, con su id, o —si no se reconoce ninguno— el recorte de esa respuesta. Y le dice que arme la carga
 * directo, sin preguntar cuáles. Nunca confirma nada: arma la referencia; la tarjeta sigue su camino de
 * siempre (y lo que espera el sí, lo sigue esperando).
 *
 * Nunca lanza: si algo falla, no hay nota y el turno sigue como antes.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class ReferenciaALoAnteriorIaHelper
{
    /**
     * Las referencias que se resuelven, ya normalizadas (minúsculas, sin tildes), como palabras enteras. Lista
     * CERRADA: «eso» o «ese» solos no entran (casi siempre son otra cosa: «eso es todo», «ese día»).
     */
    const REFERENCIAS = [
        'esos', 'esas', 'estos', 'estas', 'los dos', 'las dos', 'los tres', 'las tres', 'ambos', 'ambas',
        'los mismos', 'las mismas', 'aquellos', 'aquellas',
    ];

    /** Cuántos artículos nombrados se le pasan al modelo como mucho. */
    const MAX_ARTICULOS = 10;

    /** Largo mínimo del nombre de un artículo para buscarlo adentro del texto («Pala» sí, «X» no). */
    const LARGO_MINIMO_DEL_NOMBRE = 4;

    /** Largo del recorte de la respuesta anterior cuando no se reconoce ningún artículo. */
    const LARGO_DEL_RECORTE = 500;

    /**
     * La nota para el mensaje que contesta este assistant, o null si no usa ninguna referencia o no hay
     * respuesta anterior a la que referirse.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message  El assistant que se está generando.
     * @return string|null
     */
    public static function nota(AiConversation $conversation, AiMessage $assistant_message)
    {
        try {

            $pedido = AiMessage::where('ai_conversation_id', $conversation->id)
                                ->where('rol', 'user')
                                ->where('id', '<', $assistant_message->id)
                                ->orderBy('id', 'DESC')
                                ->first();

            if (is_null($pedido) || !self::usa_una_referencia($pedido->contenido)) {

                return null;
            }

            $anterior = AiMessage::where('ai_conversation_id', $conversation->id)
                                    ->where('rol', 'assistant')
                                    ->where('estado', 'listo')
                                    ->where('id', '<', $pedido->id)
                                    ->orderBy('id', 'DESC')
                                    ->first();

            $texto = is_null($anterior) ? '' : trim((string) $anterior->contenido);

            if ($texto === '') {

                return null;
            }

            $referencia = self::referencia_usada($pedido->contenido);

            $articulos = self::articulos_nombrados((int) $conversation->user_id, $texto);

            if (count($articulos)) {

                $lista = [];

                foreach ($articulos as $articulo) {

                    $lista[] = $articulo['nombre'].' (articulo_id '.$articulo['id'].')';
                }

                return '[Referencia del sistema: «'.$referencia.'» en este mensaje son los que nombraste en tu ÚLTIMA respuesta, '
                    .'no los de una foto ni de un pedido anterior. Artículos de esa respuesta: '.implode('; ', $lista).'. '
                    .'Si el pedido es claro (poner el stock en 0, cambiar un precio), armá la carga directo con esos, sin preguntar cuáles. '
                    .'Si nombraste más de los que encajan con el pedido, usá los que encajan; preguntá solo si de verdad no se puede saber.]';
            }

            return '[Referencia del sistema: «'.$referencia.'» en este mensaje se refiere a lo que nombraste en tu ÚLTIMA respuesta, '
                .'no a una foto ni a un pedido anterior. Tu última respuesta empezaba así: «'.mb_substr($texto, 0, self::LARGO_DEL_RECORTE).'». '
                .'Buscá esos registros por su nombre y armá la carga directo, sin preguntar cuáles; preguntá solo si de verdad no se puede saber.]';

        } catch (\Throwable $e) {

            Log::warning('ReferenciaALoAnteriorIaHelper: no se pudo resolver la referencia; sigue sin nota', [
                'ai_conversation_id' => (int) $conversation->id,
                'error'              => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Los verbos de CARGA, como raíz (cubre «poné», «ponele», «ponelos», «cambiá», «cambiales»…), ya sin tildes.
     * Revisión de la rama C (M1): sin uno de éstos el mensaje no es un pedido sobre «esos», y «¿cómo estás?»,
     * «estas ahí?» o «que tal, como estas» no son referencias.
     */
    const VERBOS_DE_CARGA = [
        'pon', 'cambi', 'sub', 'baj', 'pas', 'carg', 'borr', 'sac', 'dej', 'actualiz', 'agreg', 'elimin',
        'ajust', 'modific', 'correg', 'corregi', 'asign', 'activ', 'desactiv', 'anot', 'registr', 'mand',
    ];

    /**
     * true si el texto usa alguna de las REFERENCIAS como palabras enteras Y trae un verbo de carga.
     *
     * @param  string|null  $texto
     * @return bool
     */
    public static function usa_una_referencia($texto)
    {
        return self::referencia_usada($texto) !== '';
    }

    /**
     * true si alguna palabra del texto (sin tildes) empieza con un VERBOS_DE_CARGA.
     *
     * @param  string  $texto
     * @return bool
     */
    protected static function tiene_verbo_de_carga($texto)
    {
        foreach (explode(' ', self::normalizar((string) $texto)) as $palabra) {

            foreach (self::VERBOS_DE_CARGA as $raiz) {

                if (mb_strpos($palabra, $raiz) === 0) {

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * La primera referencia que aparece en el texto, o '' si no hay ninguna (o si no hay verbo de carga).
     *
     * 🔴 Las tildes se miran ANTES de normalizar (revisión de la rama C, M1): «estás» es el verbo («¿cómo
     * estás?», «¿estás seguro?») y no «estas». Por eso la búsqueda va sobre el texto en minúsculas CON sus
     * tildes: REFERENCIAS no tiene ninguna, así que «estás» nunca encaja.
     *
     * @param  string|null  $texto
     * @return string
     */
    protected static function referencia_usada($texto)
    {
        if (!self::tiene_verbo_de_carga((string) $texto)) {

            return '';
        }

        $con_tildes = mb_strtolower(trim((string) $texto));
        $con_tildes = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $con_tildes);
        $normalizado = ' '.trim(preg_replace('/\s+/u', ' ', (string) $con_tildes)).' ';

        foreach (self::REFERENCIAS as $referencia) {

            if (mb_strpos($normalizado, ' '.$referencia.' ') !== false) {

                return $referencia;
            }
        }

        return '';
    }

    /**
     * Los artículos ACTIVOS del dueño cuyo nombre aparece entero adentro del texto, los nombres más largos
     * primero y sin los que están contenidos en otro ya encontrado («Taladro» adentro de «Taladro Percutor»).
     *
     * Se le pregunta a la base «¿este nombre está adentro de este texto?» (LOCATE), acotado al dueño: solo
     * corre cuando el mensaje usa una referencia, y no carga el catálogo en memoria.
     *
     * @param  int  $owner_id
     * @param  string  $texto  La respuesta anterior del asistente.
     * @return array<int, array{id: int, nombre: string}>
     */
    public static function articulos_nombrados($owner_id, $texto)
    {
        // Sin el formato de WhatsApp (*negrita*, _cursiva_): el nombre tiene que poder aparecer seguido.
        $limpio = mb_strtolower(str_replace(['*', '_'], '', (string) $texto));

        $filas = DB::table('articles')
                    ->where('user_id', (int) $owner_id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->whereRaw('CHAR_LENGTH(TRIM(name)) >= ?', [self::LARGO_MINIMO_DEL_NOMBRE])
                    ->whereRaw('LOCATE(LOWER(TRIM(name)), ?) > 0', [$limpio])
                    ->orderByRaw('CHAR_LENGTH(name) DESC')
                    ->limit(self::MAX_ARTICULOS * 2)
                    ->get(['id', 'name']);

        $encontrados = [];

        foreach ($filas as $fila) {

            $nombre = trim((string) $fila->name);
            $contenido_en_otro = false;

            foreach ($encontrados as $ya) {

                if (mb_strpos(mb_strtolower($ya['nombre']), mb_strtolower($nombre)) !== false) {

                    $contenido_en_otro = true;
                    break;
                }
            }

            if (!$contenido_en_otro) {

                $encontrados[] = ['id' => (int) $fila->id, 'nombre' => $nombre];
            }

            if (count($encontrados) >= self::MAX_ARTICULOS) {

                break;
            }
        }

        return $encontrados;
    }

    /**
     * Minúsculas, sin tildes, sin signos y un solo espacio entre palabras.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function normalizar($texto)
    {
        $texto = strtr(mb_strtolower(trim($texto)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);

        $texto = preg_replace('/[^a-z0-9ñ]+/u', ' ', $texto);

        return trim(preg_replace('/\s+/', ' ', (string) $texto));
    }
}
