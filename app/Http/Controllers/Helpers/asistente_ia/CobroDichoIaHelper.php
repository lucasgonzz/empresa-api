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
     * Cuántos de los últimos mensajes de la persona se miran para decidir si el cobro pudo salir de un
     * adjunto (revisión independiente, importante 3: una foto de hace horas en el mismo tramo no explica
     * el cobro de un pedido dictado ahora).
     */
    const MENSAJES_CON_ADJUNTO = 2;

    /**
     * «Otra igual», «lo mismo que la anterior», «como la anterior»: la persona pide el cobro de la venta
     * de antes (revisión independiente, importante 2).
     */
    const PIDE_LO_MISMO = '/\b(otra igual|otra venta igual|lo mismo que (la|el) anterior|igual que (la|el) anterior|igual a (la|el) anterior|como (la|el) anterior|igual que (la|el) otr[ao]|como (la|el) otr[ao]|el mismo cobro|igual que antes|lo mismo que antes)\b/u';

    /**
     * El input de proponer_venta sin el cobro que la persona no dijo. Devuelve [input, descartado],
     * con `descartado` la lista de claves que se sacaron (para el aviso de la respuesta).
     *
     * Si la persona pidió «otra igual» y hay una venta confirmada antes en esta charla, su cobro (método,
     * caja, cuenta corriente) cuenta como dicho y completa lo que el modelo no mandó.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input
     * @return array{0: array, 1: array<int, string>}
     */
    public static function sin_lo_no_dicho(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        $tramo = self::tramo($contexto, $mensaje);

        // Sin conversación, o con un adjunto en lo último que mandó la persona: no se puede verificar.
        if (is_null($tramo)) {
            return [$input, []];
        }

        $dicho = $tramo['dicho'];

        if (!is_null($tramo['anterior']) && preg_match(self::PIDE_LO_MISMO, $dicho)) {

            list($input, $de_la_anterior) = self::con_el_cobro_de_la_anterior($input, $tramo['anterior']);

            // Lo de la venta anterior pasa a contar como dicho.
            $dicho .= self::normalizar($de_la_anterior) . ' ';
        }

        $metodo = EntradaDeCargaIa::texto($input, 'metodo_de_pago');
        $caja = EntradaDeCargaIa::texto($input, 'caja');
        $cobro = self::normalizar(EntradaDeCargaIa::texto($input, 'cobro'));

        if ($metodo === '' && $caja === '' && $cobro === '') {
            return [$input, []];
        }

        $descartado = [];

        if ($metodo !== '' && !self::nombra_el_metodo($dicho, $metodo, self::nombres_de_metodos())) {
            unset($input['metodo_de_pago']);
            $descartado[] = 'metodo_de_pago';
        }

        if ($caja !== '' && !self::nombra_la_caja($dicho, $caja, self::nombres_de_cajas($contexto))) {
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
     * El input completado con el cobro de la venta anterior en lo que el modelo no mandó, y el texto de
     * ese cobro (para sumarlo a lo dicho).
     *
     * @param  array  $input
     * @param  array  $anterior  El `resumen` de la tarjeta de la venta anterior.
     * @return array{0: array, 1: string}
     */
    protected static function con_el_cobro_de_la_anterior(array $input, array $anterior)
    {
        $cobro = isset($anterior['cobro']) ? (string) $anterior['cobro'] : '';
        $metodo = isset($anterior['metodo_de_pago']) ? (string) $anterior['metodo_de_pago'] : '';
        $caja = isset($anterior['caja']) ? (string) $anterior['caja'] : '';

        if ($cobro !== '' && EntradaDeCargaIa::texto($input, 'cobro') === '') {
            $input['cobro'] = $cobro;
        }

        if ($cobro !== 'cuenta_corriente') {

            if ($metodo !== '' && EntradaDeCargaIa::texto($input, 'metodo_de_pago') === '') {
                $input['metodo_de_pago'] = $metodo;
            }

            if ($caja !== '' && EntradaDeCargaIa::texto($input, 'caja') === '') {
                $input['caja'] = $caja;
            }
        }

        $texto = ($cobro === 'cuenta_corriente' ? 'cuenta corriente ' : 'pago ') . $metodo . ' ' . $caja;

        return [$input, $texto];
    }

    /**
     * Lo que escribió la persona en esta conversación desde la última venta registrada (antes de este
     * turno), normalizado y con un espacio en cada punta, y el `resumen` de esa venta (o null). Devuelve
     * null si no hay cómo verificarlo: no hay conversación, o la persona mandó una foto o un archivo en
     * sus últimos MENSAJES_CON_ADJUNTO mensajes.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @return array|null  {dicho: string, anterior: array|null}
     */
    public static function tramo(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        if (is_null($contexto->conversation) || empty($mensaje->id)) {
            return null;
        }

        $conversation_id = (int) $contexto->conversation->id;

        // La última venta confirmada de un turno ANTERIOR: las de este turno no cortan el tramo.
        $ultima = AiMessageAction::query()
            ->join('ai_messages', 'ai_messages.id', '=', 'ai_message_actions.ai_message_id')
            ->where('ai_messages.ai_conversation_id', $conversation_id)
            ->where('ai_message_actions.tipo', AiMessageAction::TIPO_VENTA)
            ->where('ai_message_actions.estado', AiMessageAction::ESTADO_CONFIRMADA)
            ->where('ai_message_actions.ai_message_id', '<', (int) $mensaje->id)
            ->orderBy('ai_message_actions.ai_message_id', 'DESC')
            ->orderBy('ai_message_actions.id', 'DESC')
            ->select('ai_message_actions.*')
            ->first();

        $desde = is_null($ultima) ? 0 : (int) $ultima->ai_message_id;

        $anterior = null;

        if (!is_null($ultima)) {
            $datos = is_array($ultima->datos) ? $ultima->datos : [];
            $anterior = isset($datos['resumen']) && is_array($datos['resumen']) ? $datos['resumen'] : null;
        }

        $mensajes = AiMessage::where('ai_conversation_id', $conversation_id)
            ->where('rol', 'user')
            ->where('id', '<', (int) $mensaje->id)
            ->where('id', '>', $desde)
            ->where('created_at', '>=', Carbon::now()->subHours(AiMessageAction::HORAS_VENCIMIENTO))
            ->orderBy('id', 'DESC')
            ->limit(self::MENSAJES_DE_LA_PERSONA)
            ->get();

        if (self::trae_adjuntos($mensajes->take(self::MENSAJES_CON_ADJUNTO))) {
            return null;
        }

        return [
            'dicho'    => ' ' . self::normalizar(implode(' ', $mensajes->pluck('contenido')->all())) . ' ',
            'anterior' => $anterior,
        ];
    }

    /**
     * Lo dicho (ver tramo()), o null si no se puede verificar.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @return string|null
     */
    public static function lo_dicho(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        $tramo = self::tramo($contexto, $mensaje);

        return is_null($tramo) ? null : $tramo['dicho'];
    }

    /**
     * true si alguno de los mensajes trae una foto o un archivo.
     *
     * @param  \Illuminate\Support\Collection  $mensajes
     * @return bool
     */
    protected static function trae_adjuntos($mensajes)
    {
        $ids = $mensajes->pluck('id')->all();

        if (!count($ids)) {
            return false;
        }

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
     * Los nombres de los métodos de pago del catálogo (para saber qué palabra distingue a cada uno).
     *
     * @return array<int, string>
     */
    protected static function nombres_de_metodos()
    {
        $nombres = [];

        foreach (OpcionesDeCargaIaHelper::metodos_de_pago() as $metodo) {
            $nombres[] = (string) $metodo->name;
        }

        return $nombres;
    }

    /**
     * Los nombres de las cajas del dueño.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @return array<int, string>
     */
    protected static function nombres_de_cajas(ContextoDeCargaIa $contexto)
    {
        $nombres = [];

        foreach (OpcionesDeCargaIaHelper::cajas_de_la_cuenta($contexto->owner_id) as $caja) {
            $nombres[] = (string) $caja->name;
        }

        return $nombres;
    }

    /**
     * Las palabras no genéricas de un nombre que ningún OTRO nombre de la lista tiene (revisión
     * independiente, menor 6: «Tarjeta de crédito» y «Tarjeta de débito» comparten «tarjeta»; lo que
     * dice cuál es, es «crédito»). Si no queda ninguna, todas las no genéricas.
     *
     * @param  string  $nombre
     * @param  array<int, string>  $otros
     * @return array<int, string>
     */
    public static function palabras_distintivas($nombre, array $otros)
    {
        $propias = [];

        foreach (explode(' ', self::normalizar($nombre)) as $palabra) {
            if ($palabra !== '' && !in_array($palabra, self::PALABRAS_GENERICAS, true)) {
                $propias[] = $palabra;
            }
        }

        $propias = array_values(array_unique($propias));

        $de_otros = [];

        foreach ($otros as $otro) {

            if (self::normalizar($otro) === self::normalizar($nombre)) {
                continue;
            }

            foreach (explode(' ', self::normalizar($otro)) as $palabra) {
                $de_otros[$palabra] = true;
            }
        }

        $distintivas = array_values(array_filter($propias, function ($palabra) use ($de_otros) {
            return !isset($de_otros[$palabra]);
        }));

        return count($distintivas) ? $distintivas : $propias;
    }

    /**
     * true si la palabra (o raíz) aparece en lo dicho: con 5 letras o más, al principio de una palabra
     * («transf» en «transfirio»); más corta, como palabra entera («modo» no es «modos»).
     *
     * @param  string  $dicho  Normalizado, con espacios en las puntas.
     * @param  string  $raiz
     * @return bool
     */
    protected static function aparece($dicho, $raiz)
    {
        $raiz = (string) $raiz;

        if ($raiz === '') {
            return false;
        }

        if (mb_strlen($raiz) >= 5) {
            return mb_strpos($dicho, ' ' . $raiz) !== false;
        }

        return mb_strpos($dicho, ' ' . $raiz . ' ') !== false;
    }

    /**
     * true si lo dicho nombra el método: su palabra DISTINTIVA (o un sinónimo, o una parecida).
     *
     * @param  string  $dicho  Normalizado, con espacios en las puntas.
     * @param  string  $metodo
     * @param  array<int, string>  $otros  Los nombres de todos los métodos.
     * @return bool
     */
    public static function nombra_el_metodo($dicho, $metodo, array $otros = [])
    {
        $distintivas = self::palabras_distintivas($metodo, $otros);

        foreach ($distintivas as $palabra) {

            $raices = [mb_strlen($palabra) > 6 ? mb_substr($palabra, 0, 6) : $palabra];

            if (isset(self::SINONIMOS_DE_METODO[$palabra])) {
                $raices = array_merge($raices, self::SINONIMOS_DE_METODO[$palabra]);
            }

            foreach ($raices as $raiz) {
                if (self::aparece($dicho, $raiz)) {
                    return true;
                }
            }
        }

        return NombreParecidoIaHelper::nombra_alguna_palabra($dicho, implode(' ', self::largas($distintivas)), self::PALABRAS_GENERICAS);
    }

    /**
     * true si lo dicho nombra la caja: su palabra distintiva (o una parecida). Si lo único que la
     * distingue es un número («Caja 1»), hace falta el nombre entero: un «1» suelto no es la caja.
     *
     * @param  string  $dicho
     * @param  string  $caja
     * @param  array<int, string>  $otras  Los nombres de todas las cajas.
     * @return bool
     */
    public static function nombra_la_caja($dicho, $caja, array $otras = [])
    {
        $distintivas = self::palabras_distintivas($caja, $otras);

        $con_letras = array_values(array_filter($distintivas, function ($palabra) {
            return !ctype_digit($palabra);
        }));

        if (!count($con_letras)) {
            $entero = self::normalizar($caja);

            return $entero !== '' && mb_strpos($dicho, ' ' . $entero . ' ') !== false;
        }

        foreach ($con_letras as $palabra) {
            if (self::aparece($dicho, $palabra)) {
                return true;
            }
        }

        return NombreParecidoIaHelper::nombra_alguna_palabra($dicho, implode(' ', self::largas($con_letras)), self::PALABRAS_GENERICAS);
    }

    /**
     * Las palabras de 5 letras o más: las cortas solo cuentan dichas tal cual («modo» no es «modos»).
     *
     * @param  array<int, string>  $palabras
     * @return array<int, string>
     */
    protected static function largas(array $palabras)
    {
        return array_values(array_filter($palabras, function ($palabra) {
            return mb_strlen($palabra) >= 5;
        }));
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
