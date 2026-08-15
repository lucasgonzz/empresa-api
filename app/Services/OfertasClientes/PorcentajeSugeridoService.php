<?php

namespace App\Services\OfertasClientes;

use App\Services\StockSuggestion\CoberturaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Qué tan parado está un artículo (vendibilidad ∈ [0,1], 1 = totalmente parado) y qué porcentaje
 * de descuento le corresponde dentro del rango [piso, techo] que fijó TechoDeDescuentoService (§A.5.5).
 *
 * 🔴 ESTE NÚMERO EXISTE SIEMPRE, CON O SIN IA. Es el porcentaje determinista: el punto de partida
 * que la IA puede ajustar adentro del rango (§A.7) y el fallback cuando no hay credenciales de
 * Anthropic o la API se cae. Sin él, una falla de un tercero dejaría la corrida entera sin
 * porcentajes. No es "el valor por defecto hasta que conteste la IA": es el valor, y la IA lo mueve
 * o no lo mueve.
 *
 * Las tres señales, y de dónde sale cada una:
 *   f1  ventas estimadas       1 - min(1, velocidad / VELOCIDAD_PARA_FACTOR_CERO)
 *   f2  hace cuánto no se vende    min(1, dias_sin_venta / DIAS_SIN_VENTA_TOPE)
 *   f3  hace cuánto se compró      min(1, dias_desde_compra / DIAS_DESDE_COMPRA_TOPE)
 *   vendibilidad = (f1 + f2 + f3) / 3
 *
 * 🔴 f1 sale de CoberturaService::velocidades_globales(), que YA EXISTE
 * (app/Services/StockSuggestion/CoberturaService.php:171) y NO se reimplementa: mezcla la ventana
 * reciente con la interanual y esa mezcla no es aditiva, así que cualquier copia daría otro número.
 * Una sola llamada por chunk, nunca una por artículo.
 *
 * No escribe nada en la base. PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class PorcentajeSugeridoService
{
    /** Velocidad diaria (unidades/día) a partir de la cual el artículo se considera que sale bien. */
    const VELOCIDAD_PARA_FACTOR_CERO = 1.0;

    /** Días sin venderse a partir de los cuales el artículo está del todo parado. */
    const DIAS_SIN_VENTA_TOPE = 180;

    /** Días desde la última compra al proveedor a partir de los cuales la mercadería es vieja. */
    const DIAS_DESDE_COMPRA_TOPE = 365;

    /**
     * 🔴 EL BUEN PAGADOR, COMO MODIFICADOR REAL — Y POR QUÉ ACÁ Y NO EN EL TECHO.
     *
     * Lucas pidió "al que paga bien se le habilita más descuento". Hasta el 15/8/2026 eso no pasaba:
     * es_buen_pagador se calculaba, se guardaba en la línea y se le mostraba a la IA, pero no entraba
     * ni en el score, ni en el techo, ni en el porcentaje. Dos clientes idénticos —uno que paga al
     * día y otro sin datos de cuenta corriente— recibían exactamente el mismo descuento, o sea que la
     * mitad crediticia del pedido existía solo como decoración.
     *
     * La fracción de abajo es cuánto de lo que le SOBRA al porcentaje determinista para llegar al
     * techo se le regala al que paga al día: con piso 5, techo 25 y un determinista de 13, el buen
     * pagador se lleva 13 + (25 - 13) * 0,25 = 16.
     *
     * 🔴 EL TECHO NO SE TOCA, Y ESE ES EL PUNTO DE HACERLO ACÁ. El techo es el margen del artículo
     * (TechoDeDescuentoService) y es lo único que separa una oferta de una venta bajo costo. El bonus
     * se mueve ADENTRO del rango [piso, techo] y por construcción no puede salirse: parte de un
     * número que ya es <= techo y avanza una fracción < 1 de la distancia que le falta, así que el
     * invariante "el descuento nunca supera el techo" se mantiene para cualquier valor de esta
     * constante entre 0 y 1. Si algún día alguien quiere premiar más al buen pagador, lo que sube es
     * ESTE número, nunca el techo. Hay un test que lo prueba con el techo pegado al piso.
     *
     * 🔴 Y solo premia el true. null es "sin datos de cuenta corriente", que NO es "paga mal" —el mal
     * pagador ni siquiera llega hasta acá, se excluye entero en CriteriosDeOfertaService— pero
     * tampoco es un buen antecedente: no saber no se premia.
     */
    const BONUS_BUEN_PAGADOR = 0.25;

    /** @var int Comercio dueño, al que se acota toda consulta */
    protected $user_id;

    /**
     * @param int $user_id Dueño de la corrida (no config('app.USER_ID')).
     */
    public function __construct($user_id)
    {
        $this->user_id = (int) $user_id;
    }

    /**
     * Vendibilidad de cada artículo del lote, en tres consultas agregadas para TODO el chunk (nunca
     * una por artículo: article_purchases tiene 10⁵-10⁶ filas).
     *
     * @param  array $article_ids
     * @return array Mapa article_id => float en [0,1]
     */
    public function vendibilidades(array $article_ids): array
    {
        $vendibilidades = [];

        if (empty($article_ids)) {
            return $vendibilidades;
        }

        $article_ids = array_values(array_unique(array_map('intval', $article_ids)));

        $cobertura   = new CoberturaService($this->user_id);
        $velocidades = $cobertura->velocidades_globales($article_ids);
        $sin_venta   = $this->dias_sin_venta($article_ids);
        $desde_compra = $this->dias_desde_ultima_compra($article_ids);

        foreach ($article_ids as $article_id) {
            $clave     = $cobertura->clave_global($article_id);
            $velocidad = isset($velocidades[$clave]) ? (float) $velocidades[$clave] : 0.0;

            $f1 = 1 - min(1, $velocidad / self::VELOCIDAD_PARA_FACTOR_CERO);

            /*
             * 🔴 Si una señal no tiene dato —un artículo que nunca se vendió, o que nunca se
             * compró— su factor vale 1: se lo trata como PARADO. Es la lectura correcta del dato
             * faltante en este dominio (nunca se movió = no se mueve) y además es la conservadora
             * para el comerciante, porque empuja el descuento hacia arriba solo sobre mercadería
             * que efectivamente no rota. El techo lo sigue acotando igual.
             */
            $f2 = isset($sin_venta[$article_id])
                ? min(1, $sin_venta[$article_id] / self::DIAS_SIN_VENTA_TOPE)
                : 1;

            $f3 = isset($desde_compra[$article_id])
                ? min(1, $desde_compra[$article_id] / self::DIAS_DESDE_COMPRA_TOPE)
                : 1;

            $vendibilidades[$article_id] = ($f1 + $f2 + $f3) / 3;
        }

        return $vendibilidades;
    }

    /**
     * El porcentaje determinista: dónde cae el artículo dentro de su propio rango. Vendibilidad 0
     * (sale solo) deja el descuento en el piso; vendibilidad 1 (parado) lo lleva al techo. Y si el
     * cliente paga al día, se lo acerca un poco más al techo, sin tocarlo nunca (ver
     * BONUS_BUEN_PAGADOR): esa es la única forma en que "al que paga bien se le habilita más
     * descuento" puede convivir con "el descuento nunca supera el margen".
     *
     * floor y no round, por lo mismo que el techo (ver TechoDeDescuentoService, paso 10): hacia
     * abajo nunca se rompe el margen. Y el resultado se recorta al rango igual, porque este
     * porcentaje viaja después por el recorte incondicional de la decisión de la IA (§A.7) y las dos
     * puntas tienen que coincidir.
     *
     * @param  int       $piso
     * @param  int       $techo
     * @param  float     $vendibilidad
     * @param  bool|null $es_buen_pagador true = paga al día, false = mal pagador (no llega hasta acá,
     *                                    se excluye antes), null = sin datos de cuenta corriente.
     * @return int
     */
    public function porcentaje($piso, $techo, $vendibilidad, $es_buen_pagador = null): int
    {
        $piso  = (int) $piso;
        $techo = (int) $techo;

        if ($techo <= $piso) {
            return $techo;
        }

        $vendibilidad = (float) $vendibilidad;
        $vendibilidad = $vendibilidad > 1 ? 1.0 : $vendibilidad;
        $vendibilidad = $vendibilidad < 0 ? 0.0 : $vendibilidad;

        $porcentaje = (int) floor($piso + $vendibilidad * ($techo - $piso));

        /*
         * El premio del buen pagador (ver BONUS_BUEN_PAGADOR): se acerca al techo una fracción de lo
         * que le falta, y nunca lo alcanza salvo que ya estuviera ahí. === true y no un truthy
         * suelto: null es "sin datos" y tiene que comportarse igual que no saber nada.
         */
        if ($es_buen_pagador === true) {
            $porcentaje = (int) floor($porcentaje + ($techo - $porcentaje) * self::BONUS_BUEN_PAGADOR);
        }

        if ($porcentaje > $techo) {
            return $techo;
        }

        return $porcentaje < $piso ? $piso : $porcentaje;
    }

    /**
     * Días desde la última venta real de cada artículo. Los que nunca se vendieron NO aparecen en
     * el mapa (ver el factor por defecto en vendibilidades()).
     *
     * @param  array $article_ids
     * @return array Mapa article_id => int
     */
    protected function dias_sin_venta(array $article_ids)
    {
        $q = DB::table('article_purchases')
            ->selectRaw('article_purchases.article_id as article_id, MAX(article_purchases.created_at) as ultima');

        // A.5.0, el mismo filtro de ventas reales de los criterios: en UN solo lugar.
        CriteriosDeOfertaService::filtro_de_ventas_reales($q, $this->user_id);

        $filas = $q->whereIn('article_purchases.article_id', $article_ids)
            ->groupBy('article_purchases.article_id')
            ->get();

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->article_id] = $this->dias_desde($fila->ultima);
        }

        return $mapa;
    }

    /**
     * Días desde la última compra al proveedor de cada artículo, vía article_provider_order. Los que
     * nunca se compraron NO aparecen en el mapa.
     *
     * @param  array $article_ids
     * @return array Mapa article_id => int
     */
    protected function dias_desde_ultima_compra(array $article_ids)
    {
        $filas = DB::table('article_provider_order')
            ->join('provider_orders', 'provider_orders.id', '=', 'article_provider_order.provider_order_id')
            // provider_orders sí tiene user_id propio, a diferencia de article_purchases.
            ->where('provider_orders.user_id', $this->user_id)
            ->whereIn('article_provider_order.article_id', $article_ids)
            ->groupBy('article_provider_order.article_id')
            ->selectRaw('article_provider_order.article_id as article_id, MAX(provider_orders.created_at) as ultima')
            ->get();

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->article_id] = $this->dias_desde($fila->ultima);
        }

        return $mapa;
    }

    /**
     * @param  mixed $fecha
     * @return int Días enteros desde esa fecha hasta hoy (nunca negativo)
     */
    protected function dias_desde($fecha)
    {
        return is_null($fecha)
            ? 0
            : (int) Carbon::parse($fecha)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }
}
