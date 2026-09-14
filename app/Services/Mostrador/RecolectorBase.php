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
