<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageAction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * El cobro de una venta que la persona NO dijo no se hereda (misión asistente-mejoras-4-dias,
 * 16/11/2026, rama A, P1 — hallazgo V2).
 *
 * 🔴 EL CASO REAL (Truvari, 13/11/2026, conversación 25, por audio). Fernando dictó «El cliente se llama
 * Pomodoro Pablo y llevó una caja de Mascota Malbec y una caja de Salentine…», sin decir nada del cobro.
 * Con la confianza en «directo» la venta 3101 se registró sola «cobrada con transferencia a la caja
 * BRUBANK FER»: el método y la caja los sacó el modelo de otra charla (la del Mix Clásico, donde ESA
 * plata había entrado a Brubank Fer). Recién después el dueño dijo cómo había pagado, y la venta ya
 * estaba hecha.
 *
 * Ahora proponer_venta mira lo que ESCRIBIÓ la persona en esta conversación desde la última venta
 * registrada (sin contar las de este mismo turno, para que un lote de ventas de un solo pedido no se
 * quede sin cobro): el método de pago, la caja y la cuenta corriente que el modelo manda y que no
 * aparecen ahí se descartan, y la herramienta los pregunta como si no hubieran venido (`faltan`). Es el
 * mismo criterio que ProveedorDeLaFacturaIaHelper::lo_dijo_la_persona() para el proveedor de una
 * compra: separa «lo dijo el dueño» de «lo puso el modelo».
 *
 * ⚠️ CON UN ADJUNTO EN EL MEDIO NO SE DESCARTA NADA. Una planilla o una foto de un pedido trae el cobro
 * escrito, y ni la transcripción de la foto ni el texto del Excel quedan en `contenido`: si la persona
 * mandó una imagen o un archivo desde la última venta, el cobro pudo salir de ahí y no se puede
 * verificar contra el texto. Se deja como vino (las otras guardas de la venta siguen igual).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class CobroDichoIaHelper
{
    /** Cuántos mensajes de la persona se leen como mucho (los más nuevos). */
    const MENSAJES_DE_LA_PERSONA = 20;

    /**
     * Palabras de un método de pago o de una caja que no alcanzan solas para decir que la persona lo
     * nombró: «pago» está en «pagó en efectivo» y «caja» en cualquier caja.
     */
    const PALABRAS_GENERICAS = ['pago', 'pagos', 'caja', 'cajas', 'cuenta', 'banco', 'metodo', 'medio', 'de', 'del', 'con', 'en'];

    /**
     * Formas de decir cada método que no repiten su nombre. La clave es una palabra del nombre del
     * método (normalizada); el valor, raíces que en lo dicho cuentan como haberlo nombrado.
     */
    const SINONIMOS_DE_METODO = [
        'transferencia' => ['transf', 'cbu', 'alias'],
        'efectivo'      => ['efectiv', 'cash', 'billete'],
        'debito'        => ['debit'],
        'credito'       => ['credit'],
        'mercado'       => ['mercadopago', 'mp'],
        'qr'            => ['qr'],
    ];

    /** Raíces que dicen «a cuenta corriente». */
    const DICHOS_DE_CUENTA_CORRIENTE = ['corriente', 'cta cte', 'ctacte', 'a cuenta', 'a la cuenta', 'en la cuenta', 'a su cuenta', 'fiad', 'fiar', 'le debe', 'me debe', 'queda debiendo', 'quedo debiendo', 'a credito'];

    /**
     * El input de proponer_venta sin el cobro que la persona no dijo. Devuelve [input, descartado],
     * con `descartado` la lista de claves que se sacaron (para el aviso de la respuesta).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input
     * @return array{0: array, 1: array<int, string>}
     */
    public static function sin_lo_no_dicho(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        $metodo = EntradaDeCargaIa::texto($input, 'metodo_de_pago');
        $caja = EntradaDeCargaIa::texto($input, 'caja');
        $cobro = self::normalizar(EntradaDeCargaIa::texto($input, 'cobro'));

        if ($metodo === '' && $caja === '' && $cobro === '') {
            return [$input, []];
        }

        $dicho = self::lo_dicho($contexto, $mensaje);

        // Sin texto que leer (o con un adjunto en el medio) no se puede verificar: queda como vino.
        if (is_null($dicho)) {
            return [$input, []];
        }

        $descartado = [];

        if ($metodo !== '' && !self::nombra_el_metodo($dicho, $metodo)) {
            unset($input['metodo_de_pago']);
            $descartado[] = 'metodo_de_pago';
        }

        if ($caja !== '' && !NombreParecidoIaHelper::nombra_alguna_palabra($dicho, $caja, self::PALABRAS_GENERICAS)) {
            unset($input['caja']);
            $descartado[] = 'caja';
        }

        $es_cuenta_corriente = in_array(str_replace(' ', '_', $cobro), ['cuenta_corriente', 'cta_cte', 'cuenta'], true);

        if ($es_cuenta_corriente && !self::dice_cuenta_corriente($dicho)) {
            unset($input['cobro']);
            $descartado[] = 'cobro';
        }

        /*
         * «contado» sin método dicho ni nada que suene a cobro tampoco lo dijo la persona: si el método
         * se descartó y el texto no dice cómo pagó, el cobro vuelve a preguntarse (con cliente, «¿contado
         * o cuenta corriente?»; sin cliente, la venta igual es de contado y se pregunta el método).
         */
        if (!$es_cuenta_corriente && $cobro !== '' && in_array('metodo_de_pago', $descartado, true) && !self::dice_que_pago($dicho)) {
            unset($input['cobro']);
            $descartado[] = 'cobro';
        }

        return [$input, $descartado];
    }

    /**
     * Lo que escribió la persona en esta conversación desde la última venta registrada (antes de este
     * turno), normalizado y con un espacio en cada punta; null si no hay cómo verificarlo (no hay
     * conversación, o la persona mandó una foto o un archivo en ese tramo).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @return string|null
     */
    public static function lo_dicho(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        if (is_null($contexto->conversation) || empty($mensaje->id)) {
            return null;
        }

        $conversation_id = (int) $contexto->conversation->id;

        // La última venta confirmada de un turno ANTERIOR: las de este turno no cortan el tramo.
        $desde = (int) DB::table('ai_message_actions')
            ->join('ai_messages', 'ai_messages.id', '=', 'ai_message_actions.ai_message_id')
            ->where('ai_messages.ai_conversation_id', $conversation_id)
            ->where('ai_message_actions.tipo', AiMessageAction::TIPO_VENTA)
            ->where('ai_message_actions.estado', AiMessageAction::ESTADO_CONFIRMADA)
            ->where('ai_message_actions.ai_message_id', '<', (int) $mensaje->id)
            ->max('ai_message_actions.ai_message_id');

        $mensajes = AiMessage::where('ai_conversation_id', $conversation_id)
            ->where('rol', 'user')
            ->where('id', '<', (int) $mensaje->id)
            ->where('id', '>', $desde)
            ->where('created_at', '>=', Carbon::now()->subHours(AiMessageAction::HORAS_VENCIMIENTO))
            ->orderBy('id', 'DESC')
            ->limit(self::MENSAJES_DE_LA_PERSONA)
            ->get();

        $ids = $mensajes->pluck('id')->all();

        if (count($ids) && self::trae_adjuntos($mensajes, $ids)) {
            return null;
        }

        return ' ' . self::normalizar(implode(' ', $mensajes->pluck('contenido')->all())) . ' ';
    }

    /**
     * true si alguno de los mensajes trae una foto o un archivo.
     *
     * @param  \Illuminate\Support\Collection  $mensajes
     * @param  array<int, int>  $ids
     * @return bool
     */
    protected static function trae_adjuntos($mensajes, array $ids)
    {
        if (DB::table('ai_message_imagenes')->whereIn('ai_message_id', $ids)->exists()) {
            return true;
        }

        if (Schema::hasColumn('ai_messages', 'archivos')) {

            foreach ($mensajes as $m) {

                $archivos = $m->archivos;

                if (is_array($archivos) && count($archivos)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * true si lo dicho nombra el método: alguna palabra no genérica de su nombre (o un sinónimo).
     *
     * @param  string  $dicho  Normalizado, con espacios en las puntas.
     * @param  string  $metodo
     * @return bool
     */
    public static function nombra_el_metodo($dicho, $metodo)
    {
        foreach (explode(' ', self::normalizar($metodo)) as $palabra) {

            if ($palabra === '' || in_array($palabra, self::PALABRAS_GENERICAS, true)) {
                continue;
            }

            $raices = [mb_substr($palabra, 0, 6)];

            if (isset(self::SINONIMOS_DE_METODO[$palabra])) {
                $raices = array_merge($raices, self::SINONIMOS_DE_METODO[$palabra]);
            }

            foreach ($raices as $raiz) {

                // La raíz al principio de una palabra: «transf» en «transfirio».
                if (mb_strlen($raiz) >= 2 && mb_strpos($dicho, ' ' . $raiz) !== false) {
                    return true;
                }
            }
        }

        return NombreParecidoIaHelper::nombra_alguna_palabra($dicho, $metodo, self::PALABRAS_GENERICAS);
    }

    /**
     * true si lo dicho manda la venta a cuenta corriente.
     *
     * @param  string  $dicho
     * @return bool
     */
    public static function dice_cuenta_corriente($dicho)
    {
        foreach (self::DICHOS_DE_CUENTA_CORRIENTE as $raiz) {

            if (mb_strpos($dicho, ' ' . $raiz) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * true si lo dicho habla del cobro aunque no nombre el método («ya pagó», «lo cobré»).
     *
     * @param  string  $dicho
     * @return bool
     */
    public static function dice_que_pago($dicho)
    {
        foreach ([' pag', ' cobr', ' abon', ' contado'] as $raiz) {

            if (mb_strpos($dicho, $raiz) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minúsculas, sin tildes, sin signos y con un solo espacio.
     *
     * @param  string  $texto
     * @return string
     */
    public static function normalizar($texto)
    {
        $limpio = Str::lower(Str::ascii((string) $texto));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', $limpio)));
    }
}
