<?php

namespace App\Services\Mostrador;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo que comparten los cuatro recolectores de hechos del mostrador (misión
 * modulo-ia-mostrador): la firma, los topes, el formato de montos y fechas, la
 * URL pública de la primera imagen de un artículo y la respuesta "no aplica".
 *
 * Reglas duras de todos los recolectores (§1.2 del plan):
 * - TODO scopeado por el user_id del dueño. Nunca User::all(), nunca tablas enteras.
 * - SIN efectos secundarios: no crean sugerencias, conversaciones ni filas de ninguna
 *   tabla. Son lectura pura; el que persiste es el controlador admin-sync.
 * - Montos como float con 2 decimales, fechas 'Y-m-d', nombres tal cual.
 * - Lo que no existe en la base se devuelve null o lista vacía, nunca inventado.
 */
abstract class RecolectorBase
{
    /** Tope general de las listas. */
    const TOPE_LISTA = 10;

    /** Moneda en pesos (cajas y cuentas viejas pueden tenerla null: se tratan como pesos). */
    const MONEDA_PESOS = 1;

    /**
     * Calcula los hechos del tipo para el dueño y la fecha pedidos.
     *
     * @param User $owner Dueño de la cuenta (owner_id null)
     * @param Carbon $fecha Día del que habla el informe
     * @return array
     */
    abstract public function recolectar(User $owner, Carbon $fecha): array;

    /**
     * Respuesta de un tipo que no aplica a este comercio (tienda sin tienda online,
     * stock con una sola sucursal). El API igual guarda la fila con estado 'hechos';
     * la skill decide no redactar y el escritorio no la muestra.
     *
     * @param Carbon $fecha
     * @param string $motivo
     * @return array
     */
    protected function no_aplica(Carbon $fecha, string $motivo): array
    {
        return [
            'aplica' => false,
            'fecha'  => $fecha->format('Y-m-d'),
            'motivo' => $motivo,
        ];
    }

    /**
     * Monto como float con dos decimales (null se queda null).
     *
     * @param mixed $valor
     * @return float|null
     */
    protected function monto($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        return round((float) $valor, 2);
    }

    /**
     * Fecha en 'Y-m-d' a partir de un timestamp de la base (null se queda null).
     *
     * @param mixed $valor
     * @return string|null
     */
    protected function fecha_ymd($valor)
    {
        if (empty($valor)) {
            return null;
        }

        return Carbon::parse($valor)->format('Y-m-d');
    }

    /**
     * Condición de "venta real" para consultas que parten de article_purchases: la de
     * Sale::scopeSoloVentasReales con prefijo de tabla, más el descarte de borradas y
     * la tenencia por articles.user_id. Es el mismo filtro de CoberturaService y de
     * CriteriosDeOfertaService::filtro_de_ventas_reales.
     *
     * @param \Illuminate\Database\Query\Builder $q Consulta cuyo FROM es article_purchases
     * @param int $owner_id
     * @return \Illuminate\Database\Query\Builder
     */
    protected function ventas_reales_desde_article_purchases($q, int $owner_id)
    {
        return $q->join('articles', 'article_purchases.article_id', '=', 'articles.id')
            ->join('sales', 'article_purchases.sale_id', '=', 'sales.id')
            ->where('articles.user_id', $owner_id)
            ->whereNull('sales.deleted_at')
            ->where(function ($q2) {
                $q2->whereNull('sales.is_consolidacion_facturacion')
                    ->orWhere('sales.is_consolidacion_facturacion', 0);
            });
    }

    /**
     * Condición "quedó en cero" sobre una columna de stock: stock cargado Y en cero o
     * negativo. `stock = NULL` NO es cero: es "no controla stock" (InventoryPerformanceHelper
     * lo cuenta como "sin stockear" y la tienda lo vende como disponible), así que un
     * artículo sin control de stock nunca puede "quedar sin stock".
     *
     * @param \Illuminate\Database\Query\Builder $q
     * @param string $columna Columna calificada (articles.stock, a.stock, address_article.amount)
     * @return \Illuminate\Database\Query\Builder
     */
    protected function en_cero($q, string $columna)
    {
        return $q->whereNotNull($columna)->where($columna, '<=', 0);
    }

    /**
     * Consulta base de las cuentas corrientes EN PESOS de un tipo de modelo (clientes o
     * proveedores) del dueño. La fuente de verdad es credit_accounts.saldo (saldo
     * positivo = deuda): nunca los espejos clients.saldo / providers.saldo (columna muerta
     * que CurrentAcountHelper ya no escribe) ni saldo_pesos (nullable).
     *
     * 🔴 UN SOLO CRITERIO DE MONEDA para todo el mostrador: credit_accounts.moneda_id es
     * NOT NULL en el esquema, pero por si una base vieja trajera un null, se trata como
     * pesos (igual que cajas y cuentas viejas en el resto del sistema). Toda deuda que
     * viaja en un informe —por cliente, por proveedor o total— sale de acá, así que no
     * puede haber dos números distintos para la misma deuda según el bloque.
     *
     * @param User $owner
     * @param string $model_name 'client' | 'provider'
     * @return \Illuminate\Database\Query\Builder
     */
    protected function consulta_deudas_en_pesos(User $owner, string $model_name)
    {
        return DB::table('credit_accounts')
            ->where('user_id', $owner->id)
            ->where('model_name', $model_name)
            ->where(function ($q) {
                $q->whereNull('moneda_id')->orWhere('moneda_id', self::MONEDA_PESOS);
            });
    }

    /**
     * Saldo en pesos de un lote de clientes o proveedores, por id de modelo (ver
     * consulta_deudas_en_pesos).
     *
     * @param User $owner
     * @param string $model_name 'client' | 'provider'
     * @param array $model_ids
     * @return array Mapa model_id => saldo (float)
     */
    protected function deudas_en_pesos(User $owner, string $model_name, array $model_ids): array
    {
        $mapa = [];

        $model_ids = array_values(array_unique(array_filter(array_map('intval', $model_ids))));

        if (empty($model_ids)) {
            return $mapa;
        }

        $filas = $this->consulta_deudas_en_pesos($owner, $model_name)
            ->whereIn('model_id', $model_ids)
            ->get(['model_id', 'saldo']);

        foreach ($filas as $fila) {
            $model_id = (int) $fila->model_id;

            if (!isset($mapa[$model_id])) {
                $mapa[$model_id] = 0.0;
            }

            $mapa[$model_id] += (float) ($fila->saldo ?: 0);
        }

        return $mapa;
    }

    /**
     * Deuda total en pesos de los clientes o con los proveedores del dueño (mismo
     * criterio que deudas_en_pesos, en una sola suma).
     *
     * @param User $owner
     * @param string $model_name 'client' | 'provider'
     * @return float
     */
    protected function deuda_total_en_pesos(User $owner, string $model_name): float
    {
        $total = $this->consulta_deudas_en_pesos($owner, $model_name)->sum('saldo');

        return (float) $this->monto($total);
    }

    /**
     * URL pública de la primera imagen de cada artículo pedido (null si no tiene).
     * Una sola consulta para todo el lote; la URL la resuelve ArticleHelper::getFirstImage,
     * que es lo que ya usa el resto del sistema (incluido el prefijo de producción).
     *
     * @param array $article_ids
     * @return array Mapa article_id => string|null
     */
    protected function imagenes_de(array $article_ids): array
    {
        $mapa = [];

        $article_ids = array_values(array_unique(array_filter(array_map('intval', $article_ids))));

        foreach ($article_ids as $id) {
            $mapa[$id] = null;
        }

        if (empty($article_ids)) {
            return $mapa;
        }

        $articulos = Article::withTrashed()
            ->whereIn('id', $article_ids)
            ->with('images')
            ->get(['id']);

        foreach ($articulos as $articulo) {
            $url = ArticleHelper::getFirstImage($articulo);

            $mapa[(int) $articulo->id] = is_string($url) && $url !== '' ? $url : null;
        }

        return $mapa;
    }

    /**
     * Nombres de un lote de artículos (con borrados, para que un artículo vendido ayer
     * y borrado hoy siga teniendo nombre en el informe).
     *
     * @param array $article_ids
     * @return array Mapa article_id => nombre
     */
    protected function nombres_de_articulos(array $article_ids): array
    {
        $article_ids = array_values(array_unique(array_filter(array_map('intval', $article_ids))));

        if (empty($article_ids)) {
            return [];
        }

        $mapa = [];

        $filas = DB::table('articles')
            ->whereIn('id', $article_ids)
            ->get(['id', 'name']);

        foreach ($filas as $fila) {
            $mapa[(int) $fila->id] = (string) $fila->name;
        }

        return $mapa;
    }

    /**
     * Nombre de la persona que figura en una venta o un pago: el empleado si lo hay,
     * si no el dueño.
     *
     * @param User $owner
     * @param array $nombres_por_id Mapa users.id => name ya resuelto
     * @param mixed $employee_id
     * @return string
     */
    protected function nombre_de_empleado(User $owner, array $nombres_por_id, $employee_id): string
    {
        $employee_id = (int) $employee_id;

        if ($employee_id > 0 && isset($nombres_por_id[$employee_id])) {
            return $nombres_por_id[$employee_id];
        }

        return (string) ($owner->name ?: 'Dueño');
    }

    /**
     * Nombres de las personas de la cuenta (dueño + empleados), para por_vendedor.
     *
     * @param User $owner
     * @return array Mapa users.id => name
     */
    protected function nombres_de_la_cuenta(User $owner): array
    {
        $mapa = [(int) $owner->id => (string) $owner->name];

        $empleados = DB::table('users')
            ->where('owner_id', $owner->id)
            ->get(['id', 'name']);

        foreach ($empleados as $empleado) {
            $mapa[(int) $empleado->id] = (string) $empleado->name;
        }

        return $mapa;
    }

    /**
     * Nombre de cada sucursal de la cuenta (addresses.street es el "nombre" que
     * muestra el sistema en todos lados).
     *
     * @param User $owner
     * @return array Mapa addresses.id => nombre
     */
    protected function nombres_de_sucursales(User $owner): array
    {
        $mapa = [];

        $filas = DB::table('addresses')
            ->where('user_id', $owner->id)
            ->orderBy('id')
            ->get(['id', 'street']);

        foreach ($filas as $fila) {
            $mapa[(int) $fila->id] = (string) $fila->street;
        }

        return $mapa;
    }

    /**
     * true si el comercio tiene tienda online: la URL en users.online o algún pedido.
     *
     * @param User $owner
     * @return bool
     */
    protected function tiene_tienda(User $owner): bool
    {
        if (trim((string) $owner->online) !== '') {
            return true;
        }

        return DB::table('orders')->where('user_id', $owner->id)->exists();
    }
}
