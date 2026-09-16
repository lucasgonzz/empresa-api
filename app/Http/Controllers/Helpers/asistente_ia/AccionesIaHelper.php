<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageAction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de las tarjetas de carga del asistente de IA fuera de la confirmación (misión
 * asistente-ia-acciones, §3.5 del plan): crearlas con el reemplazo por clave, descartarlas cuando el
 * mensaje termina en error, borrarlas con la conversación, colgarlas de los mensajes para la SPA y
 * contarle a la IA qué pasó con cada una.
 *
 * Confirmar y cancelar viven en EjecutorAccionesIaHelper, con su candado.
 */
class AccionesIaHelper {

    /**
     * Largo máximo de los renglones de una tarjeta en la línea de historial que lee la IA.
     */
    const LARGO_RENGLONES_HISTORIAL = 300;

    /**
     * Nota que acompaña toda propuesta exitosa: la IA nunca tiene que decir que ya está cargado.
     */
    const NOTA_PROPUESTA = 'La tarjeta queda para que la persona la confirme. No digas que ya está cargado.';

    /**
     * Crea una tarjeta 'propuesta' y pasa a 'reemplazada' las propuestas anteriores de la misma carga.
     *
     * 🔴 EL REEMPLAZO ES LA DEFENSA CONTRA EL DOBLE REGISTRO. Cuando la persona corrige ("no, era
     * 6000"), la IA arma una tarjeta nueva; si la vieja siguiera confirmable, dos clics dejarían dos
     * gastos. Por eso toda propuesta de la conversación con la MISMA clave (de este mensaje o de uno
     * anterior) queda reemplazada, más la que la IA nombró en `reemplaza_a`. Una carga distinta (otra
     * subcategoría, otra cuenta, otra tarea) tiene otra clave y no se pisa.
     *
     * El update del reemplazo vuelve a exigir `estado = propuesta` en el WHERE: si entre la lectura
     * y la escritura alguien confirmó esa tarjeta (el ejecutor la tiene bloqueada), el UPDATE espera
     * el candado y ya no la encuentra propuesta, en vez de pisar un 'confirmada'.
     *
     * 🔴 Y EL REEMPLAZO NO ALCANZA A LA QUE YA SE CONFIRMÓ, que es la otra mitad de la carrera: la
     * persona ve la tarjeta A (flete $ 5.000), escribe "no, era 6000" y, mientras el job arma B, toca
     * Confirmar en A. A queda confirmada con el gasto registrado y B nace propuesta; si la confirma,
     * el flete queda cargado dos veces. La SPA deshabilita Confirmar en las tarjetas viejas mientras
     * hay una respuesta en curso, pero dos pestañas lo saltean. Por eso, si hay una tarjeta confirmada
     * con la misma clave DESPUÉS del mensaje de la persona que disparó esta respuesta, la nueva nace
     * con un aviso y la herramienta se lo cuenta a la IA en `confirmada_parecida`.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant 'pendiente' que la propone.
     * @param  string  $tipo  AiMessageAction::TIPO_*
     * @param  string  $clave  Identidad de la carga (ver PropuestaGastoIaHelper, etc.).
     * @param  array  $datos  Payload interno de ejecución.
     * @param  array  $presentacion  {titulo, renglones: [{etiqueta, valor}], aviso}
     * @param  mixed  $reemplaza_a  Id de una tarjeta anterior de la conversación, o null.
     * @param  string|null  $referencia_updated_at  pendings.updated_at para los cambios en una tarea.
     * @return array  ['accion' => AiMessageAction, 'reemplazadas' => int[], 'confirmada_parecida' => array|null]
     */
    static function crear(ContextoDeCargaIa $contexto, AiMessage $mensaje, $tipo, $clave, array $datos, array $presentacion, $reemplaza_a = null, $referencia_updated_at = null) {

        $clave = mb_substr((string) $clave, 0, 100);

        return DB::transaction(function () use ($contexto, $mensaje, $tipo, $clave, $datos, $presentacion, $reemplaza_a, $referencia_updated_at) {

            $accion = AiMessageAction::create([
                'ai_conversation_id'    => $contexto->conversation->id,
                'ai_message_id'         => $mensaje->id,
                'user_id'               => $contexto->owner_id,
                'auth_user_id'          => (int) $contexto->conversation->auth_user_id,
                'tipo'                  => $tipo,
                'clave'                 => $clave,
                'estado'                => AiMessageAction::ESTADO_PROPUESTA,
                'datos'                 => $datos,
                'presentacion'          => $presentacion,
                'referencia_updated_at' => $referencia_updated_at,
            ]);

            $reemplaza_a = (int) $reemplaza_a;

            $anteriores = AiMessageAction::where('ai_conversation_id', $contexto->conversation->id)
                                        ->where('id', '!=', $accion->id)
                                        ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                                        ->where(function ($q) use ($clave, $reemplaza_a) {
                                            $q->where('clave', $clave);

                                            if ($reemplaza_a > 0) {
                                                $q->orWhere('id', $reemplaza_a);
                                            }
                                        })
                                        ->orderBy('id')
                                        ->pluck('id');

            $candidatas = [];

            foreach ($anteriores as $id) {

                $candidatas[] = (int) $id;
            }

            $reemplazadas = [];

            /** La tarjeta de la misma carga que quedó confirmada (la carrera), o null. */
            $confirmada = null;

            if (count($candidatas)) {

                /*
                 * 🔴 LO QUE SE INFORMA ES EL RESULTADO DEL UPDATE, NO EL DEL SELECT DE ARRIBA.
                 *
                 * El WHERE del update exige `propuesta` a propósito, así que si alguien confirmó una
                 * de esas tarjetas entre el SELECT y el UPDATE (dos pestañas, o el clic mientras el
                 * job armaba la corrección), el update NO la toca. Informar el pluck sería decirle a
                 * la IA que la reemplazó —y la IA le diría a la persona que la vieja quedó
                 * cancelada— cuando en realidad ya está REGISTRADA: confirmar la nueva duplicaría la
                 * carga. Por eso se guarda el entero que devuelve el update y, si no coincide, se
                 * vuelve a leer cuáles quedaron reemplazadas de verdad y cuál se confirmó.
                 */
                $afectadas = AiMessageAction::whereIn('id', $candidatas)
                                            ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                                            ->update([
                                                'estado'      => AiMessageAction::ESTADO_REEMPLAZADA,
                                                'resuelta_at' => Carbon::now(),
                                            ]);

                if ((int) $afectadas === count($candidatas)) {

                    $reemplazadas = $candidatas;

                } else {

                    foreach (AiMessageAction::whereIn('id', $candidatas)
                                            ->where('estado', AiMessageAction::ESTADO_REEMPLAZADA)
                                            ->orderBy('id')
                                            ->pluck('id') as $id) {

                        $reemplazadas[] = (int) $id;
                    }

                    $confirmada = AiMessageAction::whereIn('id', $candidatas)
                                                ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
                                                ->orderBy('id', 'DESC')
                                                ->first();
                }
            }

            /*
             * La otra punta de la misma carrera: una tarjeta de esta clave que ya no estaba propuesta
             * cuando se leyeron las candidatas, y que se confirmó después del pedido de la persona.
             */
            if (is_null($confirmada)) {

                $confirmada = self::confirmada_parecida($contexto, $mensaje, $clave);
            }

            $confirmada_parecida = null;

            if (!is_null($confirmada)) {

                $texto = is_object($confirmada->resultado) && isset($confirmada->resultado->texto)
                    ? (string) $confirmada->resultado->texto
                    : 'ya quedó registrada';

                $aviso = 'Ojo: hace un momento confirmaste una carga parecida ('.$texto.'). Confirmá esta solo si es otra carga.';

                $aviso_previo = isset($presentacion['aviso']) ? trim((string) $presentacion['aviso']) : '';

                $presentacion['aviso'] = $aviso_previo !== '' ? $aviso_previo.' '.$aviso : $aviso;

                // El aviso se descubre después de crear la tarjeta, así que se le agrega acá mismo.
                $accion->presentacion = $presentacion;
                $accion->save();

                $confirmada_parecida = [
                    'tarjeta_id' => (int) $confirmada->id,
                    'resultado'  => $texto,
                ];
            }

            return [
                'accion'              => $accion,
                'reemplazadas'        => $reemplazadas,
                'confirmada_parecida' => $confirmada_parecida,
            ];
        });
    }

    /**
     * La tarjeta confirmada de la misma carga (misma clave, misma conversación) que se resolvió
     * después del mensaje de la persona que disparó esta respuesta (el último 'user' anterior al
     * assistant que está proponiendo), o null. Ver la carrera en el docblock de crear().
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @param  string  $clave
     * @return \App\Models\AiMessageAction|null
     */
    protected static function confirmada_parecida(ContextoDeCargaIa $contexto, AiMessage $mensaje, $clave) {

        $pedido = AiMessage::where('ai_conversation_id', $contexto->conversation->id)
                            ->where('rol', 'user')
                            ->where('id', '<', $mensaje->id)
                            ->orderBy('id', 'DESC')
                            ->first();

        if (is_null($pedido) || is_null($pedido->created_at)) {

            return null;
        }

        return AiMessageAction::where('ai_conversation_id', $contexto->conversation->id)
                                ->where('clave', $clave)
                                ->where('estado', AiMessageAction::ESTADO_CONFIRMADA)
                                ->where('resuelta_at', '>=', $pedido->created_at)
                                ->orderBy('resuelta_at', 'DESC')
                                ->orderBy('id', 'DESC')
                                ->first();
    }

    /**
     * Respuesta de una herramienta proponer_* cuando la tarjeta quedó creada.
     *
     * @param  array  $creada  Lo que devolvió crear().
     * @param  string  $resumen  Una línea con lo que dice la tarjeta.
     * @param  array  $extra  Claves adicionales (por ejemplo `convertido_desde` y `motivo`).
     * @return array
     */
    static function respuesta_de_propuesta(array $creada, $resumen, array $extra = []) {

        $accion = $creada['accion'];

        $respuesta = [
            'ok'         => true,
            'tarjeta_id' => (int) $accion->id,
            'tipo'       => (string) $accion->tipo,
            'resumen'    => (string) $resumen,
            'reemplazo'  => $creada['reemplazadas'],
            'nota'       => self::NOTA_PROPUESTA,
        ];

        // Solo cuando la hay: la IA tiene que mencionar que esa carga parecida ya quedó registrada.
        if (!empty($creada['confirmada_parecida'])) {

            $respuesta['confirmada_parecida'] = $creada['confirmada_parecida'];
        }

        return array_merge($respuesta, $extra);
    }

    /**
     * Pasa a 'descartada' las propuestas de un mensaje que terminó en error. La SPA no las pinta y
     * ya no se pueden confirmar: nacieron de una respuesta que la persona nunca llegó a leer.
     *
     * Lo usan los tres lugares donde un assistant termina en error: el catch de handle() y failed()
     * del job (los dos pasan por marcar_error()) y el cierre de pendientes vencidos de send_message.
     *
     * @param  int  $ai_message_id
     * @return int  Cantidad de tarjetas descartadas.
     */
    static function descartar_de_mensaje($ai_message_id) {

        return AiMessageAction::where('ai_message_id', $ai_message_id)
                                ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
                                ->update([
                                    'estado'      => AiMessageAction::ESTADO_DESCARTADA,
                                    'resuelta_at' => Carbon::now(),
                                ]);
    }

    /**
     * Borra las tarjetas de una conversación (destroy borra la conversación con todo lo suyo).
     *
     * @param  int  $ai_conversation_id
     * @return void
     */
    static function borrar_de_conversacion($ai_conversation_id) {

        AiMessageAction::where('ai_conversation_id', $ai_conversation_id)->delete();
    }

    /**
     * Cuelga `acciones` de cada mensaje para la SPA (contrato §2.2): las tarjetas del mensaje en orden
     * de id, y un array vacío para todo mensaje que no esté 'listo'.
     *
     * 🔴 Un mensaje 'pendiente' nunca muestra tarjetas aunque ya tenga alguna creada: la herramienta
     * la crea en medio del loop y la respuesta todavía se está escribiendo, así que la persona podría
     * confirmar algo que la IA después corrige en el mismo mensaje (o que termina descartado porque
     * el mensaje falla). Uno 'error' tampoco: sus tarjetas quedaron descartadas.
     *
     * Una sola consulta para todos los mensajes de la página, no una por mensaje.
     *
     * @param  iterable  $mensajes  Modelos AiMessage.
     * @return void
     */
    static function cargar_en_mensajes($mensajes) {

        $ids = [];

        foreach ($mensajes as $mensaje) {

            if ($mensaje->estado === 'listo') {

                $ids[] = (int) $mensaje->id;
            }
        }

        $por_mensaje = [];

        if (count($ids)) {

            foreach (AiMessageAction::whereIn('ai_message_id', $ids)->orderBy('id')->get() as $accion) {

                $por_mensaje[(int) $accion->ai_message_id][] = $accion;
            }
        }

        foreach ($mensajes as $mensaje) {

            $acciones = isset($por_mensaje[(int) $mensaje->id]) ? $por_mensaje[(int) $mensaje->id] : [];

            $mensaje->setRelation('acciones', new EloquentCollection($acciones));
        }
    }

    /**
     * Línea que se agrega al final de un mensaje del assistant en el historial que lee la IA:
     * `[Tarjeta #12 · Gasto · Subcategoría: ...; Monto: ... · estado: confirmada (Gasto N° 88 registrado)]`.
     * Así la IA sabe qué se confirmó, qué se canceló y qué quedó reemplazado, y no vuelve a proponer
     * lo que ya está cargado.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @return string
     */
    static function linea_de_historial(AiMessageAction $accion) {

        $presentacion = is_array($accion->presentacion) ? $accion->presentacion : [];

        $titulo = isset($presentacion['titulo']) ? (string) $presentacion['titulo'] : (string) $accion->tipo;

        $renglones = [];

        if (isset($presentacion['renglones']) && is_array($presentacion['renglones'])) {

            foreach ($presentacion['renglones'] as $renglon) {

                if (is_array($renglon) && isset($renglon['etiqueta'])) {

                    $renglones[] = $renglon['etiqueta'].': '.(isset($renglon['valor']) ? $renglon['valor'] : '');
                }
            }
        }

        $detalle = implode('; ', $renglones);

        if (mb_strlen($detalle) > self::LARGO_RENGLONES_HISTORIAL) {

            $detalle = mb_substr($detalle, 0, self::LARGO_RENGLONES_HISTORIAL - 3).'...';
        }

        $estado = (string) $accion->estado;

        if ($estado === AiMessageAction::ESTADO_CONFIRMADA && is_object($accion->resultado) && isset($accion->resultado->texto)) {

            $estado .= ' ('.$accion->resultado->texto.')';

        } elseif ($estado === AiMessageAction::ESTADO_PROPUESTA && trim((string) $accion->error_mensaje) !== '') {

            $estado .= ' (el último intento de confirmarla falló: '.$accion->error_mensaje.')';
        }

        return '[Tarjeta #'.$accion->id.' · '.$titulo.' · '.$detalle.' · estado: '.$estado.']';
    }
}
