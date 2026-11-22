<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\UnmetDemand;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lógica de negocio de la demanda no satisfecha de Vender (misión demanda-sin-stock-vender).
 *
 * Cada vez que Vender frena un artículo por falta de stock, la SPA avisa a la API y acá se
 * guarda una fila en `unmet_demands`. Esa fila es la señal de "alguien quiso comprar esto y no
 * lo teníamos": la lee el motor de sugerencias de compra (resumen_por_articulo) y el asistente.
 *
 * Lo que resuelve este helper:
 *  - registrar(): valida (artículo del dueño, motivo), normaliza, atribuye el empleado y evita
 *    duplicados dentro de una ventana corta;
 *  - cancelar(): marca la fila como cancelada sin borrarla (el vendedor aclaró que fue un error);
 *  - resumen_por_articulo(): una sola consulta agregada para el motor;
 *  - esquema_listo(): guarda para la ventana de deploy (código antes que migración).
 */
class UnmetDemandHelper
{
    /** Ventana (minutos) en la que un mismo freno se considera un reintento y no una demanda nueva. */
    const VENTANA_DUPLICADO_MINUTOS = 5;

    /** Motivos válidos de un freno. */
    const MOTIVOS = ['sin_stock', 'stock_insuficiente'];

    /**
     * Memoria de esquema_listo() (sólo se memoriza el "sí": un "no" se vuelve a mirar, porque la
     * migración puede llegar con el proceso ya levantado).
     *
     * @var bool
     */
    protected static $esquema_confirmado = false;

    /**
     * Registra un freno de Vender.
     *
     * Si ya hay una fila vigente (sin cancelar) del mismo dueño + empleado + artículo + variante
     * + sucursal creada dentro de la ventana anti-duplicado, no crea otra: la actualiza con la
     * mayor cantidad pedida (null cuenta como menor), y con el stock y motivo del último freno.
     *
     * La validación está acá y no en el frontend porque es de seguridad multi-tenant (un artículo
     * de otro dueño nunca se guarda): razón fuerte para no confiar en la SPA.
     *
     * @param array $datos Body del POST (article_id, motivo, article_variant_id, combo_id, address_id,
     *                     employee_id, cantidad_pedida, stock_disponible).
     * @param int $owner_id Dueño del comercio.
     * @param int $auth_user_id Usuario autenticado (dueño o empleado).
     * @return array [UnmetDemand $model, bool $deduplicado]
     * @throws \InvalidArgumentException Con el mensaje que ve la SPA si el artículo o el motivo son inválidos.
     */
    public static function registrar(array $datos, int $owner_id, int $auth_user_id): array
    {
        $article_id = self::entero_o_null(isset($datos['article_id']) ? $datos['article_id'] : null);

        // El artículo tiene que existir y ser del dueño.
        $article_existe = $article_id !== null
            && Article::where('id', $article_id)->where('user_id', $owner_id)->exists();

        if (!$article_existe) {
            throw new \InvalidArgumentException('El artículo no existe.');
        }

        $motivo = isset($datos['motivo']) ? $datos['motivo'] : null;

        if (!in_array($motivo, self::MOTIVOS, true)) {
            throw new \InvalidArgumentException('Motivo inválido.');
        }

        // 0 / '' / ausente → null en los opcionales.
        $variant_id = self::entero_o_null(isset($datos['article_variant_id']) ? $datos['article_variant_id'] : null);
        $combo_id = self::entero_o_null(isset($datos['combo_id']) ? $datos['combo_id'] : null);
        $address_id = self::entero_o_null(isset($datos['address_id']) ? $datos['address_id'] : null);
        $cantidad_pedida = self::numero_o_null(isset($datos['cantidad_pedida']) ? $datos['cantidad_pedida'] : null);
        $stock_disponible = self::numero_o_null(isset($datos['stock_disponible']) ? $datos['stock_disponible'] : null);

        $employee_id = self::resolver_empleado(
            self::entero_o_null(isset($datos['employee_id']) ? $datos['employee_id'] : null),
            $owner_id,
            $auth_user_id
        );

        // Antiduplicado: misma fila vigente dentro de la ventana.
        $existente = UnmetDemand::where('user_id', $owner_id)
            ->where('employee_id', $employee_id)
            ->where('article_id', $article_id)
            ->where('article_variant_id', $variant_id)
            ->where('address_id', $address_id)
            ->whereNull('canceled_at')
            ->where('created_at', '>=', Carbon::now()->subMinutes(self::VENTANA_DUPLICADO_MINUTOS))
            ->orderBy('id', 'DESC')
            ->first();

        if ($existente) {
            $cantidad_vieja = $existente->cantidad_pedida;

            // Gana la cantidad mayor; null cuenta como menor.
            if ($cantidad_vieja !== null && ($cantidad_pedida === null || $cantidad_vieja > $cantidad_pedida)) {
                $cantidad_pedida = $cantidad_vieja;
            }

            $existente->cantidad_pedida = $cantidad_pedida;
            $existente->stock_disponible = $stock_disponible;
            $existente->motivo = $motivo;
            $existente->save();

            return [$existente, true];
        }

        $model = UnmetDemand::create([
            'user_id'            => $owner_id,
            'employee_id'        => $employee_id,
            'article_id'         => $article_id,
            'article_variant_id' => $variant_id,
            'combo_id'           => $combo_id,
            'address_id'         => $address_id,
            'motivo'             => $motivo,
            'cantidad_pedida'    => $cantidad_pedida,
            'stock_disponible'   => $stock_disponible,
        ]);

        return [$model, false];
    }

    /**
     * Cancela un freno: marca `canceled_at`, no borra. Idempotente (si ya estaba cancelado
     * conserva la primera fecha).
     *
     * @param int $id
     * @param int $owner_id
     * @return UnmetDemand|null null si no existe o es de otro dueño
     */
    public static function cancelar(int $id, int $owner_id): ?UnmetDemand
    {
        $model = UnmetDemand::where('id', $id)->where('user_id', $owner_id)->first();

        if (!$model) {
            return null;
        }

        if ($model->canceled_at === null) {
            $model->canceled_at = Carbon::now();
            $model->save();
        }

        return $model;
    }

    /**
     * Demanda no satisfecha vigente por artículo, para el motor de sugerencias: UNA consulta
     * agregada sobre las filas sin cancelar de los últimos $dias.
     * La cantidad suma `COALESCE(cantidad_pedida, 1)`: un freno al elegir (todavía sin cantidad)
     * cuenta como una unidad.
     *
     * @param int $owner_id
     * @param array $article_ids
     * @param int $dias Ventana hacia atrás.
     * @return array [article_id => ['veces' => int, 'cantidad' => float]]
     */
    public static function resumen_por_articulo(int $owner_id, array $article_ids, int $dias): array
    {
        $resumen = [];

        if (empty($article_ids)) {
            return $resumen;
        }

        $filas = DB::table('unmet_demands')
            ->select('article_id', DB::raw('COUNT(*) as veces'), DB::raw('SUM(COALESCE(cantidad_pedida, 1)) as cantidad'))
            ->where('user_id', $owner_id)
            ->whereIn('article_id', $article_ids)
            ->whereNull('canceled_at')
            ->where('created_at', '>=', Carbon::now()->subDays($dias))
            ->groupBy('article_id')
            ->get();

        foreach ($filas as $fila) {
            $resumen[(int) $fila->article_id] = [
                'veces'    => (int) $fila->veces,
                'cantidad' => (float) $fila->cantidad,
            ];
        }

        return $resumen;
    }

    /**
     * ¿Están las migraciones de la misión aplicadas? (tabla nueva + columnas de la línea de
     * sugerencia). Guarda de la ventana de deploy: el código puede llegar antes que la migración
     * y el motor no tiene que romperse ni agregar claves que la tabla no acepta.
     *
     * @return bool
     */
    public static function esquema_listo(): bool
    {
        if (self::$esquema_confirmado) {
            return true;
        }

        self::$esquema_confirmado = Schema::hasTable('unmet_demands')
            && Schema::hasColumn('purchase_suggestion_articles', 'demanda_no_satisfecha_veces');

        return self::$esquema_confirmado;
    }

    /**
     * Atribuye el freno a un empleado: el `employee_id` pedido si es el dueño o un usuario del
     * dueño; si no (ausente, 0 o de otro comercio), el usuario autenticado.
     *
     * @param int|null $employee_id
     * @param int $owner_id
     * @param int $auth_user_id
     * @return int
     */
    protected static function resolver_empleado(?int $employee_id, int $owner_id, int $auth_user_id): int
    {
        if ($employee_id === null) {
            return $auth_user_id;
        }

        $es_del_duenio = User::where('id', $employee_id)
            ->where(function ($query) use ($owner_id) {
                $query->where('id', $owner_id)->orWhere('owner_id', $owner_id);
            })
            ->exists();

        return $es_del_duenio ? $employee_id : $auth_user_id;
    }

    /**
     * Normaliza un id opcional: 0, '' o no numérico → null.
     *
     * @param mixed $valor
     * @return int|null
     */
    protected static function entero_o_null($valor): ?int
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return null;
        }

        $entero = (int) $valor;

        return $entero > 0 ? $entero : null;
    }

    /**
     * Normaliza un número opcional: ausente, '' o no numérico → null (el 0 se conserva, es un dato).
     *
     * @param mixed $valor
     * @return float|null
     */
    protected static function numero_o_null($valor): ?float
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return null;
        }

        return (float) $valor;
    }
}
