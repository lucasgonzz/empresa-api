<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\IaImagenPedido;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Las imágenes que el agente pide para el dueño desde la app (misión app-ia-y-memoria, 29/10/2026,
 * §4 E1.7 del plan): el pedido, el tope diario, la llegada del resultado y el bloque `imagen_generada`
 * del mensaje que la muestra.
 *
 * El circuito: pedir_imagen (herramienta del agente, solo en la app) → pedir() deja el pedido
 * `pendiente` y el bloque → el job de respuesta guarda el mensaje y despacha
 * EnviarPedidoDeImagenAlAdminJob (C2.1) → la PC de Lucas la genera → el admin la devuelve por
 * admin-sync/ia-imagenes/{uuid}/resultado (C1.4) → recibir_resultado() la guarda y el bloque pasa a
 * `lista` con su `url` → la app, que está consultando el mensaje cada 8 s, la muestra.
 *
 * 🔴 EL BLOQUE SE ACTUALIZA EN LA BASE, CON LA FILA DEL MENSAJE TOMADA (lockForUpdate): lo pueden
 * tocar el job del envío (no_disponible) y el resultado del admin, y un leer-modificar-escribir sin
 * candado pisaría uno con el otro.
 */
class PedidosDeImagenIaHelper
{
    /** Tope por defecto de pedidos por día y por dueño (config `services.ia_imagenes.tope_diario`). */
    const TOPE_DIARIO_POR_DEFECTO = 20;

    /** Peso máximo de una imagen que devuelve el admin (contrato C1.4). */
    const MAX_MB_DEL_RESULTADO = 15;

    /** Los formatos que se aceptan del admin, por los bytes. */
    const MIMES_DEL_RESULTADO = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** Lo que ve el dueño en el bloque cuando la imagen no se pudo generar. */
    const ERROR_PARA_EL_DUENO = 'No se pudo generar la imagen. Probá pedírmela de nuevo en un rato.';

    /** Lo que ve el dueño cuando el admin rechazó el pedido por el tope de imágenes del día (C2.1, 429 `tope_diario`). */
    const TOPE_PARA_EL_DUENO = 'Llegaste al tope de imágenes de hoy; probá mañana.';

    /** Lo que ve el dueño cuando su sistema no tiene todavía el circuito de imágenes del otro lado. */
    const NO_DISPONIBLE_PARA_EL_DUENO = 'La generación de imágenes todavía no está disponible para tu sistema.';

    /**
     * El tope diario configurado.
     *
     * @return int
     */
    public static function tope_diario(): int
    {
        $tope = (int) config('services.ia_imagenes.tope_diario', self::TOPE_DIARIO_POR_DEFECTO);

        return $tope > 0 ? $tope : self::TOPE_DIARIO_POR_DEFECTO;
    }

    /**
     * Cuántos pedidos hizo hoy el dueño (los que no llegaron al admin no cuentan).
     *
     * @param  int  $owner_id
     * @return int
     */
    public static function pedidos_de_hoy($owner_id): int
    {
        return IaImagenPedido::where('user_id', (int) $owner_id)
            ->where('created_at', '>=', Carbon::today())
            ->where('estado', '<>', IaImagenPedido::ESTADO_NO_DISPONIBLE)
            ->count();
    }

    /**
     * Crea el pedido (lo que hace la herramienta pedir_imagen). Devuelve
     * `['pedido' => IaImagenPedido|null, 'bloque' => array|null, 'texto' => string]`: con pedido, el
     * texto es lo que se le contesta al modelo; sin él (tope, datos que faltan), el texto explica por qué
     * para que el agente se lo cuente al dueño.
     *
     * @param  int  $owner_id
     * @param  \App\Models\AiMessage|null  $assistant_message  El mensaje que va a mostrar la imagen.
     * @param  array  $input  `prompt`, `tamano`, `estilo` (lo que mandó el modelo).
     * @return array{pedido: \App\Models\IaImagenPedido|null, bloque: array|null, texto: string, es_error: bool}
     */
    public static function pedir($owner_id, $assistant_message, array $input): array
    {
        $prompt = trim((string) ($input['prompt'] ?? ''));

        if ($prompt === '') {

            return self::sin_pedido('Falta `prompt`: describí la imagen que querés que se genere.', true);
        }

        if (!($assistant_message instanceof AiMessage) || (int) $assistant_message->id <= 0) {

            return self::sin_pedido('No hay un mensaje donde mostrar la imagen.', true);
        }

        $tope = self::tope_diario();

        if (self::pedidos_de_hoy($owner_id) >= $tope) {

            return self::sin_pedido(
                'No se pidió la imagen: el negocio ya llegó al tope de ' . $tope . ' imágenes por día. '
                . 'Contale a la persona que mañana puede pedir más, sin prometer otra cosa.',
                false
            );
        }

        $tamano = (string) ($input['tamano'] ?? 'cuadrada');

        if (!in_array($tamano, BloquesIaHelper::TAMANOS, true)) {
            $tamano = 'cuadrada';
        }

        $estilo = trim((string) ($input['estilo'] ?? ''));

        $pedido = IaImagenPedido::create([
            'uuid'          => (string) Str::uuid(),
            'user_id'       => (int) $owner_id,
            'ai_message_id' => (int) $assistant_message->id,
            'prompt'        => mb_substr($prompt, 0, BloquesIaHelper::MAX_PROMPT),
            'tamano'        => $tamano,
            'estilo'        => $estilo === '' ? null : mb_substr($estilo, 0, 200),
            'estado'        => IaImagenPedido::ESTADO_PENDIENTE,
        ]);

        return [
            'pedido'   => $pedido,
            'bloque'   => self::bloque_del_pedido($pedido),
            'texto'    => 'Listo: la imagen se está generando y aparece sola en este mismo mensaje en unos minutos. '
                . 'Decile a la persona en una línea que la estás haciendo; no digas que ya está ni pongas un link.',
            'es_error' => false,
        ];
    }

    /**
     * @param  string  $texto
     * @param  bool  $es_error
     * @return array{pedido: null, bloque: null, texto: string, es_error: bool}
     */
    protected static function sin_pedido($texto, $es_error): array
    {
        return ['pedido' => null, 'bloque' => null, 'texto' => $texto, 'es_error' => (bool) $es_error];
    }

    /**
     * El bloque `imagen_generada` de un pedido, con su estado de hoy.
     *
     * `error` es SIEMPRE un texto para el dueño, nunca el detalle técnico (ese queda en
     * ia_imagen_pedidos.error). Con `$error_para_el_dueno` se elige otro texto que el genérico (el del tope
     * diario, por ejemplo).
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @param  string|null  $error_para_el_dueno
     * @return array<string, mixed>
     */
    public static function bloque_del_pedido(IaImagenPedido $pedido, $error_para_el_dueno = null): array
    {
        $error = null;

        if ($pedido->estado === IaImagenPedido::ESTADO_FALLIDA) {
            $error = is_null($error_para_el_dueno) ? self::ERROR_PARA_EL_DUENO : (string) $error_para_el_dueno;
        } elseif ($pedido->estado === IaImagenPedido::ESTADO_NO_DISPONIBLE) {
            $error = self::NO_DISPONIBLE_PARA_EL_DUENO;
        }

        return BloquesIaHelper::imagen([
            'uuid'   => $pedido->uuid,
            'estado' => $pedido->estado,
            'prompt' => $pedido->prompt,
            'tamano' => $pedido->tamano,
            'error'  => $error,
        ]);
    }

    /**
     * Reescribe en el mensaje el bloque de este pedido con su estado actual. Con la fila del mensaje
     * tomada (ver el 🔴 del docblock de la clase). Si el mensaje no tiene el bloque (todavía no se
     * guardó, o se borró la conversación), no hace nada.
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @param  string|null  $error_para_el_dueno  Ver bloque_del_pedido().
     * @return void
     */
    public static function actualizar_bloque(IaImagenPedido $pedido, $error_para_el_dueno = null)
    {
        if (empty($pedido->ai_message_id)) {

            return;
        }

        DB::transaction(function () use ($pedido, $error_para_el_dueno) {

            $mensaje = AiMessage::where('id', (int) $pedido->ai_message_id)->lockForUpdate()->first();

            if (is_null($mensaje)) {

                return;
            }

            $bloques = is_array($mensaje->bloques) ? $mensaje->bloques : [];
            $tocado  = false;

            foreach ($bloques as $i => $bloque) {

                if (is_array($bloque)
                    && ($bloque['tipo'] ?? '') === BloquesIaHelper::TIPO_IMAGEN
                    && (string) ($bloque['uuid'] ?? '') === (string) $pedido->uuid) {

                    $bloques[$i] = self::bloque_del_pedido($pedido, $error_para_el_dueno);
                    $tocado      = true;
                }
            }

            if ($tocado) {
                $mensaje->bloques = $bloques;
                $mensaje->save();
            }
        });
    }

    /**
     * Marca un pedido como no disponible (el admin no tiene la cola) y actualiza su bloque.
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @param  string  $motivo
     * @return void
     */
    public static function marcar_no_disponible(IaImagenPedido $pedido, $motivo)
    {
        $pedido->estado = IaImagenPedido::ESTADO_NO_DISPONIBLE;
        $pedido->error  = mb_substr((string) $motivo, 0, 500);
        $pedido->save();

        self::actualizar_bloque($pedido);
    }

    /**
     * Marca un pedido como fallido y actualiza su bloque. No pisa uno que ya está `lista`.
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @param  string  $motivo  El detalle técnico (queda en el pedido, no lo ve el dueño).
     * @param  string|null  $para_el_dueno  Lo que ve el dueño en el bloque; null = el texto genérico.
     * @return void
     */
    public static function marcar_fallido(IaImagenPedido $pedido, $motivo, $para_el_dueno = null)
    {
        if ($pedido->estado === IaImagenPedido::ESTADO_LISTA) {

            return;
        }

        $pedido->estado = IaImagenPedido::ESTADO_FALLIDA;
        $pedido->error  = mb_substr((string) $motivo, 0, 500);
        $pedido->save();

        self::actualizar_bloque($pedido, $para_el_dueno);
    }

    /**
     * Cierra como fallidos los pedidos de un mensaje cuya respuesta falló: nunca se van a mostrar, y
     * así no cuentan como "en curso" ni quedan esperando para siempre.
     *
     * @param  int  $ai_message_id
     * @return void
     */
    public static function cerrar_los_del_mensaje($ai_message_id)
    {
        /* Lo llama el job en CUALQUIER respuesta que falla, también en el sistema y en WhatsApp. */
        if (!ColumnasDeLaAppIaHelper::hay_tabla('ia_imagen_pedidos')) {

            return;
        }

        try {

            IaImagenPedido::where('ai_message_id', (int) $ai_message_id)
                ->where('estado', IaImagenPedido::ESTADO_PENDIENTE)
                ->whereNull('enviado_al_admin_en')
                ->update([
                    'estado' => IaImagenPedido::ESTADO_FALLIDA,
                    'error'  => 'la respuesta del asistente falló antes de mandar el pedido',
                ]);

        } catch (\Throwable $e) {

            Log::warning('PedidosDeImagenIaHelper: no se pudieron cerrar los pedidos del mensaje', [
                'ai_message_id' => $ai_message_id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * El motivo de rechazo de la imagen que manda el admin (texto para el 422), o null. Por los BYTES,
     * nunca por la extensión.
     *
     * @param  mixed  $imagen  UploadedFile
     * @return string|null
     */
    public static function motivo_de_rechazo_del_resultado($imagen)
    {
        if (is_null($imagen) || is_array($imagen) || !is_object($imagen) || !method_exists($imagen, 'isValid') || !$imagen->isValid()) {

            return 'Falta la imagen o no llegó completa.';
        }

        if ($imagen->getSize() > self::MAX_MB_DEL_RESULTADO * 1024 * 1024) {

            return 'La imagen pesa más de ' . self::MAX_MB_DEL_RESULTADO . ' MB.';
        }

        $mime = AsistenteImagenHelper::media_type((string) file_get_contents($imagen->getRealPath()));

        if (is_null($mime) || !isset(self::MIMES_DEL_RESULTADO[$mime])) {

            return 'La imagen tiene que ser png, jpg o webp.';
        }

        return null;
    }

    /**
     * Guarda el resultado que mandó el admin (C1.4). Idempotente: un pedido que ya está `lista` no se
     * toca (un segundo `lista` del mismo uuid es un reintento del admin) y un `fallida` no pisa una
     * imagen que ya llegó. Devuelve el estado con que queda el pedido.
     *
     * @param  \App\Models\IaImagenPedido  $pedido
     * @param  string  $estado  lista | fallida
     * @param  mixed  $imagen  UploadedFile (con `lista`)
     * @param  string|null  $error
     * @return string
     */
    public static function recibir_resultado(IaImagenPedido $pedido, $estado, $imagen, $error)
    {
        if ($pedido->estado === IaImagenPedido::ESTADO_LISTA) {

            return IaImagenPedido::ESTADO_LISTA;
        }

        if ($estado !== IaImagenPedido::ESTADO_LISTA) {

            self::marcar_fallido($pedido, trim((string) $error) === '' ? 'el admin informó que no se pudo generar' : (string) $error);

            return IaImagenPedido::ESTADO_FALLIDA;
        }

        $binario = (string) file_get_contents($imagen->getRealPath());
        $mime    = AsistenteImagenHelper::media_type($binario);
        $ext     = self::MIMES_DEL_RESULTADO[$mime];
        $path    = 'ia_imagenes/' . (int) $pedido->user_id . '/' . $pedido->uuid . '.' . $ext;

        /* Disco `local` (privado): la imagen es del negocio y se sirve solo al dueño, por GET ia-imagenes/{uuid}. */
        Storage::disk('local')->put($path, $binario);

        $pedido->estado = IaImagenPedido::ESTADO_LISTA;
        $pedido->path   = $path;
        $pedido->error  = null;
        $pedido->save();

        self::actualizar_bloque($pedido);

        return IaImagenPedido::ESTADO_LISTA;
    }
}
