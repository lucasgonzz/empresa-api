<?php

namespace App\Services\Importacion;

use App\Http\Controllers\Helpers\import\article\IdentifierNormalizer;
use App\Http\Controllers\Helpers\import\article\motor\StockEnLote;
use App\Models\Address;
use App\Models\Advise;
use App\Models\Article;
use App\Models\ImportacionDePrueba;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

class PlanillaStockExistente
{
    private $planilla;
    private $run;

    public function __construct(ImportacionDePrueba $run)
    {
        $this->run = $run;
        $this->planilla = new PlanillaDePrueba($run);
    }

    public function preparar(): array
    {
        $preguntas = [];
        $depositos = Address::where('user_id', $this->run->user_id)->whereNull('buyer_id')
            ->orderBy('id')->lockForUpdate()->get(['id', 'street']);
        $opciones_deposito = $depositos->mapWithKeys(function (Address $deposito) {
            return ['d' . $deposito->id => $deposito->street];
        })->all();
        $deposito = $this->elegir($preguntas, 'stock_deposito', 'En qué depósito se hizo el conteo?', $opciones_deposito);
        $opciones_hoja = collect($this->run->hojas ?? [])->mapWithKeys(function (array $hoja) {
            return ['h' . $hoja['indice'] => $hoja['nombre']];
        })->all();
        $hoja_elegida = $this->elegir($preguntas, 'stock_hoja', 'En qué hoja está el conteo que querés cargar?', $opciones_hoja);
        if ($deposito === null || $hoja_elegida === null) {
            return ['preguntas' => $preguntas];
        }

        $hoja = collect($this->run->hojas)->first(function (array $hoja) use ($hoja_elegida) {
            return 'h' . $hoja['indice'] === $hoja_elegida;
        });
        $deposito = $depositos->firstWhere('id', (int) substr($deposito, 1));
        $codigo = $this->elegir_columna($hoja, 'codigo', $preguntas, $deposito);
        $cantidad = $this->elegir_columna($hoja, 'cantidad', $preguntas, $deposito);
        if ($codigo === null || $cantidad === null) {
            return ['preguntas' => $preguntas];
        }
        if ($codigo === $cantidad) {
            throw new RuntimeException('El código del artículo y la cantidad de stock quedaron en la misma columna.');
        }

        $hoja['mapeo'] = ['codigo' => $codigo, 'cantidad' => $cantidad];
        $hoja['proveedor'] = null;
        $hoja['descuentos_por_proveedor'] = [];
        $grupos = [];
        $omitidas = 0;
        $filas_para_revisar = [];
        foreach ($this->planilla->filas([$hoja]) as $referencia => $fila) {
            $valor = $fila['cantidad'];
            if ($valor === null || trim($valor) === '') {
                continue;
            }
            $cantidad = self::cantidad($valor);
            $codigo = self::codigo($fila['codigo']);
            if ($cantidad === null || $codigo === null) {
                $omitidas++;
                continue;
            }
            $numero = (int) explode(':', $referencia, 2)[1];
            $grupos[$codigo][$numero] = $cantidad;
        }

        $tipo = self::tipo_codigo($hoja['columnas'][$hoja['mapeo']['codigo']]['titulo']);
        $coincidencias = $this->coincidencias(array_keys($grupos), $tipo);
        $objetivos = $this->elegir_filas($hoja, $grupos, $coincidencias, $preguntas, $omitidas, $filas_para_revisar);
        if ($preguntas !== []) {
            return ['preguntas' => $preguntas];
        }

        return $this->armar_plan($deposito, $depositos->count(), $objetivos, $omitidas) + [
            'filas_para_revisar' => array_slice($filas_para_revisar, 0, 6),
        ];
    }

    private function elegir(array &$preguntas, string $clave, string $texto, array $opciones): ?string
    {
        $elegida = $this->run->decisiones[$clave] ?? null;
        if ($elegida !== null && isset($opciones[$elegida])) {
            return $elegida;
        }
        if (count($opciones) === 1) {
            return (string) array_key_first($opciones);
        }
        if ($opciones !== []) {
            $preguntas[$clave] = PlanillaDePrueba::pregunta($clave, $texto, $opciones);
        }

        return null;
    }

    private function elegir_columna(array $hoja, string $dato, array &$preguntas, Address $deposito): ?int
    {
        $opciones = [];
        $reconocidas = [];
        foreach ($hoja['columnas'] as $posicion => $columna) {
            $letra = Coordinate::stringFromColumnIndex((int) $posicion + 1);
            $etiqueta = 'Columna ' . $letra . ($columna['titulo'] === '' ? '' : ' (' . $columna['titulo'] . ')');
            $clave = 'c' . $posicion;
            $titulo = self::titulo($columna['titulo']);
            $opciones[$clave] = $etiqueta;
            if ($dato === 'codigo' && self::tipo_codigo($titulo) !== null) {
                $reconocidas[$clave] = $etiqueta;
            }
            if ($dato === 'cantidad' && (in_array($titulo, ['stock', 'stock actual', 'stock contado', 'cantidad contada', 'existencias'], true)
                || $titulo === self::titulo($deposito->street))) {
                $reconocidas[$clave] = $etiqueta;
            }
        }

        $texto = 'En qué columna de «' . $hoja['nombre'] . '» está '
            . ($dato === 'codigo' ? 'el código del artículo?' : 'el total de unidades contadas?');
        $elegida = $this->elegir($preguntas, 'stock_columna_' . $dato . '_' . $hoja['indice'], $texto, $reconocidas ?: $opciones);

        return $elegida === null ? null : (int) substr($elegida, 1);
    }

    private static function titulo(string $valor): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($valor))));
    }

    private static function tipo_codigo(string $titulo): ?string
    {
        $titulo = self::titulo($titulo);
        $tipos = [
            'numero' => ['numero', 'numero de articulo', 'nro', 'nro articulo'],
            'bar_code' => ['codigo de barras', 'codigo barras', 'ean', 'barcode', 'bar code'],
            'sku' => ['sku', 'codigo sku'],
            'provider_code' => ['codigo de proveedor', 'codigo proveedor', 'cod proveedor'],
            'general' => ['codigo', 'cod', 'codigo de articulo', 'codigo articulo', 'codigo producto'],
        ];
        foreach ($tipos as $tipo => $titulos) {
            if (in_array($titulo, $titulos, true)) {
                return $tipo;
            }
        }

        return null;
    }

    private static function codigo($valor): ?string
    {
        $codigo = IdentifierNormalizer::normalize($valor);

        return $codigo === null ? null : mb_strtolower($codigo);
    }

    public static function codigo_de_respuesta(string $respuesta): ?string
    {
        return preg_match('/\Acodigo:([^\p{C}]{1,100})\z/u', $respuesta, $capturas)
            ? self::codigo($capturas[1])
            : null;
    }

    public function codigo_inequivoco(string $codigo): bool
    {
        return count($this->coincidencias([$codigo], 'general')[$codigo] ?? []) === 1;
    }

    private static function cantidad(string $valor): ?float
    {
        // Se aceptan decimales de stock, sin separadores de miles que puedan confundirse con decimales.
        $valor = trim($valor);
        if (!preg_match('/^\d{1,10}(?:[,.]\d{1,2})?$/D', $valor)) {
            return null;
        }

        return (float) str_replace(',', '.', $valor);
    }

    private function coincidencias(array $codigos, ?string $tipo): array
    {
        if ($codigos === []) {
            return [];
        }
        $tipo = $tipo ?? 'general';
        $campos = $tipo === 'general' ? ['sku', 'bar_code', 'provider_code'] : [$tipo === 'numero' ? 'num' : $tipo];
        $buscados = array_fill_keys($codigos, true);
        $coincidencias = [];
        $articulos = Article::where('user_id', $this->run->user_id)->select(array_merge(['id', 'provider_id'], $campos))->cursor();
        foreach ($articulos as $articulo) {
            foreach ($campos as $campo) {
                if ($campo === 'provider_code' && $this->run->provider_id !== null
                    && (int) $articulo->provider_id !== (int) $this->run->provider_id) {
                    continue;
                }
                $codigo = self::codigo($articulo->{$campo});
                if ($codigo !== null && isset($buscados[$codigo])) {
                    $coincidencias[$codigo][(int) $articulo->id] = true;
                }
            }
        }
        if (in_array('provider_code', $campos, true)) {
            $proveedores = DB::table('article_provider as ap')->join('articles as a', 'a.id', '=', 'ap.article_id')
                ->where('a.user_id', $this->run->user_id)->whereNull('a.deleted_at')
                ->whereNotNull('ap.provider_code')->select('ap.article_id', 'ap.provider_code');
            if ($this->run->provider_id !== null) {
                $proveedores->where('ap.provider_id', $this->run->provider_id);
            }
            foreach ($proveedores->cursor() as $proveedor) {
                $codigo = self::codigo($proveedor->provider_code);
                if ($codigo !== null && isset($buscados[$codigo])) {
                    $coincidencias[$codigo][(int) $proveedor->article_id] = true;
                }
            }
        }

        return $coincidencias;
    }

    private function elegir_filas(
        array $hoja,
        array $grupos,
        array $coincidencias,
        array &$preguntas,
        int &$omitidas,
        array &$filas_para_revisar
    ): array
    {
        $codigos_corregidos = [];
        foreach ($this->run->decisiones ?? [] as $clave => $respuesta) {
            if (strpos($clave, 'stock_codigo_fila_') === 0) {
                $codigo = self::codigo_de_respuesta($respuesta);
                if ($codigo !== null) {
                    $codigos_corregidos[$clave] = $codigo;
                }
            }
        }
        $corregidos = $this->coincidencias(array_values($codigos_corregidos), 'general');
        $faltantes = count(array_diff_key($grupos, $coincidencias));
        $por_articulo = [];
        foreach ($grupos as $codigo => $filas) {
            $ids = array_keys($coincidencias[$codigo] ?? []);
            $codigo_ambiguo = count($ids) > 1 || count(array_unique($filas)) > 1
                || ($ids === [] && $faltantes <= 6);
            foreach ($filas as $numero => $cantidad) {
                $id = count($ids) === 1 ? $ids[0] : null;
                if ($codigo_ambiguo) {
                    $clave = 'stock_codigo_fila_' . $hoja['indice'] . '_' . $numero;
                    $ids_corregidos = array_keys($corregidos[$codigos_corregidos[$clave] ?? ''] ?? []);
                    if (count($ids_corregidos) === 1) {
                        $id = $ids_corregidos[0];
                    } else {
                        $decision = $this->run->decisiones[$clave] ?? null;
                        if ($decision === 'omitir') {
                            $id = null;
                        } elseif ($decision !== 'usar_original' || $id === null) {
                            if (count($preguntas) < 12) {
                                $preguntas[$clave] = $this->pregunta_codigo($clave, $hoja, $numero, (string) $codigo, $cantidad, $id);
                            }
                            continue;
                        }
                    }
                }
                if ($id === null) {
                    $omitidas++;
                    $filas_para_revisar[] = $numero;
                } else {
                    $por_articulo[$id][$numero] = $cantidad;
                }
            }
        }

        $objetivos = [];
        foreach ($por_articulo as $id => $filas) {
            if (count(array_unique($filas)) === 1) {
                $objetivos[$id] = reset($filas);
                continue;
            }
            $opciones = ['omitir' => 'Omitir esas filas'];
            foreach ($filas as $numero => $cantidad) {
                $opciones['f' . $numero] = 'Fila ' . $numero . ' (' . $cantidad . ' unidades)';
            }
            $clave = 'stock_filas_' . substr(hash('sha256', json_encode([$hoja['indice'], $id, $filas])), 0, 32);
            $filas_mencionadas = implode(', ', array_slice(array_keys($filas), 0, 8));
            $texto = 'En «' . $hoja['nombre'] . '» las filas ' . $filas_mencionadas
                . ' apuntan al mismo artículo y tienen cantidades distintas. Qué fila corresponde o preferís omitirlas?'
                . ' Si son artículos distintos, podés mandar otro Excel con solo esas filas y los códigos corregidos.';
            $decision = $this->elegir($preguntas, $clave, $texto, $opciones);
            if ($decision === 'omitir') {
                $omitidas += count($filas);
                $filas_para_revisar = array_merge($filas_para_revisar, array_keys($filas));
            } elseif ($decision !== null) {
                $objetivos[$id] = $filas[(int) substr($decision, 1)];
                $omitidas += count($filas) - 1;
            }
        }

        ksort($objetivos);
        return $objetivos;
    }

    private function pregunta_codigo(string $clave, array $hoja, int $numero, string $codigo, float $cantidad, ?int $id): array
    {
        $opciones = ['omitir' => 'Omitir esta fila'];
        if ($id !== null) {
            $opciones['usar_original'] = 'Usar el código de esa fila';
        }
        $texto = 'En «' . $hoja['nombre'] . '», la fila ' . $numero . ' tiene el código «'
            . mb_substr($codigo, 0, 70) . '» y ' . $cantidad . ' unidades. Qué código de artículo corresponde a esa fila?'
            . ' Podés pasar el SKU, código de barras o código de proveedor, pedir que la omita o mandar otro Excel con solo las filas corregidas.';

        return PlanillaDePrueba::pregunta($clave, $texto, $opciones) + ['acepta_codigo_articulo' => true];
    }

    private function armar_plan(Address $deposito, int $depositos, array $objetivos, int $omitidas): array
    {
        $articulos = Article::where('user_id', $this->run->user_id)->whereIn('id', array_keys($objetivos))
            ->orderBy('id')->lockForUpdate()->get(['id', 'stock', 'name', 'sku', 'provider_code', 'bar_code'])->keyBy('id');
        $variantes = DB::table('article_variants')->whereIn('article_id', $articulos->keys())
            ->lockForUpdate()->pluck('article_id')->flip();
        $pivots = DB::table('address_article')->whereIn('article_id', $articulos->keys())
            ->orderBy('article_id')->orderBy('address_id')->lockForUpdate()->get()->groupBy('article_id');
        $ajustes = [];
        $iguales = 0;
        $repuestos = [];
        foreach ($objetivos as $id => $cantidad) {
            $articulo = $articulos->get($id);
            $filas = $pivots->get($id, collect());
            $ubicacion = $filas->where('address_id', $deposito->id);
            $stock_global_sin_asignar = $filas->isEmpty() && $depositos > 1 && (float) ($articulo->stock ?? 0) !== 0.0;
            if ($articulo === null || $variantes->has($id) || $ubicacion->count() > 1 || $stock_global_sin_asignar) {
                $omitidas++;
                continue;
            }
            $actual = $ubicacion->count() === 1 ? $ubicacion->first()->amount : null;
            if ($actual !== null && (float) $actual === $cantidad) {
                $iguales++;
                continue;
            }
            $total_resultante = (float) $filas->sum('amount') - (float) $actual + $cantidad;
            if ((float) $articulo->stock < 1.0 && $total_resultante >= 1.0) {
                $repuestos[] = $id;
            }
            $ajustes[$id] = ['actual' => $actual, 'cantidad' => $cantidad, 'total' => round($total_resultante, 2)];
        }

        $con_aviso = Advise::whereIn('article_id', $repuestos)->lockForUpdate()->pluck('article_id')->unique()->all();
        $avisos = count($con_aviso);
        $codigos_con_aviso = [];
        foreach (array_slice(array_keys(array_intersect_key($ajustes, array_fill_keys($con_aviso, true))), 0, 4) as $id) {
            $articulo = $articulos->get($id);
            $codigo = $articulo->sku ?: ($articulo->provider_code ?: ($articulo->bar_code ?: $articulo->name));
            $codigos_con_aviso[] = mb_substr(trim(preg_replace('/\s+/u', ' ', $codigo)), 0, 60);
        }
        // El flujo estándar consume los avisos cuando vuelve el stock. Esas filas se dejan afuera.
        $ajustes = array_diff_key($ajustes, array_fill_keys($con_aviso, true));

        return [
            'preguntas' => [], 'deposito' => (int) $deposito->id, 'nombre_deposito' => $deposito->street,
            'ajustes' => $ajustes, 'iguales' => $iguales, 'omitidas' => $omitidas, 'avisos' => $avisos,
            'codigos_con_aviso' => $codigos_con_aviso,
        ];
    }

    public function aplicar(array $plan, User $dueno): void
    {
        $lote = new StockEnLote($dueno, $dueno->id);
        $articulos = Article::where('user_id', $dueno->id)->whereIn('id', array_keys($plan['ajustes']))->get()->keyBy('id');
        foreach ($plan['ajustes'] as $id => $ajuste) {
            $lote->agregar_por_depositos($articulos->get($id), [[
                'address_id' => $plan['deposito'],
                'amount' => round($ajuste['cantidad'] - (float) $ajuste['actual'], 2),
            ]]);
        }
        $lote->volcar(true);

        $cantidades = DB::table('address_article')->where('address_id', $plan['deposito'])
            ->whereIn('article_id', $articulos->keys())->get(['article_id', 'amount'])->groupBy('article_id');
        $totales = Article::where('user_id', $dueno->id)->whereIn('id', $articulos->keys())->pluck('stock', 'id');
        foreach ($plan['ajustes'] as $id => $ajuste) {
            $filas = $cantidades->get($id, collect());
            if ($filas->count() !== 1 || $filas->first()->amount === null
                || (float) $filas->first()->amount !== $ajuste['cantidad'] || (float) $totales->get($id) !== $ajuste['total']) {
                throw new RuntimeException('El stock guardado no coincide con el conteo confirmado.');
            }
        }
    }
}
