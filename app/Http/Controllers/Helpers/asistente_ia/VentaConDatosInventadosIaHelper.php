<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageAction;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Guarda de VERACIDAD de las ventas: el texto no puede decir que una venta se carga con otra fecha, ni
 * que quedó cobrada de una forma que la tarjeta no tiene (misión asistente-mejoras-4-dias, 16/11/2026,
 * rama A, P1 — hallazgo V1).
 *
 * 🔴 EL CASO REAL (Truvari, 13/11/2026, conversaciones 25 a 27). Fernando pidió «una venta del día de
 * ayer… del 11 de noviembre». El asistente contestó «Pasame eso y la cargo con fecha 11/11/2026», «La
 * fecha quedó tomada como miércoles 11/11/2026», «Venta N° 3101 registrada… Quedó cargada con fecha
 * 11/11/2026» y, con la 3103, «Date: sábado 7/11». proponer_venta NO tiene fecha de venta (solo
 * `fecha_entrega`, que es otra cosa): las tres ventas quedaron del 13/11, y al leer la 3102 el propio
 * sistema le dijo «del 13/11». El dueño creyó durante una hora que tenía cargadas ventas de otros días.
 *
 * Cargar una venta con fecha anterior NO entra en esta misión (mueve cajas y arqueos cerrados: es
 * decisión de producto, ver el plan). Lo que entra es que el asistente no lo afirme: la guarda corre
 * como las de VeracidadDelTurnoIaHelper (mismo y único reintento con una nota que dice la verdad, y si
 * el texto todavía lo afirma, se cambian SOLO esas oraciones por el aviso honesto).
 *
 * Cobro: si en este turno se armó o se registró una venta y el texto dice «cobrada con transferencia»
 * cuando la tarjeta dice efectivo (o cuenta corriente), también dispara.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class VentaConDatosInventadosIaHelper
{
    /** El nombre de la guarda en AsistenteIaService::GUARDAS_DE_VERACIDAD. */
    const GUARDA = 'venta_inventada';

    /** El aviso honesto que reemplaza las oraciones de la fecha. */
    const AVISO_FECHA = 'Ojo: las ventas que registro quedan con la fecha de hoy: con una fecha anterior no las puedo cargar.';

    /** El aviso honesto que reemplaza las oraciones del cobro. */
    const AVISO_COBRO = 'Ojo: el cobro de la venta es el que figura en la tarjeta; si fue de otra forma, decímelo y la corrijo.';

    const DIAS = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];

    /** Cobros que el texto puede afirmar, normalizados, con la palabra que los delata en la tarjeta. */
    const COBROS = [
        'efectivo'         => 'efectivo',
        'transferencia'    => 'transferencia',
        'debito'           => 'debito',
        'credito'          => 'credito',
        'mercado pago'     => 'mercado',
        'cuenta corriente' => 'cuenta corriente',
    ];

    /**
     * true si el turno es de una venta: se pidió proponer_venta, el texto o lo último que escribió la
     * persona habla de una venta.
     *
     * @param  string  $texto
     * @param  array<int, string>  $herramientas_pedidas
     * @param  \App\Models\AiMessage  $assistant
     * @return bool
     */
    public static function es_turno_de_venta($texto, array $herramientas_pedidas, AiMessage $assistant)
    {
        if (in_array('proponer_venta', $herramientas_pedidas, true)) {
            return true;
        }

        if (preg_match('/\bventa/u', self::normalizar($texto))) {
            return true;
        }

        $ultimos = AiMessage::where('ai_conversation_id', (int) $assistant->ai_conversation_id)
            ->where('rol', 'user')
            ->where('id', '<', (int) $assistant->id)
            ->orderBy('id', 'DESC')
            ->limit(3)
            ->pluck('contenido')
            ->all();

        return (bool) preg_match('/\b(venta|vend)/u', self::normalizar(implode(' ', $ultimos)));
    }

    /**
     * true si alguna oración afirma que una venta se carga o quedó con una fecha que no es hoy.
     *
     * @param  string  $texto
     * @param  \Carbon\Carbon|null  $hoy
     * @return bool
     */
    public static function afirma_otra_fecha($texto, $hoy = null)
    {
        $hoy = is_null($hoy) ? Carbon::today() : $hoy;

        return VeracidadDelTurnoIaHelper::alguna_oracion($texto, function ($oracion) use ($hoy) {
            return self::oracion_afirma_otra_fecha($oracion, $hoy);
        });
    }

    /**
     * La oración pone (o promete) la venta en otra fecha.
     *
     * @param  string  $oracion
     * @param  \Carbon\Carbon  $hoy
     * @return bool
     */
    public static function oracion_afirma_otra_fecha($oracion, $hoy)
    {
        $o = self::normalizar($oracion);

        // La entrega es otra cosa (fecha_entrega existe); y el repaso de una venta con su N° es un dato.
        if (preg_match('/\bentreg|\bn ?\d+\b|\bnro\b|\bnumero \d/u', $o)) {
            return false;
        }

        // Una tarea, un recordatorio o un vencimiento sí tienen fecha: no son la venta.
        if (preg_match('/\b(tarea|recordatorio|agend|aviso|avisar|vence|vencimiento|cheque|cuota)/u', $o)) {
            return false;
        }

        // Lo que solo puede ser la fecha de la venta…
        $de_fecha = '/\b(con fecha|de fecha|fecha ?:|date ?:|fechad[ao]s?|la fecha (quedo|queda|la tome|es)|fecha tomada|tomada como)/u';

        // …y lo que lo es solo si la oración habla de cargar o registrar la venta.
        $generica = '/\b(para el( dia)?|del dia|el dia)\b/u';
        $de_venta = '/\b(venta|ventas|la cargo|las cargo|cargarla|cargarlas|cargada|cargadas|registrada|registradas|la registro|la armo|armarla)\b/u';

        if (!preg_match($de_fecha, $o) && !(preg_match($generica, $o) && preg_match($de_venta, $o))) {
            return false;
        }

        return self::nombra_otro_dia($o, $hoy);
    }

    /**
     * true si el texto (normalizado) nombra un día que no es hoy: dd/mm distinto, «ayer», o un día de
     * la semana que no es el de hoy.
     *
     * @param  string  $o
     * @param  \Carbon\Carbon  $hoy
     * @return bool
     */
    protected static function nombra_otro_dia($o, $hoy)
    {
        if (preg_match_all('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/u', $o, $fechas, PREG_SET_ORDER)) {

            foreach ($fechas as $f) {

                if ((int) $f[1] !== (int) $hoy->day || (int) $f[2] !== (int) $hoy->month) {
                    return true;
                }
            }
        }

        if (preg_match('/\b(ayer|anteayer|antes de ayer)\b/u', $o)) {
            return true;
        }

        $de_hoy = self::DIAS[(int) $hoy->dayOfWeek];

        foreach (self::DIAS as $dia) {

            if ($dia !== $de_hoy && preg_match('/\b' . $dia . '\b/u', $o)) {
                return true;
            }
        }

        return false;
    }

    /**
     * true si el texto afirma un cobro que las ventas de este turno no tienen. Sin ventas en el turno,
     * no dispara (lo que da por hecho una carga sin acción lo mira TarjetaPrometidaIaHelper).
     *
     * @param  string  $texto
     * @param  \App\Models\AiMessage  $assistant
     * @return bool
     */
    public static function afirma_otro_cobro($texto, AiMessage $assistant)
    {
        $ventas = AiMessageAction::where('ai_message_id', (int) $assistant->id)
            ->where('tipo', AiMessageAction::TIPO_VENTA)
            ->get();

        if (!count($ventas)) {
            return false;
        }

        $de_las_tarjetas = '';

        foreach ($ventas as $venta) {

            $datos = is_array($venta->datos) ? $venta->datos : [];
            $resumen = isset($datos['resumen']) && is_array($datos['resumen']) ? $datos['resumen'] : [];

            $de_las_tarjetas .= ' ' . (isset($resumen['metodo_de_pago']) ? $resumen['metodo_de_pago'] : '')
                . ' ' . (isset($resumen['caja']) ? $resumen['caja'] : '')
                . ' ' . (isset($resumen['cobro']) ? str_replace('_', ' ', (string) $resumen['cobro']) : '');
        }

        $de_las_tarjetas = ' ' . self::normalizar($de_las_tarjetas) . ' ';

        return VeracidadDelTurnoIaHelper::alguna_oracion($texto, function ($oracion) use ($de_las_tarjetas) {
            return self::oracion_afirma_otro_cobro($oracion, $de_las_tarjetas);
        });
    }

    /**
     * @param  string  $oracion
     * @param  string  $de_las_tarjetas  Normalizado con espacios en las puntas.
     * @return bool
     */
    protected static function oracion_afirma_otro_cobro($oracion, $de_las_tarjetas)
    {
        $o = self::normalizar($oracion);

        // Una pregunta o un condicional («si te pagó en efectivo, decime») no afirman nada.
        if (VeracidadDelTurnoIaHelper::es_pregunta($oracion) || preg_match('/\b(si|cuando|decime|avisame)\b/u', $o)) {
            return false;
        }

        if (!preg_match('/\b(cobrad[ao]s?|quedo cobrad\w*|la cobre|se cobro|pagad[ao]s?|pago|pagaste|entro)\b/u', $o)) {
            return false;
        }

        foreach (self::COBROS as $dicho => $en_la_tarjeta) {

            if (preg_match('/\b' . $dicho . '\b/u', $o) && mb_strpos($de_las_tarjetas, ' ' . $en_la_tarjeta) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * La nota del reintento.
     *
     * @return string
     */
    public static function nota_para_reintentar()
    {
        return '[Aviso del sistema: tu respuesta afirma algo de una venta que no es así. Las ventas que se registran desde acá '
            . 'quedan SIEMPRE con la fecha de hoy: proponer_venta no tiene fecha de venta (fecha_entrega es la fecha de entrega, '
            . 'otra cosa), así que no digas que la cargaste ni que la vas a cargar con otra fecha; si la persona pidió otra fecha, '
            . 'decile que desde acá queda con la fecha de hoy. Y el cobro es el que figura en la tarjeta: no digas otro. '
            . VeracidadDelTurnoIaHelper::FRASE_DE_LA_RESPUESTA_COMPLETA;
    }

    /**
     * El texto sin las oraciones que afirman otra fecha u otro cobro, con su aviso.
     *
     * @param  string  $texto
     * @param  \App\Models\AiMessage  $assistant
     * @param  \Carbon\Carbon|null  $hoy
     * @return string
     */
    public static function texto_honesto($texto, AiMessage $assistant, $hoy = null)
    {
        $hoy = is_null($hoy) ? Carbon::today() : $hoy;

        if (self::afirma_otra_fecha($texto, $hoy)) {
            $texto = VeracidadDelTurnoIaHelper::sin_las_oraciones($texto, function ($oracion) use ($hoy) {
                return self::oracion_afirma_otra_fecha($oracion, $hoy);
            }, self::AVISO_FECHA);
        }

        if (self::afirma_otro_cobro($texto, $assistant)) {

            $ventas = AiMessageAction::where('ai_message_id', (int) $assistant->id)->where('tipo', AiMessageAction::TIPO_VENTA)->get();
            $de_las_tarjetas = '';

            foreach ($ventas as $venta) {
                $datos = is_array($venta->datos) ? $venta->datos : [];
                $resumen = isset($datos['resumen']) && is_array($datos['resumen']) ? $datos['resumen'] : [];
                $de_las_tarjetas .= ' ' . implode(' ', array_map('strval', array_filter([
                    isset($resumen['metodo_de_pago']) ? $resumen['metodo_de_pago'] : null,
                    isset($resumen['caja']) ? $resumen['caja'] : null,
                    isset($resumen['cobro']) ? str_replace('_', ' ', (string) $resumen['cobro']) : null,
                ])));
            }

            $de_las_tarjetas = ' ' . self::normalizar($de_las_tarjetas) . ' ';

            $texto = VeracidadDelTurnoIaHelper::sin_las_oraciones($texto, function ($oracion) use ($de_las_tarjetas) {
                return self::oracion_afirma_otro_cobro($oracion, $de_las_tarjetas);
            }, self::AVISO_COBRO);
        }

        return $texto;
    }

    /**
     * Minúsculas, sin tildes, sin signos salvo «/» y «:» (las fechas y «Fecha:») y un solo espacio.
     *
     * @param  string  $texto
     * @return string
     */
    public static function normalizar($texto)
    {
        $limpio = Str::lower(Str::ascii((string) $texto));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9\/: ]+/', ' ', $limpio)));
    }
}
