<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Models\SellerCommission;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\DB;

/**
 * Logica del panel "Comisiones de vendedor" (mision comisiones-vendedor-tablas, 24/9/2026).
 *
 * El modal del vendedor pasa de dos listas sin paginar (endpoint `SellerCommissionController@index`,
 * que se deja intacto por compatibilidad) a tres pedidos independientes:
 *
 *  - `resumen()`: las tres tarjetas (saldo a pagar, pendiente de liquidar, pagado).
 *  - `liquidadas()`: el ledger (filas `active`: comisiones liquidadas + pagos + saldos iniciales),
 *    paginado de a 15, las mas nuevas primero, cada fila con su `saldo_calculado`.
 *  - `pendientes()`: comisiones `inactive` (esperando que se salde la venta), paginadas de a 15.
 *
 * Definiciones cerradas del plan (no reinterpretar):
 *
 *  - `fecha_mov` de una fila del ledger = COALESCE(liquidada_at, created_at): el momento en que la
 *    fila entro al ledger. Las filas viejas sin `liquidada_at` caen en `created_at`.
 *  - El saldo por fila se CALCULA aca, en orden (fecha_mov ASC, id ASC) sobre el ledger completo del
 *    vendedor en esa moneda; NO se lee la columna `saldo`, que `ComisionesHelper::recalcular_saldos()`
 *    mantiene en orden de id (y que no se toca). El saldo final es el mismo en los dos ordenes.
 *  - Moneda: 1 (pesos) incluye `moneda_id` NULL (filas historicas); cualquier otra, igualdad exacta.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 */
class PanelComisionesHelper
{
    /**
     * Filas por pagina de cada tabla del panel (pedido de Lucas: 15).
     */
    const POR_PAGINA = 15;

    /**
     * Expresion SQL de la fecha de un movimiento del ledger. Se usa igual en el SELECT, en el ORDER
     * BY, en el filtro de rango y en el agregado del saldo por fila, para que los cuatro hablen de la
     * misma fecha.
     */
    const FECHA_MOV = 'COALESCE(seller_commissions.liquidada_at, seller_commissions.created_at)';

    /**
     * Expresion SQL del efecto de una fila sobre el saldo (lo que se le debe al vendedor sube con
     * `debe` y baja con `haber`). Los NULL cuentan como cero.
     */
    const EFECTO = 'COALESCE(seller_commissions.debe, 0) - COALESCE(seller_commissions.haber, 0)';

    /**
     * Tipos validos del filtro de la tabla de liquidadas.
     */
    const TIPOS = ['todos', 'comisiones', 'pagos'];

    /**
     * Normaliza una fecha que viene por query string. Solo se acepta el formato exacto `Y-m-d`
     * (una fecha real: `2026-02-30` no pasa); cualquier otra cosa —vacio, null, otro formato— se
     * ignora devolviendo null, que para el panel significa "sin ese extremo del rango".
     *
     * @param mixed $fecha
     * @return string|null La fecha en `Y-m-d`, o null si no vino o vino mal.
     */
    static function normalizar_fecha($fecha)
    {
        if (!is_string($fecha) || $fecha === '') {
            return null;
        }

        $parseada = DateTime::createFromFormat('!Y-m-d', $fecha);

        // createFromFormat "corrige" fechas imposibles (30/2 -> 2/3); se exige que al volver a
        // formatear quede exactamente lo que vino.
        if ($parseada === false || $parseada->format('Y-m-d') !== $fecha) {
            return null;
        }

        return $fecha;
    }

    /**
     * Normaliza la moneda: null o 0 = pesos (1), igual que `SellerCommissionController@index`.
     *
     * @param mixed $moneda_id
     * @return int
     */
    static function normalizar_moneda($moneda_id)
    {
        if (is_null($moneda_id) || (int) $moneda_id == 0) {
            return 1;
        }

        return (int) $moneda_id;
    }

    /**
     * Normaliza el filtro de tipo de la tabla de liquidadas. Un valor desconocido cae en 'todos'.
     *
     * @param mixed $tipo
     * @return string 'todos' | 'comisiones' | 'pagos'
     */
    static function normalizar_tipo($tipo)
    {
        if (in_array($tipo, self::TIPOS, true)) {
            return $tipo;
        }

        return 'todos';
    }

    /**
     * Normaliza el numero de pagina: entero >= 1, cualquier otra cosa es la pagina 1.
     *
     * @param mixed $page
     * @return int
     */
    static function normalizar_pagina($page)
    {
        $page = (int) $page;

        return $page >= 1 ? $page : 1;
    }

    /**
     * Consulta base de todo el panel: filas del vendedor, del usuario (comercio) y de la moneda
     * pedida. Pesos (1) incluye `moneda_id` NULL, mismo criterio que `recalcular_saldos`.
     *
     * @param int $user_id
     * @param int $seller_id
     * @param int $moneda_id Ya normalizada.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    static function base($user_id, $seller_id, $moneda_id)
    {
        return SellerCommission::where('seller_commissions.user_id', $user_id)
                    ->where('seller_commissions.seller_id', $seller_id)
                    ->where(function ($q) use ($moneda_id) {
                        if ($moneda_id == 1) {
                            $q->where('seller_commissions.moneda_id', 1)
                              ->orWhereNull('seller_commissions.moneda_id');
                        } else {
                            $q->where('seller_commissions.moneda_id', $moneda_id);
                        }
                    });
    }

    /**
     * Aplica un rango de fechas (dias completos, ambos extremos inclusive) sobre una expresion de
     * fecha. Se arma como intervalo semiabierto [desde 00:00:00, hasta+1 00:00:00) en vez de
     * `DATE(expr)` para comparar datetimes directo.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $expresion Expresion SQL de la fecha (columna o COALESCE).
     * @param string|null $desde `Y-m-d` normalizada, o null.
     * @param string|null $hasta `Y-m-d` normalizada, o null.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    static function aplicar_rango($query, $expresion, $desde, $hasta)
    {
        if (!is_null($desde)) {
            $query->whereRaw($expresion.' >= ?', [$desde.' 00:00:00']);
        }

        if (!is_null($hasta)) {
            $query->whereRaw($expresion.' < ?', [self::dia_siguiente($hasta)]);
        }

        return $query;
    }

    /**
     * Devuelve el comienzo del dia siguiente a `$fecha` (`Y-m-d 00:00:00`), el tope exclusivo del
     * rango.
     *
     * @param string $fecha `Y-m-d`
     * @return string
     */
    static function dia_siguiente($fecha)
    {
        return Carbon::createFromFormat('Y-m-d', $fecha)->addDay()->format('Y-m-d').' 00:00:00';
    }

    /**
     * Las tres tarjetas del panel.
     *
     *  - saldo: Σdebe − Σhaber de TODAS las `active`. Con `hasta`, solo las que tienen
     *    fecha_mov <= hasta (saldo al cierre del periodo). `desde` no lo afecta: un saldo es
     *    acumulado.
     *  - total_pendiente: Σdebe de las `inactive`, con rango sobre `created_at` (fecha de la venta).
     *  - total_pagado: Σhaber de las `active`, con rango sobre fecha_mov.
     *
     * @param int $user_id
     * @param int $seller_id
     * @param mixed $moneda_id
     * @param mixed $desde
     * @param mixed $hasta
     * @return array ['totales' => [saldo, total_pendiente, total_pagado], 'rango' => [desde, hasta]]
     */
    static function resumen($user_id, $seller_id, $moneda_id, $desde, $hasta)
    {
        $moneda_id = self::normalizar_moneda($moneda_id);
        $desde     = self::normalizar_fecha($desde);
        $hasta     = self::normalizar_fecha($hasta);

        // Saldo: solo el tope `hasta` (desde se pasa como null a proposito).
        $saldo_query = self::base($user_id, $seller_id, $moneda_id)
                            ->where('seller_commissions.status', 'active');
        self::aplicar_rango($saldo_query, self::FECHA_MOV, null, $hasta);
        $saldo = $saldo_query->sum(DB::raw(self::EFECTO));

        // Pendiente de liquidar: comisiones que todavia no entraron al ledger.
        $pendiente_query = self::base($user_id, $seller_id, $moneda_id)
                            ->where('seller_commissions.status', 'inactive');
        self::aplicar_rango($pendiente_query, 'seller_commissions.created_at', $desde, $hasta);
        $total_pendiente = $pendiente_query->sum('seller_commissions.debe');

        // Pagado: pagos registrados en el ledger dentro del rango (o todos si no hay rango).
        $pagado_query = self::base($user_id, $seller_id, $moneda_id)
                            ->where('seller_commissions.status', 'active');
        self::aplicar_rango($pagado_query, self::FECHA_MOV, $desde, $hasta);
        $total_pagado = $pagado_query->sum('seller_commissions.haber');

        return [
            'totales' => [
                'saldo'           => Numbers::redondear((float) $saldo),
                'total_pendiente' => Numbers::redondear((float) $total_pendiente),
                'total_pagado'    => Numbers::redondear((float) $total_pagado),
            ],
            'rango' => [
                'desde' => $desde,
                'hasta' => $hasta,
            ],
        ];
    }

    /**
     * Tabla de liquidadas (el ledger): filas `active`, paginadas de a 15, orden
     * (fecha_mov DESC, id DESC). Cada fila lleva `fecha_mov` y `saldo_calculado`.
     *
     * El rango filtra por fecha_mov. El tipo filtra: 'comisiones' = `debe` no nulo (incluye el
     * saldo inicial cargado como debe), 'pagos' = `haber` no nulo.
     *
     * @param int $user_id
     * @param int $seller_id
     * @param mixed $moneda_id
     * @param mixed $desde
     * @param mixed $hasta
     * @param mixed $tipo
     * @param mixed $page
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    static function liquidadas($user_id, $seller_id, $moneda_id, $desde, $hasta, $tipo, $page)
    {
        $moneda_id = self::normalizar_moneda($moneda_id);
        $desde     = self::normalizar_fecha($desde);
        $hasta     = self::normalizar_fecha($hasta);
        $tipo      = self::normalizar_tipo($tipo);
        $page      = self::normalizar_pagina($page);

        $query = self::base($user_id, $seller_id, $moneda_id)
                    ->select('seller_commissions.*')
                    ->selectRaw(self::FECHA_MOV.' as fecha_mov')
                    ->with('sale', 'moneda', 'payment_methods')
                    ->where('seller_commissions.status', 'active');

        self::aplicar_rango($query, self::FECHA_MOV, $desde, $hasta);

        if ($tipo == 'comisiones') {
            $query->whereNotNull('seller_commissions.debe');
        } else if ($tipo == 'pagos') {
            $query->whereNotNull('seller_commissions.haber');
        }

        $paginador = $query->orderByRaw(self::FECHA_MOV.' DESC')
                            ->orderBy('seller_commissions.id', 'DESC')
                            ->paginate(self::POR_PAGINA, ['*'], 'page', $page);

        self::completar_saldos($paginador->getCollection(), $user_id, $seller_id, $moneda_id, $tipo);

        return $paginador;
    }

    /**
     * Setea `saldo_calculado` en cada fila de una pagina de liquidadas (ordenada de la mas nueva a
     * la mas vieja).
     *
     * Con tipo 'todos' las filas de la pagina son CONSECUTIVAS en el ledger completo (el rango de
     * fechas corta un intervalo contiguo del orden fecha_mov/id), asi que alcanza con una sola
     * consulta agregada para la fila mas nueva y despues se baja restando el efecto de cada fila:
     * saldo(fila siguiente, mas vieja) = saldo(fila) − (debe − haber de fila).
     *
     * Con un filtro de tipo la pagina tiene huecos: las filas del otro tipo que quedaron en el
     * medio tambien mueven el saldo. Se resuelve igual con UNA consulta agregada (la de la fila mas
     * nueva) mas UNA consulta liviana que trae el efecto de TODAS las filas del ledger entre la mas
     * nueva y la mas vieja de la pagina; se baja restando por ese tramo completo y se anota el saldo
     * solo en las filas que estan en la pagina. Antes era una consulta agregada por fila: medido con
     * un vendedor de 24.000 comisiones, ~3 s por pagina (15 sumas de ~200 ms cada una).
     *
     * @param \Illuminate\Support\Collection $filas
     * @param int $user_id
     * @param int $seller_id
     * @param int $moneda_id
     * @param string $tipo
     * @return void
     */
    static function completar_saldos($filas, $user_id, $seller_id, $moneda_id, $tipo)
    {
        if (count($filas) == 0) {
            return;
        }

        $saldo = self::saldo_hasta($filas[0], $user_id, $seller_id, $moneda_id);

        if ($tipo == 'todos') {
            foreach ($filas as $fila) {
                $fila->saldo_calculado = Numbers::redondear($saldo);
                $saldo -= self::efecto($fila);
            }
            return;
        }

        // Filas de la pagina indexadas por id, para anotarles el saldo al pasar por el tramo.
        $en_pagina = [];
        foreach ($filas as $fila) {
            $en_pagina[(int) $fila->id] = $fila;
        }

        $tramo = self::tramo_del_ledger($filas[0], $filas[count($filas) - 1], $user_id, $seller_id, $moneda_id);

        foreach ($tramo as $movimiento) {
            $id = (int) $movimiento->id;
            if (isset($en_pagina[$id])) {
                $en_pagina[$id]->saldo_calculado = Numbers::redondear($saldo);
            }
            $debe  = !is_null($movimiento->debe) ? (float) $movimiento->debe : 0;
            $haber = !is_null($movimiento->haber) ? (float) $movimiento->haber : 0;
            $saldo -= ($debe - $haber);
        }
    }

    /**
     * Trae id, debe y haber de TODAS las filas `active` del ledger (sin filtro de tipo ni rango)
     * comprendidas entre `$mas_nueva` y `$mas_vieja` inclusive, en orden (fecha_mov DESC, id DESC).
     * Select de tres columnas, sin modelos Eloquent: es el camino liviano para bajar el saldo por
     * una pagina con huecos.
     *
     * @param \App\Models\SellerCommission $mas_nueva Primera fila de la pagina (con `fecha_mov`).
     * @param \App\Models\SellerCommission $mas_vieja Ultima fila de la pagina (con `fecha_mov`).
     * @param int $user_id
     * @param int $seller_id
     * @param int $moneda_id
     * @return \Illuminate\Support\Collection
     */
    static function tramo_del_ledger($mas_nueva, $mas_vieja, $user_id, $seller_id, $moneda_id)
    {
        $fecha_nueva = $mas_nueva->fecha_mov;
        $id_nueva    = $mas_nueva->id;
        $fecha_vieja = $mas_vieja->fecha_mov;
        $id_vieja    = $mas_vieja->id;

        return self::base($user_id, $seller_id, $moneda_id)
                    ->select('seller_commissions.id', 'seller_commissions.debe', 'seller_commissions.haber')
                    ->where('seller_commissions.status', 'active')
                    // Hasta la fila mas nueva inclusive: (fecha_mov, id) <= (F_nueva, ID_nueva).
                    ->where(function ($q) use ($fecha_nueva, $id_nueva) {
                        $q->whereRaw(self::FECHA_MOV.' < ?', [$fecha_nueva])
                          ->orWhere(function ($q2) use ($fecha_nueva, $id_nueva) {
                              $q2->whereRaw(self::FECHA_MOV.' = ?', [$fecha_nueva])
                                 ->where('seller_commissions.id', '<=', $id_nueva);
                          });
                    })
                    // Desde la fila mas vieja inclusive: (fecha_mov, id) >= (F_vieja, ID_vieja).
                    ->where(function ($q) use ($fecha_vieja, $id_vieja) {
                        $q->whereRaw(self::FECHA_MOV.' > ?', [$fecha_vieja])
                          ->orWhere(function ($q2) use ($fecha_vieja, $id_vieja) {
                              $q2->whereRaw(self::FECHA_MOV.' = ?', [$fecha_vieja])
                                 ->where('seller_commissions.id', '>=', $id_vieja);
                          });
                    })
                    ->orderByRaw(self::FECHA_MOV.' DESC')
                    ->orderBy('seller_commissions.id', 'DESC')
                    ->get();
    }

    /**
     * Saldo acumulado del ledger completo (todas las `active` del vendedor en la moneda, sin filtro
     * de tipo ni rango) hasta `$fila` inclusive, en orden (fecha_mov ASC, id ASC):
     * Σ(debe − haber) de las filas con fecha_mov < F, o fecha_mov = F e id <= ID.
     *
     * @param \App\Models\SellerCommission $fila Tiene que venir con el atributo `fecha_mov`.
     * @param int $user_id
     * @param int $seller_id
     * @param int $moneda_id
     * @return float
     */
    static function saldo_hasta($fila, $user_id, $seller_id, $moneda_id)
    {
        $fecha = $fila->fecha_mov;
        $id    = $fila->id;

        $saldo = self::base($user_id, $seller_id, $moneda_id)
                    ->where('seller_commissions.status', 'active')
                    ->where(function ($q) use ($fecha, $id) {
                        $q->whereRaw(self::FECHA_MOV.' < ?', [$fecha])
                          ->orWhere(function ($q2) use ($fecha, $id) {
                              $q2->whereRaw(self::FECHA_MOV.' = ?', [$fecha])
                                 ->where('seller_commissions.id', '<=', $id);
                          });
                    })
                    ->sum(DB::raw(self::EFECTO));

        return Numbers::redondear((float) $saldo);
    }

    /**
     * Efecto de una fila sobre el saldo: debe − haber (null = 0).
     *
     * @param \App\Models\SellerCommission $fila
     * @return float
     */
    static function efecto($fila)
    {
        $debe  = is_null($fila->debe) ? 0 : (float) $fila->debe;
        $haber = is_null($fila->haber) ? 0 : (float) $fila->haber;

        return $debe - $haber;
    }

    /**
     * Tabla de pendientes: filas `inactive` (comisiones que se liquidan solas cuando se salde la
     * venta), paginadas de a 15, orden (created_at DESC, id DESC), rango sobre `created_at`. No
     * llevan saldo: todavia no estan en el ledger.
     *
     * @param int $user_id
     * @param int $seller_id
     * @param mixed $moneda_id
     * @param mixed $desde
     * @param mixed $hasta
     * @param mixed $page
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    static function pendientes($user_id, $seller_id, $moneda_id, $desde, $hasta, $page)
    {
        $moneda_id = self::normalizar_moneda($moneda_id);
        $desde     = self::normalizar_fecha($desde);
        $hasta     = self::normalizar_fecha($hasta);
        $page      = self::normalizar_pagina($page);

        $query = self::base($user_id, $seller_id, $moneda_id)
                    ->with('sale', 'moneda', 'payment_methods')
                    ->where('seller_commissions.status', 'inactive');

        self::aplicar_rango($query, 'seller_commissions.created_at', $desde, $hasta);

        return $query->orderBy('seller_commissions.created_at', 'DESC')
                     ->orderBy('seller_commissions.id', 'DESC')
                     ->paginate(self::POR_PAGINA, ['*'], 'page', $page);
    }
}
