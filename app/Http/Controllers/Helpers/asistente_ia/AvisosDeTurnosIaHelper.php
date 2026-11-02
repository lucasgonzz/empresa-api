<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiAvisoDelAsistente;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * LO QUE EL DUEÑO TIENE QUE SABER CUANDO UN TURNO DE WHATSAPP NO TERMINÓ (misión agente-mejoras-uso-real,
 * ronda de arreglos de la revisión independiente, 30/10/2026).
 *
 * 🔴 POR QUÉ HACE FALTA UN AVISO APARTE. Con el mensaje del asistente en `error`, el admin NO lee el
 * contenido: manda su disculpa fija («Uf, no pude contestarte esta vez…», EnviarMensajeAlAsistenteJob
 * del admin-api). Así, el texto del empresa que le decía al dueño «me llegaron 3 fotos y quedaron
 * guardadas: escribime "seguí"» (ResponderMensajeChatIaJob) nunca le llegaba por WhatsApp. Y un turno que
 * se queda en `pendiente` (la cola sin consumidor) tampoco le avisaba nada: el admin deja de esperar a
 * los 180 s, manda la disculpa, y el pedido —«cargame un gasto de 207.500»— queda olvidado.
 *
 * Este helper deja el aviso en `ai_avisos_del_asistente` y AvisosDelAsistenteHelper lo sirve por el
 * camino que el admin YA consulta (avisos-pendientes / avisos/avisado, id `turno_sin_terminar:{uuid}`),
 * el mismo del aviso de «ya leí la factura». Sin tocar el admin.
 *
 * 🔴 TRES GUARDAS, Y NINGUNA LANZA (lo llaman el catch y el failed() del job y el vigía de turnos):
 *   - solo para mensajes del canal WhatsApp (en el sistema y en la app el texto del error se ve);
 *   - solo si el admin de este dueño anunció que consulta los avisos (AvisosDelAsistenteHelper::
 *     admin_avisa): un aviso que nadie va a mandar no se promete;
 *   - UNO por mensaje del asistente: si ya hay uno para ese mensaje, no se deja otro.
 *
 * Desde la misión agente-memoria-y-sin-techo (ronda de arreglos, R1, 30/10/2026) arma también el texto de
 * un turno que se quedó sin vueltas o sin tiempo DESPUÉS de haber hecho algo (texto_de_turno_sin_terminar(),
 * en todos los canales): ese turno ya no termina en `error` sino `listo`, con lo hecho, lo que espera el
 * sí y el «seguí». Las guardas de arriba son solo de dejar() y de los avisos.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class AvisosDeTurnosIaHelper
{
    /** Horas en las que el aviso todavía se manda (la ventana de 24 h de WhatsApp, como los otros avisos). */
    const HORAS_DEL_AVISO = 24;

    /** Cuántos caracteres del pedido del dueño se citan en el aviso de un turno colgado. */
    const LARGO_DE_LA_CITA = 60;

    /**
     * La última frase del texto de un turno que no terminó (texto_de_turno_sin_terminar()): misión
     * agente-memoria-y-sin-techo, ronda de arreglos de la revisión independiente (R1, 30/10/2026). Dice
     * «sigo con lo que falta» y no «desde donde quedó» porque ese mensaje SÍ queda `listo` y viaja en el
     * historial con lo hecho: el «seguí» ve qué se hizo y qué no.
     */
    const CIERRE_SIN_TERMINAR = 'No llegué a terminar esta respuesta. Escribime «seguí» y sigo con lo que falta.';

    /** Encabezado de las cargas que el turno dejó hechas (confirmadas), en texto_de_turno_sin_terminar(). */
    const ENCABEZADO_HECHO = 'Quedó hecho:';

    /** Encabezado de UNA carga que quedó esperando el sí, en texto_de_turno_sin_terminar(). */
    const ENCABEZADO_PARA_CONFIRMAR_UNA = 'Quedó para que la confirmes:';

    /** Encabezado de VARIAS cargas que quedaron esperando el sí, en texto_de_turno_sin_terminar(). */
    const ENCABEZADO_PARA_CONFIRMAR_VARIAS = 'Quedaron para que las confirmes:';

    /*
     * Cómo se confirma lo que quedó esperando el sí (segunda ronda de arreglos de la revisión
     * independiente, F5, 30/10/2026), debajo de esa lista. Sin esto, con tarjetas esperando por WhatsApp
     * el texto solo pedía «seguí», y la persona no sabía si «seguí» las confirmaba: el «sí» confirma, el
     * «seguí» sigue con lo que falta. En el panel y en la app se confirman con el botón de la tarjeta, con
     * las mismas palabras que el resto del sistema («Tocá Confirmar»).
     */

    /** Por texto (WhatsApp), UNA carga esperando el sí. */
    const CONFIRMAR_POR_TEXTO_UNA = 'Si está bien, contestame «sí».';

    /** Por texto (WhatsApp), VARIAS cargas esperando el sí. */
    const CONFIRMAR_POR_TEXTO_VARIAS = 'Si están bien, contestame «sí».';

    /** Con botón (panel, app), UNA tarjeta esperando. */
    const CONFIRMAR_CON_BOTON_UNA = 'Para que quede registrada, tocá Confirmar en la tarjeta.';

    /** Con botón (panel, app), VARIAS tarjetas esperando. */
    const CONFIRMAR_CON_BOTON_VARIAS = 'Para que queden registradas, tocá Confirmar en cada tarjeta.';

    /** Techo de caracteres del renglón de cada carga en texto_de_turno_sin_terminar(). */
    const LARGO_DEL_RENGLON = 300;

    /**
     * Techo de caracteres del motivo de una carga que no se pudo hacer, en su renglón («(no se pudo: …)»,
     * segunda ronda de arreglos, F6). El motivo entero viaja en el historial y en la tarjeta.
     */
    const LARGO_DEL_MOTIVO = 120;

    /**
     * Cuántas cargas se listan como mucho por sección en texto_de_turno_sin_terminar(); las demás van en
     * un «- y N más.». Con el techo de 24 vueltas un turno puede dejar muchas tarjetas, y el texto sale por
     * WhatsApp (un mensaje de 4.096 caracteres como mucho): 10 renglones de 300 entran con margen. Las que
     * no se nombran igual viajan en el historial con su renglón.
     */
    const RENGLONES_POR_SECCION = 10;

    /**
     * Presupuesto de caracteres de las DOS listas juntas de texto_de_turno_sin_terminar() (segunda ronda
     * de arreglos de la revisión independiente, F4, 30/10/2026). El tope de renglones solo no alcanzaba:
     * 10 renglones de 300 por sección pasan los 6.000 caracteres, y por WhatsApp Meta rechaza un mensaje de
     * más de 4.096 (el resto del sistema se queda en DestinatariosIaHelper::LARGO_MAXIMO_DEL_MENSAJE,
     * 3.500). Con 3.000 para las listas, los encabezados, la frase de las fotos, la del «sí» y la del
     * «seguí» entran debajo de 3.500. Lo que no entra va en el «- y N más.».
     */
    const LARGO_MAXIMO_DE_LAS_LISTAS = 3000;

    /** @var bool|null  Si la tabla existe en esta base (se pregunta una vez por proceso). */
    protected static $hay_tabla = null;

    /**
     * Deja el aviso de un turno que no terminó, o null si no corresponde (ver las tres guardas del
     * docblock). Nunca lanza.
     *
     * @param  \App\Models\AiMessage  $assistant  El mensaje del asistente que no terminó.
     * @param  string  $texto  Lo que sale por WhatsApp, tal cual.
     * @return \App\Models\AiAvisoDelAsistente|null
     */
    public static function dejar(AiMessage $assistant, $texto)
    {
        try {

            $texto = trim((string) $texto);

            if ($texto === '' || !$assistant->es_de_whatsapp() || !self::hay_tabla()) {

                return null;
            }

            $conversation = AiConversation::find((int) $assistant->ai_conversation_id);

            if (is_null($conversation) || !AvisosDelAsistenteHelper::admin_avisa((int) $conversation->user_id)) {

                return null;
            }

            /* Uno por mensaje: el job y el vigía no pueden dejar dos para el mismo turno. */
            $ya = AiAvisoDelAsistente::where('ai_message_id', (int) $assistant->id)->first();

            if (!is_null($ya)) {

                return $ya;
            }

            return AiAvisoDelAsistente::create([
                'uuid'               => (string) Str::uuid(),
                'owner_id'           => (int) $conversation->user_id,
                'ai_conversation_id' => (int) $conversation->id,
                'ai_message_id'      => (int) $assistant->id,
                'tipo'               => AiAvisoDelAsistente::TIPO_TURNO_SIN_TERMINAR,
                'texto'              => $texto,
            ]);

        } catch (\Throwable $e) {

            Log::warning('AvisosDeTurnosIaHelper: no se pudo dejar el aviso del turno', [
                'ai_message_id' => (int) $assistant->id,
                'error'         => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Los avisos del dueño que todavía no salieron, de las últimas HORAS_DEL_AVISO horas, del más viejo
     * al más nuevo. Vacío si la tabla no existe (un upgrade a medio correr).
     *
     * @param  \App\Models\User  $dueno
     * @param  int  $tope
     * @return \Illuminate\Support\Collection
     */
    public static function pendientes_de_aviso(User $dueno, $tope)
    {
        if (!self::hay_tabla()) {

            return collect();
        }

        return AiAvisoDelAsistente::where('owner_id', (int) $dueno->id)
                                    ->whereNull('avisado_por_whatsapp_at')
                                    ->where('created_at', '>=', Carbon::now()->subHours(self::HORAS_DEL_AVISO))
                                    ->orderBy('created_at')
                                    ->orderBy('id')
                                    ->limit((int) $tope)
                                    ->get();
    }

    /**
     * La frase de las fotos que llegaron con el pedido y no se cargaron, o vacío si no hay ninguna.
     * Es la misma para el contenido del mensaje en error (lo que se ve en el sistema y queda en el
     * historial) y para el aviso por WhatsApp.
     *
     * 🔴 NO DICE «NO LLEGUÉ A CARGARLAS» SI ALGO SÍ QUEDÓ HECHO EN ESE TURNO (revisión independiente,
     * arreglo 4, 30/10/2026). En "directo" una carga sacada de una de las fotos (un gasto, una venta) se
     * confirma sola en el acto; si después el turno se corta, decir que no se cargó nada lleva al dueño a
     * pedirla otra vez y se duplica. Con tarjetas confirmadas en el mensaje, la frase dice qué quedó hecho
     * (con el texto de su resultado) y que lo demás no.
     *
     * @param  int  $sin_usar  Cuántas fotos del pedido no usó ninguna carga.
     * @param  array<int, string>  $hechos  Lo que quedó hecho en el turno (el resultado de cada tarjeta confirmada).
     * @return string  Sin espacio adelante.
     */
    public static function frase_de_fotos_sin_cargar($sin_usar, array $hechos = [])
    {
        $sin_usar = (int) $sin_usar;

        if ($sin_usar <= 0) {

            return '';
        }

        if (count($hechos)) {

            return 'Alcancé a dejar hecho esto: ' . implode(' ', $hechos) . ' Lo demás no: '
                . ($sin_usar === 1 ? 'la otra foto quedó guardada sin cargar.' : 'las otras ' . $sin_usar . ' fotos quedaron guardadas sin cargar.')
                . ' Escribime «seguí» y continúo con eso.';
        }

        return $sin_usar === 1
            ? 'Me llegó la foto y quedó guardada, pero no llegué a cargarla. Escribime «seguí» y continúo.'
            : 'Me llegaron ' . $sin_usar . ' fotos y quedaron guardadas, pero no llegué a cargarlas. Escribime «seguí» y continúo.';
    }

    /**
     * La frase de las fotos sin cargar de un turno que no terminó (frase_de_fotos_sin_cargar() con las
     * fotos del pedido que ninguna carga usó y lo que SÍ quedó hecho: las tarjetas confirmadas de este
     * mensaje, que el cierre del error no descarta), o vacío. Protegido: si algo falla, vacío.
     *
     * @param  \App\Models\AiMessage  $assistant  El mensaje del asistente que no terminó.
     * @param  \App\Models\AiMessage|null  $pedido  El mensaje del dueño que contestaba.
     * @return string
     */
    public static function frase_del_turno(AiMessage $assistant, $pedido)
    {
        try {

            $sin_usar = $pedido instanceof AiMessage
                ? AiMessageImagen::where('ai_message_id', (int) $pedido->id)->whereNull('gestionada_at')->count()
                : 0;

            $hechos = [];

            foreach (AiMessageAction::where('ai_message_id', (int) $assistant->id)
                                    ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
                                    ->orderBy('id')
                                    ->get() as $accion) {

                $texto = is_object($accion->resultado) && isset($accion->resultado->texto) ? trim((string) $accion->resultado->texto) : '';

                if ($texto !== '') {

                    $hechos[] = rtrim($texto, '.') . '.';
                }
            }

            return self::frase_de_fotos_sin_cargar($sin_usar, $hechos);

        } catch (\Throwable $e) {

            Log::warning('AvisosDeTurnosIaHelper: no se pudieron contar las fotos del turno', [
                'ai_message_id' => (int) $assistant->id,
                'error'         => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * El texto, DETERMINISTA y sin el modelo, de un turno que se quedó sin vueltas o sin tiempo y cuya
     * llamada de cierre falló, volvió vacía o no tuvo ventana (misión agente-memoria-y-sin-techo, ronda de
     * arreglos de la revisión independiente, R1, 30/10/2026). Lo usa AsistenteIaService::
     * respuesta_sin_terminar(), y el mensaje queda `listo` con este texto:
     *
     *   Quedó hecho:
     *   - Pago N° 1706 registrado.
     *
     *   Quedaron para que las confirmes:
     *   - Tarea (Detalle: Cheque 1 se acredita mañana; Fecha: 31/10/2026)
     *   Para que queden registradas, tocá Confirmar en cada tarjeta.   (por WhatsApp: «Si están bien, contestame «sí».»)
     *
     *   Me llegaron 3 fotos y quedaron guardadas.
     *
     *   No llegué a terminar esta respuesta. Escribime «seguí» y sigo con lo que falta.
     *
     * 🔴 POR QUÉ `listo` Y NO EL ERROR DE TIEMPO AGOTADO. Con el mensaje en `error`: (a) no viaja en el
     * historial (build_messages_payload() lee solo los `listo`), así que ante el «seguí» el modelo no veía
     * las cargas que ese turno YA hizo —en "directo" quedan confirmadas solas— y las podía repetir; (b) por
     * WhatsApp el admin no manda el contenido de un mensaje en error sino su disculpa fija («Uf, no pude
     * contestarte…», EnviarMensajeAlAsistenteJob), así que el «seguí» nunca le llegaba al dueño. `listo`,
     * el texto llega por los dos canales, y las tarjetas viajan en el historial con su renglón
     * (AccionesIaHelper::linea_de_historial) junto a este texto.
     *
     * Las tarjetas que esperan el sí NO se descartan (eso lo hace el job solo con un mensaje en error):
     * siguen confirmables con el botón o con el «sí» por texto.
     *
     * LAS FOTOS. El aviso de fotos sin usar (frase_del_turno(), que el job suma al texto de un error y deja
     * como aviso por WhatsApp) solo corre con un mensaje en `error`. Con este texto, las fotos van en una
     * frase propia y neutra («quedaron guardadas»): no dice «no llegué a cargarlas» porque alguna puede
     * estar en una tarjeta que espera el sí, y el «seguí» las encuentra igual (las fotos sin usar de la
     * última tanda vuelven a viajar con el mensaje siguiente).
     *
     * Nunca lanza: si algo falla, queda solo la última frase.
     *
     * @param  \App\Models\AiMessage  $assistant  El mensaje del asistente que no terminó.
     * @param  \App\Models\AiMessage|null  $pedido  El mensaje del dueño que contestaba.
     * @param  int|null  $tarjeta_confirmada_aparte  La tarjeta de un mensaje ANTERIOR que este turno confirmó
     *                                               antes de llamar al modelo (la confirmación determinista
     *                                               del «sí»), o null. Va primera entre lo hecho.
     * @return string
     */
    public static function texto_de_turno_sin_terminar(AiMessage $assistant, $pedido, $tarjeta_confirmada_aparte = null)
    {
        try {

            $hechos = [];
            $para_confirmar = [];

            /* Las tarjetas de mensajes ANTERIORES que ya van en «Quedó hecho» (para no repetirlas). */
            $ya_contadas = [];

            if (!is_null($tarjeta_confirmada_aparte) && (int) $tarjeta_confirmada_aparte > 0) {

                $aparte = AiMessageAction::where('id', (int) $tarjeta_confirmada_aparte)
                                         ->where('ai_conversation_id', (int) $assistant->ai_conversation_id)
                                         ->first();

                if (!is_null($aparte) && (string) $aparte->estado === AiMessageAction::ESTADO_CONFIRMADA && (int) $aparte->ai_message_id !== (int) $assistant->id) {

                    $hechos[] = self::renglon_para_la_persona($aparte);
                    $ya_contadas[] = (int) $aparte->id;
                }
            }

            /*
             * Segunda ronda de arreglos (F6): las tarjetas de mensajes ANTERIORES de esta conversación que se
             * confirmaron mientras corría este turno (confirmar_carga_pendiente sobre una tarjeta que había
             * dejado otra respuesta, o el botón Confirmar tocado mientras tanto): `resuelta_at` desde el alta
             * de este mensaje. Están hechas, y si el turno no termina la persona tiene que saberlo igual que
             * las de este mensaje (en el historial viajan colgadas del mensaje que las propuso).
             */
            if (!is_null($assistant->created_at)) {

                $durante_el_turno = AiMessageAction::where('ai_conversation_id', (int) $assistant->ai_conversation_id)
                                                   ->where('ai_message_id', '<', (int) $assistant->id)
                                                   ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
                                                   ->where('resuelta_at', '>=', Carbon::parse($assistant->created_at))
                                                   ->orderBy('resuelta_at')
                                                   ->orderBy('id')
                                                   ->get();

                foreach ($durante_el_turno as $accion) {

                    if (in_array((int) $accion->id, $ya_contadas, true)) {
                        continue;
                    }

                    $hechos[] = self::renglon_para_la_persona($accion);
                    $ya_contadas[] = (int) $accion->id;
                }
            }

            foreach (AiMessageAction::where('ai_message_id', (int) $assistant->id)->orderBy('id')->get() as $accion) {

                $estado = (string) $accion->estado;

                if ($estado === AiMessageAction::ESTADO_CONFIRMADA) {

                    $hechos[] = self::renglon_para_la_persona($accion);

                } elseif ($estado === AiMessageAction::ESTADO_PROPUESTA && !$accion->vencio()) {

                    $para_confirmar[] = self::renglon_para_la_persona($accion);
                }
            }

            $partes = [];

            /* F4: lo que les queda a las listas (las dos comparten LARGO_MAXIMO_DE_LAS_LISTAS). */
            $presupuesto = self::LARGO_MAXIMO_DE_LAS_LISTAS;

            if (count($hechos)) {

                $partes[] = self::ENCABEZADO_HECHO . self::lista($hechos, $presupuesto);
            }

            if (count($para_confirmar)) {

                $una = count($para_confirmar) === 1;

                /* F5: cómo se confirman, según el canal (por texto el «sí», con botón la tarjeta). */
                $como = $assistant->confirma_por_texto()
                    ? ($una ? self::CONFIRMAR_POR_TEXTO_UNA : self::CONFIRMAR_POR_TEXTO_VARIAS)
                    : ($una ? self::CONFIRMAR_CON_BOTON_UNA : self::CONFIRMAR_CON_BOTON_VARIAS);

                $partes[] = ($una ? self::ENCABEZADO_PARA_CONFIRMAR_UNA : self::ENCABEZADO_PARA_CONFIRMAR_VARIAS)
                    . self::lista($para_confirmar, $presupuesto)
                    . "\n" . $como;
            }

            $fotos = $pedido instanceof AiMessage
                ? AiMessageImagen::where('ai_message_id', (int) $pedido->id)->whereNull('gestionada_at')->count()
                : 0;

            if ($fotos > 0) {

                $partes[] = $fotos === 1
                    ? 'Me llegó la foto y quedó guardada.'
                    : 'Me llegaron ' . $fotos . ' fotos y quedaron guardadas.';
            }

            $partes[] = self::CIERRE_SIN_TERMINAR;

            return implode("\n\n", $partes);

        } catch (\Throwable $e) {

            Log::warning('AvisosDeTurnosIaHelper: no se pudo armar el texto del turno sin terminar; va solo el cierre', [
                'ai_message_id' => (int) $assistant->id,
                'error'         => $e->getMessage(),
            ]);

            return self::CIERRE_SIN_TERMINAR;
        }
    }

    /**
     * El renglón de una carga como lo lee la PERSONA en texto_de_turno_sin_terminar(): si está confirmada
     * y tiene resultado, el texto del resultado («Pago N° 1706 registrado.»); si no, el título de la
     * tarjeta con sus renglones entre paréntesis («Tarea (Detalle: …; Fecha: …)»). No es
     * AccionesIaHelper::linea_de_historial() a propósito: aquélla es para el modelo («[Tarjeta #12 · … ·
     * estado: …]»), viaja igual en el historial colgada del mensaje, y repetida en el texto la vería dos
     * veces (y DeepSeek ya copia esas líneas en sus respuestas).
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @return string
     */
    protected static function renglon_para_la_persona(AiMessageAction $accion)
    {
        if ((string) $accion->estado === AiMessageAction::ESTADO_CONFIRMADA && is_object($accion->resultado) && isset($accion->resultado->texto)) {

            $resultado = trim(preg_replace('/\s+/u', ' ', (string) $accion->resultado->texto));

            if ($resultado !== '') {

                return self::recortar_renglon(rtrim($resultado, '. ') . '.');
            }
        }

        $presentacion = is_array($accion->presentacion) ? $accion->presentacion : [];

        $titulo = isset($presentacion['titulo']) ? trim((string) $presentacion['titulo']) : '';
        $titulo = $titulo === '' ? (string) $accion->tipo : $titulo;

        $renglones = [];

        if (isset($presentacion['renglones']) && is_array($presentacion['renglones'])) {

            foreach ($presentacion['renglones'] as $renglon) {

                if (is_array($renglon) && isset($renglon['etiqueta'])) {

                    $valor = isset($renglon['valor']) && is_scalar($renglon['valor']) ? trim((string) $renglon['valor']) : '';

                    $renglones[] = trim((string) $renglon['etiqueta']) . ($valor === '' ? '' : ': ' . $valor);
                }
            }
        }

        $renglon = count($renglones) ? $titulo . ' (' . implode('; ', $renglones) . ')' : $titulo;

        /*
         * Segunda ronda de arreglos (F6): una tarjeta que sigue esperando PERO cuyo último intento de
         * confirmarla falló (en "directo", la ejecución automática que no se pudo) dice por qué, corto: si
         * no, en la lista de lo que espera el sí parece una más, lista para confirmar, y la persona no
         * sabe que ya se intentó.
         */
        $motivo = (string) $accion->estado === AiMessageAction::ESTADO_PROPUESTA
            ? trim(preg_replace('/\s+/u', ' ', (string) $accion->error_mensaje))
            : '';

        if ($motivo !== '') {

            $motivo = rtrim($motivo, '. ');
            $motivo = mb_strlen($motivo) > self::LARGO_DEL_MOTIVO ? rtrim(mb_substr($motivo, 0, self::LARGO_DEL_MOTIVO - 1)) . '…' : $motivo;

            /* El motivo no se pierde aunque el renglón sea largo: se recorta el renglón, no el motivo. */
            $sufijo = ' (no se pudo: ' . $motivo . ')';

            return self::recortar_renglon_con_sufijo($renglon, $sufijo);
        }

        return self::recortar_renglon($renglon);
    }

    /**
     * Un renglón con un sufijo que tiene que quedar entero («(no se pudo: …)»): se recorta el renglón para
     * que los dos juntos entren en LARGO_DEL_RENGLON.
     *
     * @param  string  $renglon
     * @param  string  $sufijo
     * @return string
     */
    protected static function recortar_renglon_con_sufijo($renglon, $sufijo)
    {
        $lugar = max(20, self::LARGO_DEL_RENGLON - mb_strlen($sufijo));

        $renglon = trim(preg_replace('/\s+/u', ' ', (string) $renglon));

        if (mb_strlen($renglon) > $lugar) {
            $renglon = rtrim(mb_substr($renglon, 0, $lugar - 1)) . '…';
        }

        return $renglon . $sufijo;
    }

    /**
     * Los renglones de una sección, uno por línea con «- » adelante (empieza con el salto de línea), con
     * RENGLONES_POR_SECCION como mucho, mientras entren en lo que queda del presupuesto de caracteres de
     * las listas (F4: LARGO_MAXIMO_DE_LAS_LISTAS, que comparten las dos secciones), y un «- y N más.» con
     * los que no entraron. Siempre va al menos el primero (recortado a LARGO_DEL_RENGLON ya viene): una
     * sección que solo dijera «y 3 más» no diría nada.
     *
     * @param  array<int, string>  $renglones
     * @param  int  $presupuesto  Lo que les queda a las listas; se descuenta lo que usa esta.
     * @return string
     */
    protected static function lista(array $renglones, &$presupuesto)
    {
        $lista = '';
        $puestos = 0;

        foreach ($renglones as $renglon) {

            $linea = "\n- " . $renglon;
            $largo = mb_strlen($linea);

            if ($puestos >= self::RENGLONES_POR_SECCION || ($puestos > 0 && $largo > $presupuesto)) {
                break;
            }

            $lista .= $linea;
            $presupuesto -= $largo;
            $puestos++;
        }

        $sobran = count($renglones) - $puestos;

        return $sobran > 0 ? $lista . "\n- y " . $sobran . ' más.' : $lista;
    }

    /**
     * Un renglón en una sola línea y de LARGO_DEL_RENGLON caracteres como mucho.
     *
     * @param  string  $texto
     * @return string
     */
    protected static function recortar_renglon($texto)
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string) $texto));

        return mb_strlen($texto) > self::LARGO_DEL_RENGLON ? rtrim(mb_substr($texto, 0, self::LARGO_DEL_RENGLON - 1)) . '…' : $texto;
    }

    /**
     * El aviso de un turno que quedó colgado y cerró el vigía: cita lo que pidió el dueño y cuándo, y
     * pregunta si lo retoma. «No te pude contestar "cargame un gasto de 207.500…" (28/10 18:28). ¿Lo
     * retomo?». Si el pedido eran solo fotos, lo dice; si quedaron fotos sin cargar, también.
     *
     * @param  \App\Models\AiMessage|null  $pedido  El mensaje del dueño que el turno contestaba.
     * @return string
     */
    public static function texto_de_turno_colgado($pedido)
    {
        if (!($pedido instanceof AiMessage)) {

            return 'No te pude contestar tu último mensaje. ¿Lo retomo?';
        }

        $cuando = is_null($pedido->created_at) ? '' : ' (' . Carbon::parse($pedido->created_at)->format('d/m H:i') . ')';

        $texto = trim(preg_replace('/\s+/u', ' ', (string) $pedido->contenido));

        $fotos = AiMessageImagen::where('ai_message_id', (int) $pedido->id)->count();

        if ($texto === '') {

            $que = $fotos > 0
                ? ($fotos === 1 ? 'la foto que me mandaste' : 'las ' . $fotos . ' fotos que me mandaste')
                : 'tu último mensaje';

            return 'No te pude contestar ' . $que . $cuando . '. ¿Lo retomo?';
        }

        /* Cortada en la última palabra entera que entra: «cuota re…» no se lee. */
        $cita = $texto;

        if (mb_strlen($texto) > self::LARGO_DE_LA_CITA) {

            $corte = mb_substr($texto, 0, self::LARGO_DE_LA_CITA);
            $espacio = mb_strrpos($corte, ' ');

            $cita = rtrim(($espacio !== false && $espacio > self::LARGO_DE_LA_CITA / 2) ? mb_substr($corte, 0, $espacio) : $corte, ' ,.;:') . '…';
        }

        $sin_usar = AiMessageImagen::where('ai_message_id', (int) $pedido->id)->whereNull('gestionada_at')->count();

        return 'No te pude contestar "' . $cita . '"' . $cuando . '. ¿Lo retomo?'
            . ($sin_usar > 0 ? ' ' . ($sin_usar === 1 ? 'La foto quedó guardada.' : 'Las ' . $sin_usar . ' fotos quedaron guardadas.') : '');
    }

    /**
     * true si la tabla de los avisos existe en esta base. Se pregunta una vez por proceso: un empresa con
     * la migración sin correr no se cae, simplemente no deja avisos.
     *
     * @return bool
     */
    public static function hay_tabla()
    {
        if (is_null(self::$hay_tabla)) {

            try {

                self::$hay_tabla = Schema::hasTable('ai_avisos_del_asistente');

            } catch (\Throwable $e) {

                return false;
            }
        }

        return (bool) self::$hay_tabla;
    }
}
