<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiEnvioFallido;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\User;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "SI REBOTA, TE AVISO POR ACÁ": los envíos a clientes del asistente que fallan DESPUÉS del sí del dueño
 * (misión asistente-arreglos-segunda-prueba, 19/10/2026, defecto N3).
 *
 * 🔴 EL CASO REAL. En la segunda prueba por WhatsApp contra demo3 el dueño confirmó mandarle un
 * presupuesto a Marcos por mail. El resultado decía «mandado a Marcos Perez: … por mail a X (sale en
 * unos minutos)», la nota empujaba al modelo a contestar «le mandé el presupuesto», y el mail murió en el
 * worker (el transporte de demo3 devolvió 401). Nada ataba ese fallo a la tarjeta ni a la conversación:
 * quedó en `failed_jobs` y en el log. Con los recordatorios de cobro y las promociones (un job por
 * cliente, en segundo plano) pasaba lo mismo: un renglón de log por cliente y nada más.
 *
 * Qué hace este helper:
 *
 *   1. registrar(): deja la fila en `ai_envios_fallidos` atada a la tarjeta (y por ella al dueño y a la
 *      conversación). 🔴 NUNCA LANZA: lo llaman un `failed()` de la cola, el listener de `MessageSent`
 *      (adentro de `Mailer::send()`), el webhook de estados de WhatsApp y los jobs; una excepción acá
 *      rompería justo lo que está tratando de reportar.
 *   2. En un LOTE (recordatorios, promo) junta los fallos de la misma tarjeta y canal en UNA fila con
 *      `cantidad` y los nombres: cuarenta clientes que no recibieron son un aviso, no cuarenta.
 *   3. Arma el texto del aviso y la lista de pendientes que `AvisosDelAsistenteHelper` mezcla con los
 *      escaneos (`envio_fallido:{uuid}`), y sella lo que ya se dijo en el turno (cola sync) para no
 *      avisarlo dos veces.
 *
 * 🔴 EL AVISO SE PROMETE SOLO SI VA A LLEGAR: `avisar_por_whatsapp` = la tarjeta la propuso un mensaje de
 * WhatsApp y el admin de este dueño anunció que consulta los avisos (el mismo criterio que el escaneo de
 * una factura, `PropuestaCompraConFacturaIaHelper::avisa_por_whatsapp()`). Desde el panel la fila queda
 * igual (sirve para el registro), sin aviso.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class EnviosFallidosDelAsistenteHelper
{
    /** Cuántos nombres guarda una fila de lote (el resto queda contado en `cantidad`). */
    const MAXIMO_DE_NOMBRES = 20;

    /** Cuántos nombres se escriben en el aviso; el resto va como "y N más". */
    const NOMBRES_EN_EL_AVISO = 10;

    /**
     * Minutos sin fallos nuevos antes de avisar un LOTE: los jobs de un lote corren uno atrás del otro y
     * avisar con el primero dejaría al resto para un segundo aviso. Un comprobante se avisa enseguida.
     */
    const MINUTOS_DE_CALMA_DEL_LOTE = 2;

    /** Los motivos, en palabras del dueño (nunca el error crudo del servidor). */
    const MOTIVO_MAIL_RECHAZADO = 'el servidor de correo rechazó la casilla';

    const MOTIVO_MAIL_NO_SALIO = 'el servidor de correo no lo aceptó';

    const MOTIVO_WHATSAPP_NO_ENTREGADO = 'WhatsApp no lo entregó';

    /**
     * Registra un envío que no salió. Nunca lanza.
     *
     * @param  int|null  $ai_message_action_id  La tarjeta del envío. Sin tarjeta (un mail de la pantalla,
     *                                          un WhatsApp que no mandó el asistente) no hay nada que
     *                                          registrar y devuelve null.
     * @param  string  $canal  AiEnvioFallido::CANAL_MAIL | CANAL_WHATSAPP
     * @param  string|null  $destino  La casilla o el teléfono.
     * @param  string|null  $cliente  El nombre del cliente.
     * @param  string  $motivo  En palabras del dueño.
     * @return \App\Models\AiEnvioFallido|null
     */
    public static function registrar($ai_message_action_id, $canal, $destino, $cliente, $motivo)
    {
        try {

            if (empty($ai_message_action_id) || !is_numeric($ai_message_action_id)) {

                return null;
            }

            $accion = AiMessageAction::find((int) $ai_message_action_id);

            if (is_null($accion)) {

                return null;
            }

            $tipo = self::tipo_de_la_tarjeta($accion);

            if (is_null($tipo)) {

                // Solo los tres envíos a clientes: cualquier otra tarjeta no manda nada a nadie.
                return null;
            }

            $canal = $canal === AiEnvioFallido::CANAL_WHATSAPP ? AiEnvioFallido::CANAL_WHATSAPP : AiEnvioFallido::CANAL_MAIL;

            $destino = self::recortar($destino, 191);
            $cliente = self::recortar($cliente, 191);
            $motivo = self::recortar($motivo, 255);

            if ($tipo === AiEnvioFallido::TIPO_COMPROBANTE) {

                return self::registrar_comprobante($accion, $canal, $destino, $cliente, $motivo);
            }

            return self::registrar_en_el_lote($accion, $tipo, $canal, $destino, $cliente, $motivo);

        } catch (\Throwable $e) {

            /*
             * Ni siquiera el log puede romper al que llamó: el `failed()` de un worker o el listener de un
             * envío de mail no tienen a quién devolverle la excepción.
             */
            try {
                Log::warning('EnviosFallidosDelAsistenteHelper: no se pudo registrar un envío fallido.', [
                    'ai_message_action_id' => $ai_message_action_id,
                    'canal'                => $canal,
                    'error'                => $e->getMessage(),
                ]);
            } catch (\Throwable $ignorada) {
                // Nada: registrar es lo único que hace este helper.
            }

            return null;
        }
    }

    /**
     * Un WhatsApp del asistente que Meta rechazó DESPUÉS de aceptarlo (webhook `whatsapp.message.failed`
     * con la fila marcada con su tarjeta). Nunca lanza.
     *
     * @param  \App\Models\WhatsappChatMessage  $mensaje  La fila ya marcada `fallido`.
     * @return \App\Models\AiEnvioFallido|null
     */
    public static function registrar_whatsapp_rechazado(WhatsappChatMessage $mensaje)
    {
        try {

            if (empty($mensaje->ai_message_action_id)) {

                return null;
            }

            list($cliente, $telefono) = self::cliente_y_telefono_del_mensaje($mensaje);

            $motivo = self::MOTIVO_WHATSAPP_NO_ENTREGADO;

            $error = trim((string) $mensaje->send_error);

            if ($error !== '') {
                // El motivo de Meta, corto: sirve para saber si es el número o la plantilla.
                $motivo .= ' (' . mb_substr($error, 0, 100) . ')';
            }

            return self::registrar((int) $mensaje->ai_message_action_id, AiEnvioFallido::CANAL_WHATSAPP, $telefono, $cliente, $motivo);

        } catch (\Throwable $e) {

            return null;
        }
    }

    /**
     * Un WhatsApp del asistente que Meta dio por fallido y DESPUÉS por entregado o leído: el fallo no era
     * tal y no se le avisa al dueño "no le llegó" (revisión del 19/10/2026). Nunca lanza.
     *
     * 🔴 POR QUÉ PASA. Con el rango de estados corregido un `failed` después del `sent` se guarda, y un
     * `delivered` posterior (Meta reintenta y entrega, o los eventos llegan desordenados) le gana: la
     * burbuja queda `entregado`. Sin esto, la fila del envío fallido seguía y el dueño recibía el aviso de
     * algo que el cliente sí recibió.
     *
     * Qué se neutraliza: SOLO lo que todavía no se avisó (si ya se avisó, el aviso salió y no se
     * desmanda). En un comprobante (una fila por tarjeta y canal) la fila deja de pedir aviso. En un lote
     * se le resta ese cliente (cantidad y nombre) a la fila sin avisar que lo tiene; si queda en cero, deja
     * de pedir aviso. El cliente se identifica igual que al registrarlo (cliente_y_telefono_del_mensaje).
     * El `updated_at` no se toca: es el reloj de la calma del lote.
     *
     * @param  \App\Models\WhatsappChatMessage  $mensaje  La fila que acaba de pasar de fallido a entregado o leído.
     * @return void
     */
    public static function deshacer_whatsapp_rechazado(WhatsappChatMessage $mensaje)
    {
        try {

            if (empty($mensaje->ai_message_action_id)) {

                return;
            }

            list($cliente) = self::cliente_y_telefono_del_mensaje($mensaje);

            // Recortado igual que al registrarlo, para que el nombre coincida.
            $cliente = self::recortar($cliente, 191);

            $filas = AiEnvioFallido::where('ai_message_action_id', (int) $mensaje->ai_message_action_id)
                                    ->where('canal', AiEnvioFallido::CANAL_WHATSAPP)
                                    ->whereNull('avisado_por_whatsapp_at')
                                    ->where('avisar_por_whatsapp', 1)
                                    ->orderBy('id', 'DESC')
                                    ->get();

            foreach ($filas as $fila) {

                if ($fila->tipo_envio === AiEnvioFallido::TIPO_COMPROBANTE) {

                    $fila->avisar_por_whatsapp = false;
                    $fila->motivo = self::sumar_motivo($fila->motivo, 'después WhatsApp lo entregó');
                    $fila->timestamps = false;
                    $fila->save();

                    return;
                }

                $nombres = $fila->lista_de_nombres();

                $posicion = array_search((string) $cliente, $nombres, true);

                if ($posicion === false) {
                    continue;
                }

                array_splice($nombres, $posicion, 1);

                $fila->cantidad = max(0, (int) $fila->cantidad - 1);
                $fila->nombres = self::nombres_en_json($nombres);

                if ((int) $fila->cantidad === 0) {
                    $fila->avisar_por_whatsapp = false;
                }

                /*
                 * El primer nombre del lote (y su teléfono) es el que se usa con un solo fallo: si el que
                 * salió es ése, pasa el siguiente, sin teléfono (el de la fila era el del que sí recibió).
                 */
                if ($fila->cliente === $cliente) {
                    $fila->cliente = count($nombres) ? (string) $nombres[0] : $fila->cliente;
                    $fila->destino = null;
                }

                $fila->timestamps = false;
                $fila->save();

                return;
            }

        } catch (\Throwable $e) {
            // Sin esto, lo peor que pasa es un aviso de más.
        }
    }

    /**
     * El cliente (nombre) y el teléfono de un WhatsApp saliente, como los ve el aviso: el cliente vinculado
     * al chat o, sin vínculo, el nombre que reporta WhatsApp.
     *
     * @param  \App\Models\WhatsappChatMessage  $mensaje
     * @return array{0: string|null, 1: string|null}
     */
    protected static function cliente_y_telefono_del_mensaje(WhatsappChatMessage $mensaje)
    {
        $chat = WhatsappChat::find((int) $mensaje->whatsapp_chat_id);

        if (is_null($chat)) {

            return [null, null];
        }

        $chat->loadMissing('client');

        return [
            !is_null($chat->client) ? (string) $chat->client->name : (string) $chat->display_name,
            (string) $chat->phone,
        ];
    }

    /**
     * Las filas de una tarjeta que nacieron (o se movieron) desde un momento: lo que falló ADENTRO del
     * turno con la cola sync. Nunca lanza (devuelve vacío).
     *
     * @param  int  $ai_message_action_id
     * @param  string|null  $canal  null = los dos.
     * @param  \Carbon\Carbon  $desde
     * @return \Illuminate\Support\Collection
     */
    public static function de_la_tarjeta_desde($ai_message_action_id, $canal, Carbon $desde)
    {
        try {

            $query = AiEnvioFallido::where('ai_message_action_id', (int) $ai_message_action_id)
                                    ->where('updated_at', '>=', $desde);

            if (!is_null($canal)) {
                $query->where('canal', (string) $canal);
            }

            return $query->orderBy('id')->get();

        } catch (\Throwable $e) {

            return collect();
        }
    }

    /**
     * Sella como "ya dicho en el turno" las filas que el resultado de la confirmación ya le cuenta al
     * dueño: con la cola sync el mail o el job fallan adentro del mismo turno, y el aviso por WhatsApp
     * sería contarle dos veces lo mismo. Nunca lanza.
     *
     * @param  \Illuminate\Support\Collection|array  $filas
     * @return void
     */
    public static function sellar_dichas_en_el_turno($filas)
    {
        try {

            $ids = [];

            foreach ($filas as $fila) {
                $ids[] = (int) $fila->id;
            }

            if (count($ids)) {
                AiEnvioFallido::whereIn('id', $ids)->whereNull('dicho_en_el_turno_at')->update(['dicho_en_el_turno_at' => Carbon::now()]);
            }

        } catch (\Throwable $e) {
            // Sin el sello lo peor que pasa es un aviso de más.
        }
    }

    /**
     * Las filas que el admin tiene que avisar por WhatsApp, de la más vieja a la más nueva y con tope.
     *
     * Una fila entra si: pidió el aviso, no se avisó todavía, no se dijo ya en el turno, nació hace menos
     * de AvisosDelAsistenteHelper::HORAS_DEL_AVISO (la ventana de 24 h de WhatsApp: después ya no le sirve
     * al dueño como aviso) y, si es de un lote, lleva MINUTOS_DE_CALMA_DEL_LOTE sin fallos nuevos.
     *
     * @param  \App\Models\User  $dueno
     * @param  int  $tope
     * @return \Illuminate\Support\Collection
     */
    public static function pendientes_de_aviso(User $dueno, $tope)
    {
        $calma = Carbon::now()->subMinutes(self::MINUTOS_DE_CALMA_DEL_LOTE);

        return AiEnvioFallido::where('owner_id', (int) $dueno->id)
                                ->where('avisar_por_whatsapp', 1)
                                ->whereNull('avisado_por_whatsapp_at')
                                ->whereNull('dicho_en_el_turno_at')
                                ->where('created_at', '>=', Carbon::now()->subHours(AvisosDelAsistenteHelper::HORAS_DEL_AVISO))
                                ->where(function ($query) use ($calma) {
                                    $query->where('tipo_envio', AiEnvioFallido::TIPO_COMPROBANTE)
                                            ->orWhere('updated_at', '<=', $calma);
                                })
                                ->orderBy('created_at')
                                ->orderBy('id')
                                ->limit((int) $tope)
                                ->get();
    }

    /**
     * El texto del aviso, en la voz del asistente y sin link (este aviso no lleva: `ruta` vacía).
     *
     * Ejemplos:
     *   «No le llegó el presupuesto N° 900005 a Marcos Perez por mail (marcos@test.local): el servidor de
     *    correo rechazó la casilla. Por WhatsApp sí salió. Si querés, pedime que se lo mande de nuevo.»
     *   «De los recordatorios de cobro, 3 no salieron por WhatsApp: Ana, Juan y Pedro (WhatsApp no confirmó
     *    el envío).»
     *
     * @param  \App\Models\AiEnvioFallido  $fila
     * @return string
     */
    public static function texto_del_aviso(AiEnvioFallido $fila): string
    {
        $por = $fila->canal === AiEnvioFallido::CANAL_WHATSAPP ? 'WhatsApp' : 'mail';

        $motivo = trim((string) $fila->motivo);

        if ($fila->tipo_envio === AiEnvioFallido::TIPO_COMPROBANTE) {

            $que = trim((string) $fila->que) !== '' ? (string) $fila->que : 'el comprobante';

            $texto = 'No le llegó ' . $que . ' a ' . self::nombre($fila->cliente) . ' por ' . $por
                . (trim((string) $fila->destino) !== '' ? ' (' . $fila->destino . ')' : '')
                . ($motivo !== '' ? ': ' . $motivo : '') . '.';

            $otro = self::el_otro_canal_salio($fila);

            if (!is_null($otro)) {
                $texto .= ' Por ' . $otro . ' sí salió.';
            }

            return $texto . ' Si querés, pedime que se lo mande de nuevo.';
        }

        $de_que = $fila->tipo_envio === AiEnvioFallido::TIPO_RECORDATORIOS ? 'el recordatorio de cobro' : 'la promoción';

        if ((int) $fila->cantidad <= 1) {

            return 'No le salió ' . $de_que . ' a ' . self::nombre($fila->cliente) . ' por ' . $por
                . (trim((string) $fila->destino) !== '' ? ' (' . $fila->destino . ')' : '')
                . ($motivo !== '' ? ': ' . $motivo : '') . '.';
        }

        $de_los = $fila->tipo_envio === AiEnvioFallido::TIPO_RECORDATORIOS ? 'De los recordatorios de cobro' : 'De la promoción';

        return $de_los . ', ' . (int) $fila->cantidad . ' no salieron por ' . $por . ': '
            . self::nombres_del_aviso($fila) . ($motivo !== '' ? ' (' . $motivo . ')' : '') . '.';
    }

    /**
     * Si la tarjeta la propuso un mensaje de WhatsApp y el admin de este dueño anunció que consulta los
     * avisos: lo que decide `avisar_por_whatsapp` y si el resultado puede prometer «si rebota, te aviso».
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @return bool
     */
    public static function avisa_por_whatsapp(AiMessageAction $accion): bool
    {
        $propuso = AiMessage::find((int) $accion->ai_message_id);

        if (is_null($propuso) || !$propuso->es_de_whatsapp()) {

            return false;
        }

        return AvisosDelAsistenteHelper::admin_avisa((int) $accion->user_id);
    }

    /**
     * El tipo de envío de una tarjeta, o null si no es un envío a clientes.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @return string|null
     */
    protected static function tipo_de_la_tarjeta(AiMessageAction $accion)
    {
        $tipos = [
            AiMessageAction::TIPO_ENVIO_COMPROBANTE   => AiEnvioFallido::TIPO_COMPROBANTE,
            AiMessageAction::TIPO_RECORDATORIOS_COBRO => AiEnvioFallido::TIPO_RECORDATORIOS,
            AiMessageAction::TIPO_PROMOCION_WHATSAPP  => AiEnvioFallido::TIPO_PROMOCION,
        ];

        return isset($tipos[(string) $accion->tipo]) ? $tipos[(string) $accion->tipo] : null;
    }

    /**
     * Un comprobante: UNA fila por tarjeta y canal. Si ya hay (el `failed()` de la cola y el listener del
     * 550 no se pisan, pero un reintento de la cola o un webhook repetido sí podrían), se devuelve la que
     * estaba.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @param  string  $canal
     * @param  string|null  $destino
     * @param  string|null  $cliente
     * @param  string|null  $motivo
     * @return \App\Models\AiEnvioFallido
     */
    protected static function registrar_comprobante(AiMessageAction $accion, $canal, $destino, $cliente, $motivo)
    {
        $ya = AiEnvioFallido::where('ai_message_action_id', (int) $accion->id)->where('canal', $canal)->first();

        if (!is_null($ya)) {

            return $ya;
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        return AiEnvioFallido::create([
            'uuid'                 => (string) Str::uuid(),
            'owner_id'             => (int) $accion->user_id,
            'ai_conversation_id'   => (int) $accion->ai_conversation_id,
            'ai_message_action_id' => (int) $accion->id,
            'tipo_envio'           => AiEnvioFallido::TIPO_COMPROBANTE,
            'canal'                => $canal,
            'destino'              => $destino,
            'cliente'              => $cliente,
            'nombres'              => null,
            // El nombre que la tarjeta le mostró al dueño ("el presupuesto N° 900005"), guardado al proponer.
            'que'                  => isset($datos['nombre']) ? self::recortar($datos['nombre'], 191) : null,
            'motivo'               => $motivo,
            'cantidad'             => 1,
            'avisar_por_whatsapp'  => self::avisa_por_whatsapp($accion),
        ]);
    }

    /**
     * Un lote (recordatorios, promo): los fallos de la misma tarjeta y canal que todavía no se avisaron
     * se juntan en UNA fila. La fila se bloquea para que dos jobs seguidos sumen y no se pisen.
     *
     * 🔴 SOLO SE SUMA A UNA FILA QUE TODAVÍA NO ESTÁ CALMA (corrección de la revisión del 19/10/2026). Una
     * fila calma (sin fallos nuevos hace MINUTOS_DE_CALMA_DEL_LOTE) ya es ofrecible: el admin puede
     * haberla traído con avisos-pendientes y estar mandando "2 no salieron: Ana y Juan". Si el fallo de
     * Pedro que llega en ese medio minuto se sumara ahí, el sello de avisos/avisado se llevaba a Pedro
     * sin que nadie se lo dijera al dueño. Con esto, un fallo que llega después de la calma abre una fila
     * nueva (y su propio aviso). La condición es la MISMA que usa pendientes_de_aviso() para ofrecerla.
     *
     * ⚠️ Con dos workers que fallan EXACTAMENTE a la vez y todavía no hay fila, pueden nacer dos (dos
     * avisos en vez de uno). Hoy corre un worker por cliente; es el mismo límite que dice el progreso del
     * lote de `SendRecordatorioCobroJob`. Y un fallo que llega después de la calma de una fila que el
     * admin todavía no consultó también abre otra: dos avisos en vez de uno, nunca uno perdido.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @param  string  $tipo
     * @param  string  $canal
     * @param  string|null  $destino
     * @param  string|null  $cliente
     * @param  string|null  $motivo
     * @return \App\Models\AiEnvioFallido
     */
    protected static function registrar_en_el_lote(AiMessageAction $accion, $tipo, $canal, $destino, $cliente, $motivo)
    {
        return DB::transaction(function () use ($accion, $tipo, $canal, $destino, $cliente, $motivo) {

            $fila = AiEnvioFallido::where('ai_message_action_id', (int) $accion->id)
                                    ->where('canal', $canal)
                                    ->whereNull('avisado_por_whatsapp_at')
                                    ->whereNull('dicho_en_el_turno_at')
                                    // Todavía no calma: no se pudo haber ofrecido (ver el 🔴 de arriba).
                                    ->where('updated_at', '>', Carbon::now()->subMinutes(self::MINUTOS_DE_CALMA_DEL_LOTE))
                                    ->lockForUpdate()
                                    ->first();

            if (is_null($fila)) {

                return AiEnvioFallido::create([
                    'uuid'                 => (string) Str::uuid(),
                    'owner_id'             => (int) $accion->user_id,
                    'ai_conversation_id'   => (int) $accion->ai_conversation_id,
                    'ai_message_action_id' => (int) $accion->id,
                    'tipo_envio'           => $tipo,
                    'canal'                => $canal,
                    'destino'              => $destino,
                    'cliente'              => $cliente,
                    'nombres'              => json_encode(is_null($cliente) ? [] : [$cliente], JSON_UNESCAPED_UNICODE),
                    'que'                  => $tipo === AiEnvioFallido::TIPO_RECORDATORIOS ? 'los recordatorios de cobro' : 'la promoción',
                    'motivo'               => $motivo,
                    'cantidad'             => 1,
                    'avisar_por_whatsapp'  => self::avisa_por_whatsapp($accion),
                ]);
            }

            $nombres = $fila->lista_de_nombres();

            if (!is_null($cliente) && count($nombres) < self::MAXIMO_DE_NOMBRES) {
                $nombres[] = $cliente;
            }

            $fila->cantidad = (int) $fila->cantidad + 1;
            $fila->nombres = self::nombres_en_json($nombres);
            $fila->motivo = self::sumar_motivo($fila->motivo, $motivo);
            // El updated_at nuevo es el reloj de la calma del lote (pendientes_de_aviso()).
            $fila->save();

            return $fila;
        });
    }

    /**
     * Si el OTRO canal de un comprobante salió: estaba en la tarjeta, el resultado no lo dio por fallido
     * y no tiene su propia fila. null si no aplica.
     *
     * @param  \App\Models\AiEnvioFallido  $fila
     * @return string|null  'WhatsApp' o 'mail'.
     */
    protected static function el_otro_canal_salio(AiEnvioFallido $fila)
    {
        $accion = AiMessageAction::find((int) $fila->ai_message_action_id);

        if (is_null($accion)) {

            return null;
        }

        $otro = $fila->canal === AiEnvioFallido::CANAL_WHATSAPP ? AiEnvioFallido::CANAL_MAIL : AiEnvioFallido::CANAL_WHATSAPP;

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $canales = isset($datos['canales']) && is_array($datos['canales']) ? $datos['canales'] : [];

        if (!in_array($otro, $canales, true)) {

            return null;
        }

        $resultado = json_decode(json_encode($accion->resultado), true);

        $nombre_del_otro = $otro === AiEnvioFallido::CANAL_WHATSAPP ? 'WhatsApp' : 'mail';

        if (is_array($resultado) && isset($resultado['no_salieron']) && is_array($resultado['no_salieron'])) {

            foreach ($resultado['no_salieron'] as $no_salio) {

                if (isset($no_salio['canal']) && (string) $no_salio['canal'] === $nombre_del_otro) {

                    return null;
                }
            }
        }

        if (AiEnvioFallido::where('ai_message_action_id', (int) $accion->id)->where('canal', $otro)->exists()) {

            return null;
        }

        return $nombre_del_otro;
    }

    /**
     * "Ana, Juan y Pedro" o "Ana, Juan, … y 12 más" (los que no entran en el aviso, contados con
     * `cantidad`, que sigue sumando aunque la lista guardada tenga tope).
     *
     * @param  \App\Models\AiEnvioFallido  $fila
     * @return string
     */
    protected static function nombres_del_aviso(AiEnvioFallido $fila)
    {
        $nombres = array_map(function ($nombre) {
            return self::nombre($nombre);
        }, $fila->lista_de_nombres());

        $visibles = array_slice($nombres, 0, self::NOMBRES_EN_EL_AVISO);

        $resto = max(0, (int) $fila->cantidad - count($visibles));

        if (!count($visibles)) {

            return $resto . ' clientes';
        }

        if ($resto > 0) {

            return implode(', ', $visibles) . ' y ' . $resto . ' más';
        }

        if (count($visibles) === 1) {

            return $visibles[0];
        }

        $ultimo = array_pop($visibles);

        return implode(', ', $visibles) . ' y ' . $ultimo;
    }

    /**
     * Los motivos de un lote, sin repetir: "WhatsApp no confirmó el envío; la plantilla dejó de estar
     * aprobada". Con tope de columna.
     *
     * @param  string|null  $actual
     * @param  string|null  $nuevo
     * @return string|null
     */
    protected static function sumar_motivo($actual, $nuevo)
    {
        $actual = trim((string) $actual);
        $nuevo = trim((string) $nuevo);

        if ($nuevo === '' || $actual === $nuevo) {

            return $actual === '' ? null : $actual;
        }

        if ($actual === '') {

            return self::recortar($nuevo, 255);
        }

        foreach (explode('; ', $actual) as $ya) {

            if ($ya === $nuevo) {

                return $actual;
            }
        }

        $suma = $actual . '; ' . $nuevo;

        return mb_strlen($suma) > 255 ? $actual : $suma;
    }

    /**
     * La lista en JSON, achicada hasta que entre en la columna (1000).
     *
     * @param  array<int, string>  $nombres
     * @return string
     */
    protected static function nombres_en_json(array $nombres)
    {
        $json = json_encode(array_values($nombres), JSON_UNESCAPED_UNICODE);

        while (mb_strlen($json) > 1000 && count($nombres) > 1) {
            array_pop($nombres);
            $json = json_encode(array_values($nombres), JSON_UNESCAPED_UNICODE);
        }

        return $json;
    }

    /**
     * Un nombre para el texto, con relleno.
     *
     * @param  mixed  $nombre
     * @return string
     */
    protected static function nombre($nombre)
    {
        $nombre = trim((string) $nombre);

        return $nombre === '' ? 'tu cliente' : $nombre;
    }

    /**
     * Un string recortado al largo de la columna, o null si viene vacío.
     *
     * @param  mixed  $valor
     * @param  int  $largo
     * @return string|null
     */
    protected static function recortar($valor, $largo)
    {
        if (is_null($valor)) {

            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, (int) $largo);
    }
}
