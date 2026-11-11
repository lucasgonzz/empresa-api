<?php

namespace App\Services;

use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Una foto por operación confirmada, no por cada escritura intermedia del cálculo.
 * Los pivotes se escriben ANTES de setFinalPrice: mirar solo el precio general allí
 * perdía los cambios exclusivos de listas (ticket 98). El scope exterior captura
 * el antes, incluye SQL en lote y hace que los espejos interiores no dupliquen eventos.
 */
class HistorialPreciosService
{
    private static $contexto;

    public static function activo()
    {
        return self::$contexto !== null;
    }

    public static function ejecutar(array $ids, $actor, $origen, callable $operacion)
    {
        if (self::activo()) {
            self::capturar($ids);
            return $operacion();
        }

        self::$contexto = [
            'antes' => [],
            'ids' => [],
            'actor' => $actor === null ? Auth::id() : $actor,
            'origen' => $origen,
        ];

        try {
            return DB::transaction(function () use ($ids, $operacion) {
                self::capturar($ids);
                $resultado = $operacion();
                self::registrar();
                return $resultado;
            });
        } finally {
            // Los workers y simulaciones reutilizan proceso: un rollback no debe
            // dejar el "antes" de una operación para el siguiente trabajo.
            self::$contexto = null;
        }
    }

    public static function capturar(array $ids)
    {
        if (!self::activo()) {
            return;
        }
        $nuevos = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !isset(self::$contexto['ids'][$id])) {
                self::$contexto['ids'][$id] = true;
                $nuevos[] = $id;
            }
        }
        if ($nuevos) {
            self::$contexto['antes'] += self::foto($nuevos, true);
        }
    }

    private static function decimal($valor)
    {
        return $valor === null ? null : RecalculoDePreciosEnLote::decimal_como_lo_guarda_mysql($valor, 2);
    }

    private static function foto(array $ids, $bloquear = false)
    {
        sort($ids, SORT_NUMERIC);
        $foto = [];
        foreach (array_chunk($ids, 500) as $tanda) {
            $consulta = DB::table('articles')->whereIn('id', $tanda)->orderBy('id');
            if ($bloquear) {
                $consulta->lockForUpdate();
            }
            $articulos = $consulta->get(['id', 'user_id', 'cost', 'price', 'final_price']);
            $con_moneda = DB::table('extencion_empresa_user as eu')
                ->join('extencion_empresas as e', 'e.id', '=', 'eu.extencion_empresa_id')
                ->whereIn('eu.user_id', $articulos->pluck('user_id')->unique()->all())
                ->where('e.slug', 'ventas_en_dolares')->pluck('eu.user_id')->flip()->all();
            $ids_con_moneda = [];
            foreach ($articulos as $articulo) {
                if (isset($con_moneda[$articulo->user_id])) {
                    $ids_con_moneda[] = $articulo->id;
                }
                $foto[$articulo->id] = [
                    'cost' => $articulo->cost,
                    'price' => $articulo->price,
                    'final_price' => $articulo->final_price,
                    'final_comparable' => self::decimal($articulo->final_price),
                    'listas' => [],
                    'pivotes' => [],
                ];
            }
            $pivotes = DB::table('article_price_type as apt')
                ->join('price_types as pt', 'pt.id', '=', 'apt.price_type_id')
                ->whereIn('apt.article_id', $tanda)->orderBy('apt.id')
                ->get(['apt.article_id', 'apt.price_type_id', 'apt.final_price', 'pt.name']);
            foreach ($pivotes as $pivote) {
                if (!isset($foto[$pivote->article_id])) {
                    continue;
                }
                $valor = self::decimal($pivote->final_price);
                // La relación histórica permite duplicados heredados. El lector
                // del catálogo elige la primera fila; la foto usa el mismo orden.
                if (array_key_exists($pivote->price_type_id, $foto[$pivote->article_id]['pivotes'])) {
                    continue;
                }
                $foto[$pivote->article_id]['pivotes'][$pivote->price_type_id] = $valor;
                $foto[$pivote->article_id]['listas'][$pivote->price_type_id . ':1'] = [
                    'lista_id' => (int) $pivote->price_type_id,
                    'lista_nombre' => $pivote->name,
                    'moneda_id' => 1,
                    'moneda_nombre' => 'Pesos',
                    'precio' => $valor,
                ];
            }
            $monedas = DB::table('article_price_type_monedas as apm')
                ->join('price_types as pt', 'pt.id', '=', 'apm.price_type_id')
                ->leftJoin('monedas as m', 'm.id', '=', 'apm.moneda_id')
                ->whereIn('apm.article_id', $ids_con_moneda)->orderBy('apm.id')
                ->get(['apm.article_id', 'apm.price_type_id', 'apm.moneda_id', 'apm.final_price', 'pt.name as lista_nombre', 'm.name as moneda_nombre']);
            $monedas_vistas = [];
            foreach ($monedas as $moneda) {
                if (!isset($foto[$moneda->article_id])) {
                    continue;
                }
                $clave_moneda = $moneda->article_id . ':' . $moneda->price_type_id . ':' . $moneda->moneda_id;
                if (isset($monedas_vistas[$clave_moneda])) {
                    continue;
                }
                $monedas_vistas[$clave_moneda] = true;
                // La fila en pesos por moneda y su pivote son el mismo precio
                // lógico. La fila por moneda es la fuente; el espejo no es otro cambio.
                $foto[$moneda->article_id]['listas'][$moneda->price_type_id . ':' . $moneda->moneda_id] = [
                    'lista_id' => (int) $moneda->price_type_id,
                    'lista_nombre' => $moneda->lista_nombre,
                    'moneda_id' => (int) $moneda->moneda_id,
                    'moneda_nombre' => $moneda->moneda_nombre,
                    'precio' => self::decimal($moneda->final_price),
                ];
            }
        }
        return $foto;
    }

    private static function registrar()
    {
        $despues = self::foto(array_keys(self::$contexto['ids']));
        $eventos = [];
        $fotos = [];
        $uuid = (string) Str::uuid();
        $ahora = now()->format('Y-m-d H:i:s');
        foreach ($despues as $id => $actual) {
            $previo = self::$contexto['antes'][$id] ?? ['final_price' => null, 'final_comparable' => null, 'listas' => []];
            $detalle = [];
            foreach (array_unique(array_merge(array_keys($previo['listas']), array_keys($actual['listas']))) as $clave) {
                $antes = $previo['listas'][$clave] ?? null;
                $nuevo = $actual['listas'][$clave] ?? null;
                if ($antes !== null && $nuevo !== null && $antes['precio'] === $nuevo['precio']) {
                    continue;
                }
                // Adjuntar una lista sin importe no es crear un precio. Una baja
                // de una fila con precio null sí conserva su identidad explícita.
                if ($antes === null && ($nuevo === null || $nuevo['precio'] === null)) {
                    continue;
                }
                $dato = $nuevo ?? $antes;
                unset($dato['precio']);
                $dato['precio_anterior'] = $antes['precio'] ?? null;
                $dato['precio_nuevo'] = $nuevo['precio'] ?? null;
                $dato['operacion'] = $antes === null ? 'alta' : ($nuevo === null ? 'baja' : 'cambio');
                $detalle[] = $dato;
            }
            if (!$detalle && $previo['final_comparable'] === $actual['final_comparable']) {
                continue;
            }
            $eventos[] = [
                'article_id' => $id,
                'cost' => $actual['cost'],
                'price' => $actual['price'],
                'final_price' => $actual['final_price'],
                'precio_final_anterior' => $previo['final_price'],
                'employee_id' => self::$contexto['actor'],
                'origen' => self::$contexto['origen'],
                'cambios_listas' => json_encode($detalle, JSON_UNESCAPED_UNICODE),
                'operacion_uuid' => $uuid,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
            $fotos[$id] = $actual['pivotes'];
        }
        foreach (array_chunk($eventos, 500) as $tanda) {
            DB::table('price_changes')->insert($tanda);
        }
        if (!$eventos) {
            return;
        }
        $pivotes = [];
        foreach (DB::table('price_changes')->where('operacion_uuid', $uuid)->get(['id', 'article_id']) as $evento) {
            foreach ($fotos[$evento->article_id] as $lista_id => $precio) {
                $pivotes[] = ['price_change_id' => $evento->id, 'price_type_id' => $lista_id, 'final_price' => $precio];
            }
        }
        foreach (array_chunk($pivotes, 500) as $tanda) {
            DB::table('price_change_price_type')->insert($tanda);
        }
    }
}
