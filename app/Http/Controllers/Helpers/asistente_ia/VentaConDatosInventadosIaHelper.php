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
 * Total (31/10 #8, misión asistente-diario-20261116): si el texto cita como total de la venta un monto que
 * la tarjeta del turno no tiene («$30.516,79» cuando la tarjeta dice $31.470,43), también dispara, y la
 * oración se cambia por el total de la tarjeta con el porqué (ver afirma_otro_total()).
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

    /**
     * El comienzo del aviso que reemplaza las oraciones que citan otro total (31/10 #8, misión
     * asistente-diario-20261116). Sigue con el total de la tarjeta y el porqué (ver aviso_de_total()).
     */
    const AVISO_TOTAL = 'Ojo: el total de la venta es el de la tarjeta';

    /** Diferencia en pesos que todavía es «el mismo total» (centavos de redondeo). */
    const TOLERANCIA_TOTAL = 0.5;

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
     * true si en ESTE turno hubo una venta: se pidió proponer_venta o el mensaje dejó una tarjeta (o una
     * venta registrada) de tipo venta.
     *
     * Revisión independiente (16/11/2026, bloqueante 1): antes alcanzaba con que el texto o la persona
     * hablaran de «ventas», y disparaba sobre informes de ventas pasadas («Las ventas del día de ayer
     * suman…»). Lo que se pierde a sabiendas: la promesa SIN herramienta («Pasame el detalle y la cargo
     * con fecha 11/11») ya no dispara; el texto de la venta que después se arma, sí.
     *
     * @param  string  $texto  Sin uso: queda por la firma de la llamada.
     * @param  array<int, string>  $herramientas_pedidas
     * @param  \App\Models\AiMessage  $assistant
     * @return bool
     */
    public static function es_turno_de_venta($texto, array $herramientas_pedidas, AiMessage $assistant)
    {
        if (in_array('proponer_venta', $herramientas_pedidas, true)) {
            return true;
        }

        return AiMessageAction::where('ai_message_id', (int) $assistant->id)
            ->where('tipo', AiMessageAction::TIPO_VENTA)
            ->exists();
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

        // El rótulo «Date:»/«Fecha:» al empezar es el resumen de lo que se acaba de cargar (la 3103 del caso real).
        // …salvo que hable de VARIAS ventas («Fecha: 15/11 — 14 ventas por $X» es un informe).
        if (preg_match('/^(fecha|date) ?:/u', $o)) {
            return !preg_match('/\bventas\b/u', $o) && self::nombra_otro_dia($o, $hoy);
        }

        // La fecha puesta a la venta («con fecha», «para el sábado», «tomada como»)…
        $de_fecha = '/\b(con fecha|de fecha|fechad[ao]s?|la fecha|fecha tomada|tomada como|para el( dia)?|del dia|el dia)\b/u';

        /*
         * …con un verbo de CARGA en la misma oración (revisión independiente, bloqueante 1): un informe
         * («tuviste 5 ventas», «hay 3 ventas con fecha 12/11 sin cobrar») no carga nada.
         */
        // «quedó/queda» solo con «cargada/registrada/anotada» o con «la venta» de sujeto: «Las ventas del día
        // de ayer quedaron en $X» es un informe (re-verificación de la revisión).
        $de_carga = '/\b(cargo|cargue|cargada|cargadas|cargarla|cargarlas|cargar|registro|registre|registrada|registradas|registrarla|anotada|la armo|armarla|armada|tomada|tome|la venta (quedo|queda))\b/u';

        if (!preg_match($de_fecha, $o) || !preg_match($de_carga, $o)) {
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
     * LAS TARJETAS DE VENTA DE ESTE TURNO, con lo que hace falta para mirar un total citado (31/10 #8).
     *
     * Por cada una: `total`, los montos que la tarjeta también muestra y que el texto puede citar sin
     * que sea un error (`conocidos`: subtotal, descuento, precios y subtotales de cada renglón y de cada
     * promo) y el `porque` del total (ver porque_del_total()).
     *
     * @param  \App\Models\AiMessage  $assistant
     * @return array<int, array{total: float, conocidos: array<int, float>, porque: string}>
     */
    protected static function tarjetas_de_venta(AiMessage $assistant)
    {
        $ventas = AiMessageAction::where('ai_message_id', (int) $assistant->id)
            ->where('tipo', AiMessageAction::TIPO_VENTA)
            ->get();

        $tarjetas = [];

        foreach ($ventas as $venta) {

            $datos = is_array($venta->datos) ? $venta->datos : [];
            $resumen = isset($datos['resumen']) && is_array($datos['resumen']) ? $datos['resumen'] : [];

            if (!isset($resumen['total']) || !is_numeric($resumen['total'])) {
                continue;
            }

            $conocidos = [];

            if (isset($resumen['sub_total']) && is_numeric($resumen['sub_total'])) {
                $conocidos[] = (float) $resumen['sub_total'];
            }

            if (isset($resumen['descuento']['monto']) && is_numeric($resumen['descuento']['monto'])) {
                $conocidos[] = (float) $resumen['descuento']['monto'];
            }

            foreach (['articulos', 'promos'] as $clave) {
                $renglones = isset($resumen[$clave]) && is_array($resumen[$clave]) ? $resumen[$clave] : [];

                foreach ($renglones as $renglon) {
                    foreach (['precio', 'subtotal'] as $campo) {
                        if (isset($renglon[$campo]) && is_numeric($renglon[$campo])) {
                            $conocidos[] = (float) $renglon[$campo];
                        }
                    }
                }
            }

            $tarjetas[] = [
                'total'     => (float) $resumen['total'],
                'conocidos' => $conocidos,
                'porque'    => self::porque_del_total($resumen),
            ];
        }

        return $tarjetas;
    }

    /**
     * POR QUÉ EL TOTAL DE LA TARJETA PUEDE NO SER LA CUENTA «PRECIO × CANTIDAD − DESCUENTO» QUE HIZO EL
     * MODELO (31/10 #8): el descuento o recargo del método de pago que ya llevan los precios, la lista de
     * precios, el descuento de cada artículo y el descuento de la venta. '' si no hay nada que explicar.
     *
     * @param  array  $resumen  El `resumen` de la tarjeta (PropuestaVentaIaHelper::resumen()).
     * @return string
     */
    public static function porque_del_total(array $resumen)
    {
        $partes = [];

        $metodo = isset($resumen['metodo_de_pago']) && $resumen['metodo_de_pago'] !== '' ? (string) $resumen['metodo_de_pago'] : 'el método de pago';

        if (isset($resumen['porcentaje_del_metodo']) && is_numeric($resumen['porcentaje_del_metodo']) && (float) $resumen['porcentaje_del_metodo'] != 0) {
            $porcentaje = (float) $resumen['porcentaje_del_metodo'];
            $partes[] = 'los precios ya llevan el ' . ($porcentaje > 0 ? 'descuento' : 'recargo') . ' del ' . PropuestaVentaIaHelper::porcentaje(abs($porcentaje)) . ' % de ' . $metodo;
        }

        if (!empty($resumen['lista_de_precios'])) {
            $partes[] = 'los precios salen de la lista ' . $resumen['lista_de_precios'];
        }

        $articulos = isset($resumen['articulos']) && is_array($resumen['articulos']) ? $resumen['articulos'] : [];

        foreach ($articulos as $articulo) {
            if (!empty($articulo['descuento'])) {
                $partes[] = 'cada artículo lleva su descuento';
                break;
            }
        }

        if (isset($resumen['descuento']['porcentaje']) && is_numeric($resumen['descuento']['porcentaje'])) {
            $partes[] = 'el descuento de la venta (' . PropuestaVentaIaHelper::porcentaje((float) $resumen['descuento']['porcentaje']) . ' %) va sobre ese subtotal';
        }

        return implode('; ', $partes);
    }

    /**
     * Los montos en pesos que cita una oración («$ 31.470,43», «$30516.79», «$1.202.047»), como números.
     *
     * Formato argentino: el punto separa miles y la coma los decimales. Sin coma, un solo punto seguido de
     * uno o dos dígitos al final se lee como decimal («$30516.79»).
     *
     * @param  string  $oracion
     * @return array<int, float>
     */
    public static function montos_citados($oracion)
    {
        if (!preg_match_all('/\$\s?(\d[\d\.]*(?:,\d{1,2})?)/u', (string) $oracion, $coincidencias)) {
            return [];
        }

        $montos = [];

        foreach ($coincidencias[1] as $crudo) {

            $numero = self::a_numero($crudo);

            if (!is_null($numero)) {
                $montos[] = $numero;
            }
        }

        return $montos;
    }

    /**
     * Un monto escrito («31.470,43», «30516.79», «1.202.047») como número, o null.
     *
     * @param  string  $crudo  Sin el signo $.
     * @return float|null
     */
    protected static function a_numero($crudo)
    {
        $crudo = rtrim((string) $crudo, '.');

        if (strpos($crudo, ',') !== false) {
            $numero = str_replace(',', '.', str_replace('.', '', $crudo));
        } elseif (substr_count($crudo, '.') === 1 && preg_match('/\.\d{1,2}$/', $crudo)) {
            $numero = $crudo;
        } else {
            $numero = str_replace('.', '', $crudo);
        }

        return is_numeric($numero) ? (float) $numero : null;
    }

    /**
     * Los montos que la oración presenta COMO TOTAL DE LA VENTA: el que sigue a «total» (no a «subtotal»)
     * y, si la oración nombra la venta o el total, el que sigue a «queda en» / «quedaría en».
     *
     * Revisión independiente (16/11/2026): la primera versión miraba TODOS los montos de una oración que
     * dijera «total» o «queda en», y disparaba con el precio por unidad («…con el efectivo queda en
     * $6.103,36»), con el saldo de un cliente o con el vuelto. Ahora solo cuenta el monto que sigue a la
     * palabra, y las oraciones de otra cosa (saldo, vuelto, lista, cada, unitario, por unidad, debe) no
     * cuentan.
     *
     * @param  string  $oracion
     * @return array<int, float>
     */
    protected static function montos_del_total($oracion)
    {
        $o = self::normalizar($oracion);

        if (preg_match('/\b(saldo|vuelto|lista|cada|unitario|por unidad|c\/u|debe|deben|debiendo|adeuda|deuda|por kg|el kilo)\b/u', $o)) {
            return [];
        }

        $monto = '\$\s?(\d[\d\.]*(?:,\d{1,2})?)';
        $patrones = ['/(?<![a-záéíóúñ])total(?:es)?\b[^$\d]{0,40}?' . $monto . '/iu'];

        if (preg_match('/\b(venta|total)\b/u', $o)) {
            $patrones[] = '/\b(?:queda|quedan|quedar[ií]a|quedar[ií]an)\s+en\s+' . $monto . '/iu';
        }

        $montos = [];

        foreach ($patrones as $patron) {

            if (!preg_match_all($patron, (string) $oracion, $coincidencias)) {
                continue;
            }

            foreach ($coincidencias[1] as $crudo) {

                $numero = self::a_numero($crudo);

                if (!is_null($numero)) {
                    $montos[] = $numero;
                }
            }
        }

        return $montos;
    }

    /**
     * true si la oración cita como TOTAL de la venta un monto que ninguna tarjeta del turno tiene.
     *
     * Mira solo los montos que la oración presenta como total (montos_del_total()). Cada uno vale si es el
     * total de alguna tarjeta, la SUMA de los totales de las tarjetas del turno («el total de las dos ventas
     * da $X») u otro número que la tarjeta muestra (subtotal, descuento, precio o subtotal de un renglón).
     * Dispara si alguno no es nada de eso: «total $30.516» con la tarjeta en $31.470 sí; «subtotal $33.907,
     * total $31.470» no.
     *
     * @param  string  $oracion
     * @param  array  $tarjetas  De tarjetas_de_venta().
     * @return bool
     */
    protected static function oracion_cita_otro_total($oracion, array $tarjetas)
    {
        if (!count($tarjetas)) {
            return false;
        }

        $montos = self::montos_del_total($oracion);

        if (!count($montos)) {
            return false;
        }

        $validos = [];
        $suma = 0.0;

        foreach ($tarjetas as $tarjeta) {
            $validos[] = (float) $tarjeta['total'];
            $suma += (float) $tarjeta['total'];

            foreach ($tarjeta['conocidos'] as $otro) {
                $validos[] = (float) $otro;
            }
        }

        if (count($tarjetas) > 1) {
            $validos[] = $suma;
        }

        foreach ($montos as $monto) {

            $es_valido = false;

            foreach ($validos as $valido) {
                if (abs($monto - $valido) <= self::TOLERANCIA_TOTAL) {
                    $es_valido = true;
                }
            }

            if (!$es_valido) {
                return true;
            }
        }

        return false;
    }

    /**
     * true si el texto cita como total de la venta un monto distinto del de la tarjeta del turno.
     *
     * 🔴 EL CASO REAL (31/10 #8, Ferre Total, conv. 33): «5 kg de cloro con 10 % de descuento, en
     * efectivo». El asistente dijo $30.516,79 (5 × $6.781,51 − 10 %) y la venta se armó por $31.470,43, sin
     * explicar la diferencia; Pablo confirmó el segundo monto sin saber cuál era el bueno. El total que se
     * le dice al dueño es el de la tarjeta, que es la cuenta de Vender.
     *
     * @param  string  $texto
     * @param  \App\Models\AiMessage  $assistant
     * @return bool
     */
    public static function afirma_otro_total($texto, AiMessage $assistant)
    {
        $tarjetas = self::tarjetas_de_venta($assistant);

        if (!count($tarjetas)) {
            return false;
        }

        return VeracidadDelTurnoIaHelper::alguna_oracion($texto, function ($oracion) use ($tarjetas) {
            return self::oracion_cita_otro_total($oracion, $tarjetas);
        });
    }

    /**
     * El aviso que reemplaza las oraciones con otro total: el de la tarjeta, con el porqué. Con varias
     * ventas en el turno no se elige una: se dice que cada total es el de su tarjeta.
     *
     * @param  array  $tarjetas  De tarjetas_de_venta().
     * @return string
     */
    protected static function aviso_de_total(array $tarjetas)
    {
        if (count($tarjetas) !== 1) {
            return 'Ojo: el total de cada venta es el que figura en su tarjeta.';
        }

        $aviso = self::AVISO_TOTAL . ': ' . FormatoIaHelper::monto($tarjetas[0]['total']);

        if ($tarjetas[0]['porque'] !== '') {
            $aviso .= ' (' . $tarjetas[0]['porque'] . ')';
        }

        return $aviso . '.';
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
            . 'El total también es el de la tarjeta (el `total` que devolvió proponer_venta): no lo recalcules; si no es tu cuenta, decí por qué '
            . '(descuento o recargo del método de pago, la lista, el descuento de cada artículo o de la venta). '
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

        // 31/10 #8: la oración con otro total se cambia por el de la tarjeta, con el porqué.
        $tarjetas = self::tarjetas_de_venta($assistant);

        if (count($tarjetas) && self::afirma_otro_total($texto, $assistant)) {
            $texto = VeracidadDelTurnoIaHelper::sin_las_oraciones($texto, function ($oracion) use ($tarjetas) {
                return self::oracion_cita_otro_total($oracion, $tarjetas);
            }, self::aviso_de_total($tarjetas));
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
