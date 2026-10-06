<?php

namespace Tests\Feature\CategoryProposals;

use App\Models\Category;
use App\Models\CategoryProposalItem;
use App\Models\SubCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ayudas de los tests de la ingesta y de la lectura de la categorización con IA (misión
 * categorizacion-tres-modelos, 5/10/2026), del constructor API-1.
 *
 * Vive aparte de `CategoryProposalsTestCase` a propósito: esa base la edita solo el orquestador, y dos
 * constructores en el mismo worktree no pueden pisarse una clase. Se usa con `use ArmaCorridasDeCategorias;`
 * dentro de una clase que extienda `CategoryProposalsTestCase`.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
trait ArmaCorridasDeCategorias
{
    /** Las rutas de `admin-sync` de la categorización (sin el id de la corrida). */
    protected function url_admin($resto = '')
    {
        return 'api/admin-sync/catalogo/'.ltrim($resto, '/');
    }

    /**
     * POST a una ruta de `admin-sync/catalogo/*` con la clave de la skill.
     *
     * @param  string $resto   Lo que sigue a `admin-sync/catalogo/`.
     * @param  array  $cuerpo
     * @param  bool   $con_clave
     * @return \Illuminate\Testing\TestResponse
     */
    protected function post_admin($resto, array $cuerpo = [], $con_clave = true)
    {
        return $this->postJson($this->url_admin($resto), $cuerpo, $this->cabeceras_de_admin($con_clave));
    }

    /**
     * GET a una ruta de `admin-sync/catalogo/*` con la clave de la skill.
     *
     * @param  string $resto
     * @param  bool   $con_clave
     * @return \Illuminate\Testing\TestResponse
     */
    protected function get_admin($resto, $con_clave = true)
    {
        return $this->getJson($this->url_admin($resto), $this->cabeceras_de_admin($con_clave));
    }

    /**
     * Las propuestas del cuerpo de `crear` con la forma del contrato: la tarjeta A (con árbol) y,
     * opcionalmente, la B.
     *
     * @param  bool $con_b  Suma una segunda tarjeta nueva.
     * @return array
     */
    protected function propuestas_de_ejemplo($con_b = false)
    {
        $propuestas = [
            [
                'clave'       => 'A',
                'tipo'        => 'nueva',
                'nombre'      => 'Por rubro, como en góndola',
                'resumen'     => 'Las categorías de una ferretería de barrio.',
                'descripcion' => 'Se basa en cómo se ordena la góndola de una ferretería.',
                'arbol'       => [
                    ['nombre' => 'Bisagras', 'subcategorias' => ['De cierre suave', 'Comunes']],
                    ['nombre' => 'Correderas'],
                ],
            ],
        ];

        if ($con_b) {

            $propuestas[] = [
                'clave'   => 'B',
                'tipo'    => 'nueva',
                'nombre'  => 'Por uso',
                'resumen' => 'Según para qué se usa.',
                'arbol'   => [
                    ['nombre' => 'Muebles', 'subcategorias' => ['Cocina']],
                    ['nombre' => 'Puertas y ventanas'],
                ],
            ];
        }

        return $propuestas;
    }

    /**
     * Crea una corrida por el endpoint de la skill y devuelve el JSON de la respuesta (afirma el 201).
     *
     * @param  array|null $propuestas  Por defecto, las de ejemplo con A y B.
     * @param  bool       $reemplazar
     * @return array
     */
    protected function crear_corrida_por_api($propuestas = null, $reemplazar = false)
    {
        $propuestas = is_null($propuestas) ? $this->propuestas_de_ejemplo(true) : $propuestas;

        $respuesta = $this->post_admin('categorias/propuestas', [
            'reemplazar' => $reemplazar,
            'propuestas' => $propuestas,
        ]);

        $respuesta->assertStatus(201);

        return $respuesta->json();
    }

    /**
     * Inserta muchos artículos activos de un saque (sin pasar por Eloquent: con cientos de filas el
     * `create` uno por uno es lento y acá no se prueba nada de los observers).
     *
     * @param  int $cantidad
     * @param  \App\Models\User|null $dueno
     * @param  string $prefijo
     * @return int[]  Los ids, en orden.
     */
    protected function crear_articulos_en_masa($cantidad, $dueno = null, $prefijo = 'Articulo')
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;
        $ahora = Carbon::now();

        $filas = [];

        for ($i = 1; $i <= $cantidad; $i++) {

            $filas[] = [
                'name'       => $prefijo.' '.$i,
                'user_id'    => $dueno->id,
                'status'     => 'active',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        foreach (array_chunk($filas, 200) as $lote) {

            DB::table('articles')->insert($lote);
        }

        return DB::table('articles')
            ->where('user_id', $dueno->id)
            ->where('name', 'like', $prefijo.' %')
            ->orderBy('id')
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }

    /**
     * Una categoría real del comercio (con Eloquent, como la crea el sistema).
     *
     * @param  string $nombre
     * @param  \App\Models\User|null $dueno
     * @return \App\Models\Category
     */
    protected function crear_categoria_real($nombre, $dueno = null)
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        return Category::create(['name' => $nombre, 'user_id' => $dueno->id]);
    }

    /**
     * Una subcategoría real de una categoría real.
     *
     * @param  string $nombre
     * @param  \App\Models\Category $categoria
     * @return \App\Models\SubCategory
     */
    protected function crear_subcategoria_real($nombre, Category $categoria)
    {
        return SubCategory::create(['name' => $nombre, 'category_id' => $categoria->id, 'user_id' => $categoria->user_id]);
    }

    /**
     * El `resumen` de la SPA para la sesión actual (afirma el 200).
     *
     * @return array
     */
    protected function resumen()
    {
        return $this->getJson('api/category-proposal-runs/resumen')->assertStatus(200)->json();
    }

    /**
     * El `actual` de la SPA para la sesión actual (afirma el 200).
     *
     * @return array
     */
    protected function actual()
    {
        return $this->getJson('api/category-proposal-runs/actual')->assertStatus(200)->json();
    }

    /**
     * El `items` de la SPA para una corrida y una solapa.
     *
     * @param  int    $run_id
     * @param  string $query  Lo que sigue a `?`.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function items($run_id, $query = '')
    {
        return $this->getJson('api/category-proposal-runs/'.$run_id.'/items'.($query === '' ? '' : '?'.$query));
    }

    /**
     * Una corrida con la propuesta A (nueva) sembrada a mano, con seis artículos:
     *
     *   Bisagras (Comunes, De cierre suave) · Correderas
     *   art1 → Bisagras/Comunes (segura) · art2 → Bisagras/De cierre suave (segura)
     *   art3 → Bisagras (dudosa) · art4 → Correderas (segura) · art5 → Correderas (dudosa)
     *   art6 → ninguna
     *
     * @param  string $estado
     * @param  \App\Models\User|null $dueno
     * @param  array $extra_de_articulos  Columnas extra por índice de artículo (1 a 6).
     * @return array  ['run', 'propuestas' => ['A' => [...]], 'articulos' => [1 => Article, ...]]
     */
    protected function corrida_con_a($estado = 'lista', $dueno = null, array $extra_de_articulos = [])
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        $articulos = [];

        foreach ([1, 2, 3, 4, 5, 6] as $n) {

            $articulos[$n] = $this->crear_articulo('Articulo '.$n.' de '.$dueno->id, isset($extra_de_articulos[$n]) ? $extra_de_articulos[$n] : [], $dueno);
        }

        $sembrado = $this->sembrar_corrida([
            'estado'          => $estado,
            'articulos_total' => 6,
            'propuestas'      => [
                'A' => [
                    'tipo'        => 'nueva',
                    'nombre'      => 'Por rubro',
                    'resumen'     => 'Resumen de A',
                    'descripcion' => 'Descripcion de A',
                    'arbol'       => ['Correderas' => [], 'Bisagras' => ['De cierre suave', 'Comunes']],
                    'items'       => [
                        [$articulos[1], 'Bisagras', 'Comunes', 'segura'],
                        [$articulos[2], 'Bisagras', 'De cierre suave', 'segura'],
                        [$articulos[3], 'Bisagras', null, 'dudosa', 'no queda claro'],
                        [$articulos[4], 'Correderas', null, 'segura'],
                        [$articulos[5], 'Correderas', null, 'dudosa'],
                        [$articulos[6], null, null, 'ninguna', 'ambiguo'],
                    ],
                ],
            ],
        ], $dueno);

        $sembrado['articulos'] = $articulos;

        return $sembrado;
    }

    /**
     * Una corrida `elegida` con la propuesta A y los seis ítems en estados distintos para probar las
     * solapas: a_revisar (art3, art5), asignados (art1 aplicada, art2 aprobada, art4 aplicada) y
     * sin_categoria (art6 sin_asignar).
     *
     * @param  array $extra_de_articulos
     * @param  \App\Models\User|null $dueno
     * @return array  Lo de `corrida_con_a` más `items` (por número de artículo).
     */
    protected function corrida_elegida_para_revisar(array $extra_de_articulos = [], $dueno = null)
    {
        $sembrado = $this->corrida_con_a('elegida', $dueno, $extra_de_articulos);
        $a        = $sembrado['propuestas']['A']['proposal'];

        $run = $sembrado['run'];
        $run->propuesta_elegida_id = $a->id;
        $run->save();

        $estados = [1 => 'aplicada', 2 => 'aprobada', 3 => 'a_revisar', 4 => 'aplicada', 5 => 'a_revisar', 6 => 'sin_asignar'];

        $por_numero = [];

        foreach ($sembrado['articulos'] as $n => $articulo) {

            $item = CategoryProposalItem::where('proposal_id', $a->id)->where('article_id', $articulo->id)->first();

            $item->estado = $estados[$n];
            $item->save();

            $por_numero[$n] = $item;
        }

        $sembrado['items'] = $por_numero;

        return $sembrado;
    }

    /**
     * Cuántas consultas SQL cuesta lo que hace el callback (con el log de consultas prendido). Las
     * devuelve junto con el texto de cada una para poder mirar qué se pidió.
     *
     * @param  callable $callback
     * @return array  ['cantidad' => int, 'consultas' => string[]]
     */
    protected function medir_consultas(callable $callback)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $log = DB::getQueryLog();

        DB::disableQueryLog();

        $consultas = [];

        foreach ($log as $fila) {

            $consultas[] = $fila['query'];
        }

        return ['cantidad' => count($consultas), 'consultas' => $consultas];
    }
}
