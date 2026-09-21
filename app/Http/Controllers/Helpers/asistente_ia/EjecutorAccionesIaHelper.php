<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Confirmar y cancelar una tarjeta de carga del asistente de IA (misión asistente-ia-acciones, §3.6
 * del plan). Lo llaman los endpoints `POST ai-conversations/{id}/acciones/{accion_id}/confirmar` y
 * `.../cancelar`, en el request del clic y autenticados COMO LA PERSONA: la decisión es humana, los
 * permisos se chequean contra quien confirma, y los helpers de plata (correlativos, employee_id del
 * pago, empleado del movimiento de caja) leen la sesión.
 *
 * Devuelve `['status' => int, 'body' => array]` con el contrato §2.4/§2.5; la tenencia de la
 * conversación ya la resolvió el controller (conversacion_de_la_persona()).
 *
 * Misión asistente-por-whatsapp (16/9/2026): por WhatsApp no hay botón, así que el mismo
 * confirmar()/cancelar() lo llama ConfirmacionPorTextoIaHelper desde adentro del job — con la
 * persona puesta en Auth, porque los helpers de plata la leen de ahí — cuando el dueño contesta que
 * sí por texto. La lógica no cambia: lo que cambia es quién aprieta el botón.
 */
class EjecutorAccionesIaHelper {

    const MENSAJE_ERROR_GENERICO = 'No se pudo registrar. Probá de nuevo en unos segundos.';

    const MENSAJE_NO_ENCONTRADA = 'Tarjeta no encontrada.';

    const MENSAJE_RESUELTA = 'Esta tarjeta ya no se puede confirmar ni cancelar.';

    const MENSAJE_VENCIDA = 'Esta tarjeta venció. Si todavía querés cargarlo, pedíselo de nuevo al asistente.';

    const MENSAJE_DESCARTADA = 'Esta tarjeta quedó descartada.';

    const MENSAJE_EN_CURSO = 'Esta tarjeta se está registrando en este momento.';

    /**
     * 🔴 LAS CARGAS QUE SE EJECUTAN CON LA TRANSACCIÓN DEL EJECUTOR YA CERRADA (misión
     * asistente-omnisciente, 21/9/2026; arreglo de los hallazgos 🔴 2 y 🔴 3 del chequeo
     * adversarial).
     *
     * Las cuatro son las que llaman al MISMO controller que atiende la pantalla, y esos
     * controllers tienen adentro efectos que NO se pueden deshacer con un rollback:
     *
     *   - `CategoryController` (alta, edición y baja de categorías y subcategorías) llama
     *     SÍNCRONO a Tienda Nube. Con la transacción del ejecutor abierta, un rollback posterior
     *     dejaba la categoría creada allá y no acá; en la baja, borrada allá y viva acá. Y la
     *     transacción quedaba abierta, con el candado tomado, todo lo que tardara ese HTTP.
     *   - `SaleController::store()` abre su propia transacción, y al terminar suelta el
     *     `RELEASE_LOCK` del candado anti-duplicados, despacha `SendSaleWhatsappJob` y emite el
     *     evento de la demo. Anidado adentro de la transacción del ejecutor, su `DB::commit()`
     *     sólo decrementaba el contador (`ManagesTransactions`: el commit real es el del de
     *     afuera): el candado se soltaba y los jobs salían ANTES de que la venta existiera para
     *     nadie más. El escenario que abre es el que el candado existe para cerrar: el dueño
     *     confirma desde el chat mientras se guarda la misma venta desde Vender, el request de la
     *     pantalla toma el candado libre, no ve la venta sin comitear y la duplica (le pasó a
     *     Panchito; está escrito en el docblock del candado).
     *
     * El resto de los tipos —gasto, pago, tareas, combo, oferta, compra con factura, foto de
     * sucursal, imágenes, masiva, PDF— queda EXACTAMENTE como estaba: una sola transacción que
     * envuelve el candado, la ejecución y el cierre de la tarjeta.
     *
     * @var array<int, string>
     */
    const TIPOS_DE_DOS_ETAPAS = [
        AiMessageAction::TIPO_ALTA,
        AiMessageAction::TIPO_EDICION,
        AiMessageAction::TIPO_BAJA,
        AiMessageAction::TIPO_VENTA,
    ];

    /**
     * Ejecuta la carga de la tarjeta y la deja 'confirmada' con su resultado.
     *
     * 🔴 CANDADO CONTRA EL SEGUNDO CLIC. Todo corre en una transacción que empieza con
     * `lockForUpdate` sobre la fila de la tarjeta: un doble clic o dos pestañas llegan los dos acá, el
     * segundo espera el candado, y cuando entra ya la ve 'confirmada' y sale por 409 sin volver a
     * ejecutar nada. Sin eso, los dos verían 'propuesta' y registrarían el gasto dos veces (la clase
     * "la operación que mueve plata sin candado contra el segundo clic" de APRENDER_NO_PARCHEAR.md).
     *
     * 🔴 LO QUE TIENE QUE SOBREVIVIR AL ROLLBACK SE ESCRIBE AFUERA DE LA TRANSACCIÓN. Un 422 (caja
     * nunca abierta, sin permiso, tarea editada en el medio...) revierte todo lo que la carga alcanzó
     * a escribir, y si el `error_mensaje` se guardara adentro, el mismo rollback se lo llevaría: la
     * tarjeta quedaría sin decir por qué no se pudo. Por eso la excepción sale de la transacción y
     * recién en el catch, ya revertida, se persisten el `error_mensaje` y el paso a 'vencida' o
     * 'descartada'. La SPA muestra `model.error_mensaje` (422) y `model.estado` (409), así que esas
     * escrituras van ANTES de armar la respuesta.
     *
     * 🔴 Adentro del try de la escritura no hay broadcast ni notificación: un aviso que falla después
     * de que la carga quedó bien volvería 500 algo que ya está registrado (clase "el aviso que voltea
     * la operación que ya terminó").
     *
     * 🔴 Y HAY UN SEGUNDO CAMINO, para las cargas que llaman al controller de la pantalla (el ABM
     * genérico y la venta): ésas se ejecutan con la transacción ya cerrada, en dos etapas. Todo
     * está en TIPOS_DE_DOS_ETAPAS y en ejecutar_en_dos_etapas(). Lo de este docblock vale tal cual
     * para todas las demás.
     *
     * @param  \App\Models\AiConversation  $conversation  Conversación de la persona autenticada.
     * @param  int  $accion_id
     * @param  \App\Models\User|null  $persona  La persona autenticada.
     * @param  callable  $num_expense_resolver  Controller::num('expenses'), para el correlativo del gasto.
     * @return array  ['status' => int, 'body' => array]
     */
    static function confirmar(AiConversation $conversation, $accion_id, $persona, $num_expense_resolver) {

        return self::ejecutar_confirmacion($conversation, $accion_id, $persona, $num_expense_resolver, true);
    }

    /**
     * Igual que confirmar(), pero SIN exigir que el mensaje que propuso la tarjeta esté 'listo'
     * (misión foto-sucursal-y-asistente-configurable, 17/9/2026).
     *
     * 🔴 ES SOLO PARA LA AUTO-EJECUCIÓN DEL AGENTE EN MODO "RESUELTO". Ahí la tarjeta se confirma
     * DENTRO del turno que la propone, con el assistant todavía 'pendiente' a propósito (el loop no
     * terminó de escribir la respuesta). La guarda de 'listo' que sí aplica a la confirmación humana
     * —botón o texto— rechazaría este caso con un 404. Lo llama ConfirmacionPorTextoIaHelper::
     * confirmar_del_agente(), que ya autenticó a la persona; las demás guardas (propuesta, no
     * vencida, mensaje no en error) siguen valiendo.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  int  $accion_id
     * @param  \App\Models\User|null  $persona
     * @param  callable  $num_expense_resolver
     * @return array  ['status' => int, 'body' => array]
     */
    static function confirmar_en_el_turno(AiConversation $conversation, $accion_id, $persona, $num_expense_resolver) {

        return self::ejecutar_confirmacion($conversation, $accion_id, $persona, $num_expense_resolver, false);
    }

    /**
     * El cuerpo compartido de confirmar() y confirmar_en_el_turno(): la transacción con candado que
     * ejecuta la carga y deja la tarjeta 'confirmada' con su resultado. Ver el docblock de arriba
     * (que era el de confirmar()) para el candado, el rollback y el broadcast.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  int  $accion_id
     * @param  \App\Models\User|null  $persona
     * @param  callable  $num_expense_resolver
     * @param  bool  $exige_mensaje_listo  false solo para la auto-ejecución del agente.
     * @return array  ['status' => int, 'body' => array]
     */
    protected static function ejecutar_confirmacion(AiConversation $conversation, $accion_id, $persona, $num_expense_resolver, $exige_mensaje_listo) {

        if (!self::es_de_la_conversacion($conversation, $accion_id)) {

            return self::respuesta(404, ['message' => self::MENSAJE_NO_ENCONTRADA]);
        }

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation, $persona);

        if (self::es_de_dos_etapas($conversation, $accion_id)) {

            return self::ejecutar_en_dos_etapas($contexto, $conversation, $accion_id, $num_expense_resolver, $exige_mensaje_listo);
        }

        try {

            DB::transaction(function () use ($contexto, $conversation, $accion_id, $num_expense_resolver, $exige_mensaje_listo) {

                $accion = self::bloquear($conversation, $accion_id);

                self::verificar_que_siga_propuesta($accion, $exige_mensaje_listo);

                $resultado = self::ejecutar_por_tipo($contexto, $accion, $num_expense_resolver);

                $accion->estado = AiMessageAction::ESTADO_CONFIRMADA;
                $accion->resultado = $resultado;
                $accion->resuelta_at = Carbon::now();
                $accion->error_mensaje = null;
                $accion->save();
            });

        } catch (AccionIaException $e) {

            return self::respuesta_de_negocio($accion_id, $e);

        } catch (\Throwable $e) {

            Log::error('EjecutorAccionesIaHelper: falló la confirmación de una tarjeta del asistente', [
                'ai_message_action_id' => (int) $accion_id,
                'ai_conversation_id'   => (int) $conversation->id,
                'error'                => $e->getMessage(),
            ]);

            // Afuera de la transacción, que ya se revirtió: ver el docblock.
            self::guardar_error($accion_id, self::MENSAJE_ERROR_GENERICO);

            return self::respuesta(500, ['message' => self::MENSAJE_ERROR_GENERICO]);
        }

        return self::respuesta(200, ['model' => AiMessageAction::find((int) $accion_id)]);
    }

    /**
     * true si esta tarjeta es de las que se ejecutan en dos etapas (ver TIPOS_DE_DOS_ETAPAS).
     *
     * Lee el tipo sin candado a propósito: es sólo para elegir el camino, y el camino elegido
     * vuelve a bloquear y a verificar la fila antes de tocar nada. El tipo de una tarjeta, además,
     * no cambia nunca después de creada.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  int  $accion_id
     * @return bool
     */
    protected static function es_de_dos_etapas(AiConversation $conversation, $accion_id) {

        $tipo = AiMessageAction::where('id', (int) $accion_id)
                                ->where('ai_conversation_id', $conversation->id)
                                ->value('tipo');

        return in_array((string) $tipo, self::TIPOS_DE_DOS_ETAPAS, true);
    }

    /**
     * La confirmación de una carga que llama al controller de la pantalla: primero se RESERVA la
     * tarjeta en una transacción corta, y recién después, con esa transacción ya cerrada, se
     * ejecuta.
     *
     * 🔴 POR QUÉ NO ALCANZA CON LA TRANSACCIÓN ÚNICA DE ejecutar_confirmacion(). Ver el docblock
     * de TIPOS_DE_DOS_ETAPAS: adentro de esos controllers hay un HTTP síncrono a Tienda Nube, un
     * `RELEASE_LOCK`, un job y un evento. Nada de eso se revierte con un rollback, y el
     * `DB::commit()` de `SaleController` anidado ni siquiera comitea: sólo baja el contador. Con
     * la transacción del ejecutor cerrada, cada controller vuelve a correr en las mismas
     * condiciones en las que corre cuando lo llama la pantalla, que es su comportamiento probado.
     *
     * 🔴 EL CANDADO CONTRA EL SEGUNDO CLIC SIGUE EXISTIENDO, y es lo único que reemplaza al
     * `lockForUpdate` sostenido: la etapa 1 marca la fila 'en_curso' y la comitea. El segundo clic
     * entra, espera el candado de fila hasta ese commit, la ve 'en_curso' y sale por 409 sin
     * ejecutar nada — el mismo 409 que daba antes viendo 'confirmada'.
     *
     * 🔴 LO QUE SE PIERDE, DICHO EN VOZ ALTA: ya no hay una transacción que envuelva la ejecución
     * entera, así que un controller que escriba a medias y tire después deja lo que alcanzó a
     * escribir. Es exactamente lo que pasa cuando esa misma carga entra por la pantalla, y es
     * preferible a lo otro: que un rollback nuestro borre de este lado algo que ya salió del
     * sistema. Los rechazos (permisos, tenencia, validación, límite de crédito) cortan ANTES de
     * escribir, y ésos siguen sin dejar nada.
     *
     * Si el proceso muere entre la reserva y el cierre (un OOM, no una excepción: eso lo agarra el
     * catch), la tarjeta queda 'en_curso' y no se puede volver a confirmar. No se resucita sola a
     * propósito: no hay forma de saber si la carga llegó a escribirse, y reintentarla a ciegas es
     * duplicar una venta. La salida es pedirle la carga de nuevo al asistente, que arma otra
     * tarjeta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiConversation  $conversation
     * @param  int  $accion_id
     * @param  callable  $num_expense_resolver
     * @param  bool  $exige_mensaje_listo
     * @return array  ['status' => int, 'body' => array]
     */
    protected static function ejecutar_en_dos_etapas(ContextoDeCargaIa $contexto, AiConversation $conversation, $accion_id, $num_expense_resolver, $exige_mensaje_listo) {

        /* Etapa 1: reservar. Transacción corta, y adentro sólo la fila de la tarjeta. */
        try {

            DB::transaction(function () use ($conversation, $accion_id, $exige_mensaje_listo) {

                $accion = self::bloquear($conversation, $accion_id);

                self::verificar_que_siga_propuesta($accion, $exige_mensaje_listo);

                $accion->estado = AiMessageAction::ESTADO_EN_CURSO;
                $accion->error_mensaje = null;
                $accion->save();
            });

        } catch (AccionIaException $e) {

            return self::respuesta_de_negocio($accion_id, $e);

        } catch (\Throwable $e) {

            Log::error('EjecutorAccionesIaHelper: falló la reserva de una tarjeta del asistente', [
                'ai_message_action_id' => (int) $accion_id,
                'ai_conversation_id'   => (int) $conversation->id,
                'error'                => $e->getMessage(),
            ]);

            self::guardar_error($accion_id, self::MENSAJE_ERROR_GENERICO);

            return self::respuesta(500, ['message' => self::MENSAJE_ERROR_GENERICO]);
        }

        /* Etapa 2: ejecutar, ya sin ninguna transacción abierta por este ejecutor. */
        $accion = AiMessageAction::find((int) $accion_id);

        try {

            $resultado = self::ejecutar_por_tipo($contexto, $accion, $num_expense_resolver);

        } catch (AccionIaException $e) {

            // Antes de la respuesta: respuesta_de_negocio() escribe sobre la fila que sigue
            // 'propuesta' (el error_mensaje y el paso a 'vencida'), así que la reserva se suelta
            // primero. Es el mismo motivo por el que esas escrituras viven afuera de la
            // transacción: lo que tiene que sobrevivir al fracaso no puede depender de ella.
            self::soltar_la_reserva($accion_id);

            return self::respuesta_de_negocio($accion_id, $e);

        } catch (\Throwable $e) {

            Log::error('EjecutorAccionesIaHelper: falló la confirmación de una tarjeta del asistente', [
                'ai_message_action_id' => (int) $accion_id,
                'ai_conversation_id'   => (int) $conversation->id,
                'error'                => $e->getMessage(),
            ]);

            self::soltar_la_reserva($accion_id);

            self::guardar_error($accion_id, self::MENSAJE_ERROR_GENERICO);

            return self::respuesta(500, ['message' => self::MENSAJE_ERROR_GENERICO]);
        }

        self::cerrar_la_reserva($accion_id, $resultado);

        return self::respuesta(200, ['model' => AiMessageAction::find((int) $accion_id)]);
    }

    /**
     * Devuelve a 'propuesta' la tarjeta reservada cuya ejecución falló: la persona la puede volver
     * a confirmar, igual que antes de este camino. Protegida, como guardar_error(): si esta
     * escritura falla, la respuesta con el motivo tiene que salir igual.
     *
     * @param  int  $accion_id
     * @return void
     */
    protected static function soltar_la_reserva($accion_id) {

        try {

            AiMessageAction::where('id', (int) $accion_id)
                            ->where('estado', AiMessageAction::ESTADO_EN_CURSO)
                            ->update(['estado' => AiMessageAction::ESTADO_PROPUESTA]);

        } catch (\Throwable $e) {

            Log::warning('EjecutorAccionesIaHelper: no se pudo devolver a propuesta una tarjeta en curso', [
                'ai_message_action_id' => (int) $accion_id,
                'error'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cierra la tarjeta reservada como 'confirmada' con su resultado. Se relee con el estado en el
     * where para no pisar nada que no sea la reserva de esta corrida, y se guarda por el modelo
     * para que el `resultado` pase por el cast 'object' igual que en el camino de una sola
     * transacción.
     *
     * Si acá falla algo, la carga YA se ejecutó: se loguea y la tarjeta queda 'en_curso', pero no
     * se revierte nada de lo que el controller escribió.
     *
     * @param  int  $accion_id
     * @param  array  $resultado
     * @return void
     */
    protected static function cerrar_la_reserva($accion_id, array $resultado) {

        try {

            $accion = AiMessageAction::where('id', (int) $accion_id)
                                        ->where('estado', AiMessageAction::ESTADO_EN_CURSO)
                                        ->first();

            if (is_null($accion)) {

                Log::warning('EjecutorAccionesIaHelper: la tarjeta ya no estaba en curso al cerrarla', [
                    'ai_message_action_id' => (int) $accion_id,
                ]);

                return;
            }

            $accion->estado = AiMessageAction::ESTADO_CONFIRMADA;
            $accion->resultado = $resultado;
            $accion->resuelta_at = Carbon::now();
            $accion->error_mensaje = null;
            $accion->save();

        } catch (\Throwable $e) {

            Log::error('EjecutorAccionesIaHelper: la carga se ejecutó pero no se pudo cerrar la tarjeta', [
                'ai_message_action_id' => (int) $accion_id,
                'error'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deja la tarjeta 'cancelada', con el mismo candado que confirmar(): una cancelación y una
     * confirmación simultáneas no pueden terminar las dos bien.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  int  $accion_id
     * @return array  ['status' => int, 'body' => array]
     */
    static function cancelar(AiConversation $conversation, $accion_id) {

        if (!self::es_de_la_conversacion($conversation, $accion_id)) {

            return self::respuesta(404, ['message' => self::MENSAJE_NO_ENCONTRADA]);
        }

        try {

            DB::transaction(function () use ($conversation, $accion_id) {

                $accion = self::bloquear($conversation, $accion_id);

                self::verificar_que_siga_propuesta($accion);

                $accion->estado = AiMessageAction::ESTADO_CANCELADA;
                $accion->resuelta_at = Carbon::now();
                $accion->save();
            });

        } catch (AccionIaException $e) {

            return self::respuesta_de_negocio($accion_id, $e);
        }

        $accion = AiMessageAction::find((int) $accion_id);

        /*
         * Lo que una tarjeta dejó preparado en disco y ya no va a usar se limpia ACÁ, después de
         * la transacción y sin poder voltear la cancelación (best-effort). Hoy es una sola: la
         * candidata `catcand_*.webp` de "Imagen para la categoría X" (misión
         * asistente-masivas-imagenes-y-remito). Sin esto el archivo vivía hasta la próxima purga,
         * que solo corre cuando alguien vuelve a pedir imágenes de categorías.
         */
        if (!is_null($accion) && (string) $accion->tipo === AiMessageAction::TIPO_IMAGEN_CATEGORIA) {

            PropuestaImagenCategoriaIaHelper::al_cancelar($accion);
        }

        return self::respuesta(200, ['model' => $accion]);
    }

    /**
     * @param  \App\Models\AiConversation  $conversation
     * @param  int  $accion_id
     * @return bool
     */
    protected static function es_de_la_conversacion(AiConversation $conversation, $accion_id) {

        return AiMessageAction::where('id', (int) $accion_id)
                                ->where('ai_conversation_id', $conversation->id)
                                ->exists();
    }

    /**
     * La tarjeta con la fila bloqueada hasta el commit (ver el candado en confirmar()).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  int  $accion_id
     * @return \App\Models\AiMessageAction
     *
     * @throws AccionIaException
     */
    protected static function bloquear(AiConversation $conversation, $accion_id) {

        $accion = AiMessageAction::where('id', (int) $accion_id)
                                    ->where('ai_conversation_id', $conversation->id)
                                    ->lockForUpdate()
                                    ->first();

        if (is_null($accion)) {

            throw new AccionIaException(404, self::MENSAJE_NO_ENCONTRADA);
        }

        return $accion;
    }

    /**
     * Corta si la tarjeta ya no se puede resolver. Se mira el estado GUARDADO (la lectura con el
     * vencimiento se chequea aparte, porque una vencida hay que dejarla guardada como tal).
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @param  bool  $exige_mensaje_listo  false solo para la auto-ejecución del agente (ver confirmar_en_el_turno()).
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_que_siga_propuesta(AiMessageAction $accion, $exige_mensaje_listo = true) {

        if ($accion->estado_guardado() === AiMessageAction::ESTADO_EN_CURSO) {

            // El segundo clic de una carga de dos etapas, mientras la primera todavía corre. Mismo
            // 409 de siempre, con el motivo real: no está resuelta, está registrándose.
            throw new AccionIaException(409, self::MENSAJE_EN_CURSO);
        }

        if ($accion->estado_guardado() !== AiMessageAction::ESTADO_PROPUESTA) {

            throw new AccionIaException(409, self::MENSAJE_RESUELTA);
        }

        if ($accion->vencio()) {

            throw new AccionIaException(409, self::MENSAJE_VENCIDA, AiMessageAction::ESTADO_VENCIDA);
        }

        $mensaje = AiMessage::find($accion->ai_message_id);

        // Una tarjeta de un mensaje que terminó en error ya tendría que estar descartada; si una
        // carrera la dejó propuesta, se descarta acá en vez de ejecutarla.
        if (is_null($mensaje) || $mensaje->estado === 'error') {

            throw new AccionIaException(409, self::MENSAJE_DESCARTADA, AiMessageAction::ESTADO_DESCARTADA);
        }

        // Un mensaje todavía 'pendiente' no muestra sus tarjetas (AccionesIaHelper::cargar_en_mensajes()):
        // para quien confirma con el dedo, esa tarjeta todavía no existe. La auto-ejecución del agente
        // (misión foto-sucursal-y-asistente-configurable) es la excepción: confirma DENTRO del turno
        // que la propone, con el mensaje 'pendiente' a propósito, así que salta esta sola guarda.
        if ($exige_mensaje_listo && $mensaje->estado !== 'listo') {

            throw new AccionIaException(404, self::MENSAJE_NO_ENCONTRADA);
        }
    }

    /**
     * Ejecuta la carga por el helper de su tipo, que es el que la armó.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @param  callable  $num_expense_resolver
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException
     */
    protected static function ejecutar_por_tipo(ContextoDeCargaIa $contexto, AiMessageAction $accion, $num_expense_resolver) {

        switch ($accion->tipo) {

            case AiMessageAction::TIPO_GASTO:
                return PropuestaGastoIaHelper::ejecutar($contexto, $accion, $num_expense_resolver);

            case AiMessageAction::TIPO_PAGO:
                return PropuestaPagoIaHelper::ejecutar($contexto, $accion);

            case AiMessageAction::TIPO_TAREA_NUEVA:
                return PropuestaTareaIaHelper::ejecutar_tarea_nueva($contexto, $accion);

            case AiMessageAction::TIPO_TAREA_EDITAR:
                return PropuestaTareaIaHelper::ejecutar_cambios($contexto, $accion);

            case AiMessageAction::TIPO_TAREA_COMPLETAR:
                return PropuestaTareaIaHelper::ejecutar_marcar_hecha($contexto, $accion, $num_expense_resolver);

            case AiMessageAction::TIPO_COMBO:
                return PropuestaComboIaHelper::ejecutar($contexto, $accion);

            case AiMessageAction::TIPO_OFERTA:
                return PropuestaOfertaIaHelper::ejecutar($contexto, $accion);

            case AiMessageAction::TIPO_COMPRA_CON_FACTURA:
                return PropuestaCompraConFacturaIaHelper::ejecutar($contexto, $accion);

            case AiMessageAction::TIPO_FOTO_SUCURSAL:
                return PropuestaFotoSucursalIaHelper::ejecutar($contexto, $accion);

            /*
             * Misión asistente-masivas-imagenes-y-remito (19/9/2026). Las de categorías y de diseño
             * de PDF las implementa el constructor B (contrato §6); acá solo se despachan por tipo.
             */
            case AiMessageAction::TIPO_IMAGENES_CATEGORIAS:
                return PropuestaImagenesCategoriasIaHelper::ejecutar($contexto, $accion);

            case AiMessageAction::TIPO_IMAGEN_CATEGORIA:
                return PropuestaImagenCategoriaIaHelper::ejecutar($contexto, $accion);

            case AiMessageAction::TIPO_IMAGENES_ARTICULOS:
                return PropuestaImagenesArticulosIaHelper::ejecutar($contexto, $accion);

            case AiMessageAction::TIPO_ACTUALIZACION_MASIVA:
                return PropuestaActualizacionMasivaIaHelper::ejecutar($contexto, $accion);

            case AiMessageAction::TIPO_DISENO_PDF:
                return PropuestaDisenoPdfIaHelper::ejecutar($contexto, $accion);

            /*
             * Misión asistente-omnisciente (21/9/2026). Las tres genéricas van al ejecutor que llama
             * al controller de la pantalla; la venta la ejecuta el constructor C por SaleController::store
             * (contrato §4). Ninguna pasa por la auto-confirmación.
             */
            case AiMessageAction::TIPO_ALTA:
            case AiMessageAction::TIPO_EDICION:
            case AiMessageAction::TIPO_BAJA:
                return EjecutorGenericoIaHelper::ejecutar($contexto, $accion);

            case AiMessageAction::TIPO_VENTA:
                return PropuestaVentaIaHelper::ejecutar($contexto, $accion);

            // Misión cheques-endoso-y-bancos (21/9/2026).
            case AiMessageAction::TIPO_UNIFICAR_BANCOS:
                return PropuestaBancosChequesIaHelper::ejecutar($contexto, $accion);
        }

        throw new AccionIaException(422, 'Esta tarjeta no se puede confirmar.');
    }

    /**
     * Respuesta de un resultado de negocio, con lo que tiene que quedar guardado escrito ANTES de
     * armarla y afuera de la transacción ya revertida.
     *
     * @param  int  $accion_id
     * @param  AccionIaException  $e
     * @return array
     */
    protected static function respuesta_de_negocio($accion_id, AccionIaException $e) {

        if ($e->status === 404) {

            return self::respuesta(404, ['message' => $e->getMessage()]);
        }

        if (!is_null($e->estado_a_persistir)) {

            // Solo si sigue guardada como propuesta: nunca se pisa un estado ya resuelto.
            AiMessageAction::where('id', (int) $accion_id)
                            ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                            ->update([
                                'estado'      => $e->estado_a_persistir,
                                'resuelta_at' => Carbon::now(),
                            ]);
        }

        if ($e->status === 422) {

            self::guardar_error($accion_id, $e->getMessage());

            return self::respuesta(422, [
                'message' => $e->getMessage(),
                'model'   => AiMessageAction::find((int) $accion_id),
            ]);
        }

        return self::respuesta(409, [
            'code'    => 'accion_resuelta',
            'message' => $e->getMessage(),
            'model'   => AiMessageAction::find((int) $accion_id),
        ]);
    }

    /**
     * Guarda el último error de un intento de confirmar en la tarjeta que sigue propuesta. Protegido:
     * si esta escritura falla, la respuesta igual sale (el error ya se logueó o se devuelve).
     *
     * @param  int  $accion_id
     * @param  string  $mensaje
     * @return void
     */
    protected static function guardar_error($accion_id, $mensaje) {

        try {

            AiMessageAction::where('id', (int) $accion_id)
                            ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                            ->update(['error_mensaje' => mb_substr((string) $mensaje, 0, 5000)]);

        } catch (\Throwable $e) {

            Log::warning('EjecutorAccionesIaHelper: no se pudo guardar el error_mensaje de la tarjeta', [
                'ai_message_action_id' => (int) $accion_id,
                'error'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  int  $status
     * @param  array  $body
     * @return array
     */
    protected static function respuesta($status, array $body) {

        return [
            'status' => (int) $status,
            'body'   => $body,
        ];
    }
}
