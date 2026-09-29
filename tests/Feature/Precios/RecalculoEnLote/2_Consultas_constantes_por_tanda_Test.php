<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Las consultas del motor por tanda no crecen con los artículos (misión
 * recalculo-precios-motor-rapido, 28/9/2026).
 *
 * Es la propiedad que hace rápido al motor: el recálculo de hoy hacía decenas de consultas POR
 * ARTÍCULO (en Servian, una de 200 ms solo para leer los descuentos), y el motor hace un número
 * fijo por tanda —las listas del dueño, la lectura de los artículos con sus relaciones precargadas,
 * el memo de categorías— más las escrituras en bloque.
 *
 * Se mide con 50 y con 500 artículos de la misma cuenta (los dos entran en una tanda de 1.000):
 *  - las LECTURAS tienen que ser exactamente las mismas;
 *  - en la cuenta sin listas (la de Servian), el TOTAL también: 50 y 500 filas caben en un solo
 *    UPDATE y un solo INSERT;
 *  - con listas, las escrituras crecen solo por el corte de las sentencias en bloque (pares de a
 *    250, filas de a 500): se acota con el máximo que da ese corte, no por artículo.
 *
 * Los datos se insertan en bloque (DB::table) para que armar 550 artículos no tarde más que
 * medirlos. Antes de medir se hace una corrida de calentamiento: lo que vive en estáticas del
 * proceso (columnas de la tabla, caché de métodos de pago) se consulta una vez por worker, no por
 * tanda, y no es lo que se mide acá.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Consultas_constantes_por_tanda_Test extends RecalculoEnLoteTestCase
{
    /** @var array SQL ejecutado mientras $contando está prendido. */
    protected $consultas = [];

    /** @var bool */
    protected $contando = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultas = [];
        $this->contando  = false;

        DB::listen(function ($query) {
            if ($this->contando) {
                $this->consultas[] = $query->sql;
            }
        });
    }

    /**
     * @return array
     */
    public function configuraciones()
    {
        return [
            'cuenta simple sin listas (Servian)'  => ['servian'],
            'tres listas con recargos'            => ['listas'],
            'listas por categoria y subcategoria' => ['categorias'],
            'ventas en dolares'                   => ['dolares'],
        ];
    }

    /**
     * @dataProvider configuraciones
     *
     * @param  string $configuracion
     * @return void
     */
    public function test_las_consultas_del_motor_no_crecen_con_los_articulos($configuracion)
    {
        $escenario = $this->armar($configuracion, 550);

        $dueno   = $escenario['dueno'];
        $ids_50  = array_slice($escenario['ids'], 0, 50);
        $ids_500 = array_slice($escenario['ids'], 50, 500);

        /* Calentamiento: estáticas del proceso. */
        $this->medir($dueno, $ids_50);

        $con_50  = $this->medir($dueno, $ids_50);
        $con_500 = $this->medir($dueno, $ids_500);

        $lecturas_50  = $this->lecturas($con_50['consultas']);
        $lecturas_500 = $this->lecturas($con_500['consultas']);

        fwrite(STDERR, sprintf(
            "\n[RecalculoEnLote] %s: 50 artículos = %d consultas (%d lecturas); 500 artículos = %d consultas (%d lecturas)\n",
            $configuracion,
            count($con_50['consultas']),
            count($lecturas_50),
            count($con_500['consultas']),
            count($lecturas_500)
        ));

        /* Con VER_CONSULTAS=1 en el entorno se imprime la lista entera (para el informe). */
        if (getenv('VER_CONSULTAS')) {
            fwrite(STDERR, implode("
", $con_500['consultas']) . "
");
        }

        /* Guardas: el escenario tiene que haber recalculado y cambiado precios de verdad. */
        $this->assertCount(50, $con_50['resultado']['cambiaron'], 'Los 50 artículos tenían que cambiar de precio.');
        $this->assertCount(500, $con_500['resultado']['cambiaron'], 'Los 500 artículos tenían que cambiar de precio.');

        $this->assertSame(
            count($lecturas_50),
            count($lecturas_500),
            "Las lecturas del motor crecen con los artículos.\n50:\n" . implode("\n", $lecturas_50) . "\n500:\n" . implode("\n", $lecturas_500)
        );

        $this->assertLessThan(40, count($lecturas_500), 'Demasiadas lecturas para una tanda: ' . implode("\n", $lecturas_500));

        if ($configuracion === 'servian') {

            $this->assertSame(
                count($con_50['consultas']),
                count($con_500['consultas']),
                "Sin listas, 50 y 500 artículos son las mismas consultas.\n50:\n" . implode("\n", $con_50['consultas']) . "\n500:\n" . implode("\n", $con_500['consultas'])
            );

        } else {

            $this->assertLessThanOrEqual(
                15,
                count($con_500['consultas']) - count($lecturas_500),
                'Las escrituras de 500 artículos no pueden pasar del corte de las sentencias en bloque: ' . implode("\n", $con_500['consultas'])
            );
        }
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * Corre el motor sobre los ids adentro de un savepoint (que se revierte) con los precios
     * pisados, así todos cambian y se escriben los price_changes. Cuenta solo las consultas del
     * motor.
     *
     * @param  \App\Models\User $dueno
     * @param  array            $ids
     * @return array ['consultas' => string[], 'resultado' => array]
     */
    protected function medir($dueno, array $ids)
    {
        DB::beginTransaction();

        Carbon::setTestNow(self::AHORA);

        $this->pisar_precio_final($ids, 1);

        $owner = User::find($dueno->id);

        $this->consultas = [];
        $this->contando  = true;

        $resultado = RecalculoDePreciosEnLote::recalcular($ids, $owner);

        $this->contando = false;

        $consultas = $this->consultas;

        Carbon::setTestNow();

        DB::rollBack();

        return [
            'consultas' => $consultas,
            'resultado' => $resultado,
        ];
    }

    /**
     * @param  array $consultas
     * @return array
     */
    protected function lecturas(array $consultas)
    {
        return array_values(array_filter($consultas, function ($sql) {
            return preg_match('/^\s*(select|show)\b/i', $sql) === 1;
        }));
    }

    /**
     * Arma la cuenta y $cantidad artículos con relaciones, en bloque.
     *
     * @param  string $configuracion
     * @param  int    $cantidad
     * @return array ['dueno' => User, 'ids' => int[]]
     */
    protected function armar($configuracion, $cantidad)
    {
        $flags       = [];
        $extensiones = [];

        if ($configuracion === 'servian') {
            $flags = ['aplicar_iva_al_costo' => 1, 'redondear_precios_en_centavos' => 1];
        }

        if ($configuracion === 'listas') {
            $flags = ['listas_de_precio' => 1];
        }

        if ($configuracion === 'categorias') {
            $extensiones = ['lista_de_precios_por_categoria'];
        }

        if ($configuracion === 'dolares') {
            $flags       = ['listas_de_precio' => 1];
            $extensiones = ['ventas_en_dolares'];
        }

        $dueno = $this->crear_dueno($flags, $extensiones);

        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 40]);

        $categorias    = [];
        $subcategorias = [];

        for ($i = 0; $i < 3; $i++) {
            $categorias[]    = $this->crear_categoria($dueno, ['percentage_gain' => 5 + $i]);
            $subcategorias[] = $this->crear_subcategoria($dueno, $categorias[$i]);
        }

        $listas = [];

        if ($configuracion === 'listas') {
            $listas[] = $this->crear_lista($dueno, 'Minorista', 60, 3, [['percentage' => 5], ['amount' => 10]]);
            $listas[] = $this->crear_lista($dueno, 'Mayorista', 35, 2, [['percentage' => 2]]);
            $listas[] = $this->crear_lista($dueno, 'Gremio', 20, 1);
        }

        if ($configuracion === 'categorias') {

            $listas[] = $this->crear_lista($dueno, 'Cat A', null, 1, [['percentage' => 3]]);
            $listas[] = $this->crear_lista($dueno, 'Cat B', null, 2);

            foreach ($categorias as $i => $categoria) {
                $categoria->price_types()->attach($listas[0]->id, ['percentage' => 30 + $i]);
                $categoria->price_types()->attach($listas[1]->id, ['percentage' => 50 + $i]);
            }

            /* La primera subcategoría con sus propios márgenes; las otras caen a su categoría. */
            $subcategorias[0]->price_types()->attach($listas[0]->id, ['percentage' => 70]);
        }

        if ($configuracion === 'dolares') {
            $listas[] = $this->crear_lista($dueno, 'Pesos y dolares', 40, 1);
        }

        $ahora = Carbon::now()->format('Y-m-d H:i:s');

        $filas = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $filas[] = [
                'name'            => 'zz Articulo consultas ' . $configuracion . ' ' . $i,
                'user_id'         => $dueno->id,
                'status'          => 'active',
                'cost'            => 100 + ($i % 97) * 1.37,
                'cost_in_dollars' => $configuracion === 'dolares' ? $i % 2 : 0,
                'provider_id'     => $proveedor->id,
                'category_id'     => $categorias[$i % 3]->id,
                'sub_category_id' => $subcategorias[$i % 3]->id,
                'iva_id'          => self::IVA_21,
                'created_at'      => $ahora,
                'updated_at'      => $ahora,
            ];
        }

        foreach (array_chunk($filas, 200) as $tanda) {
            DB::table('articles')->insert($tanda);
        }

        $ids = DB::table('articles')
                    ->where('user_id', $dueno->id)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(function ($id) { return (int) $id; })
                    ->all();

        $this->assertCount($cantidad, $ids);

        /* Un descuento y un recargo por artículo: lo que hoy se leía con una consulta cada uno. */
        $descuentos = [];
        $recargos   = [];
        $pivots     = [];
        $monedas    = [];

        foreach ($ids as $i => $id) {

            $descuentos[] = ['article_id' => $id, 'percentage' => 5 + ($i % 3), 'created_at' => $ahora, 'updated_at' => $ahora];
            $recargos[]   = ['article_id' => $id, 'amount' => 10, 'luego_del_precio_final' => 0, 'created_at' => $ahora, 'updated_at' => $ahora];

            foreach ($listas as $lista) {
                $pivots[] = ['article_id' => $id, 'price_type_id' => $lista->id, 'percentage' => null, 'final_price' => 1];
            }

            if ($configuracion === 'dolares') {
                foreach ([self::ARS, self::USD] as $moneda_id) {
                    $monedas[] = [
                        'article_id'                => $id,
                        'price_type_id'             => $listas[0]->id,
                        'moneda_id'                 => $moneda_id,
                        'percentage'                => 40,
                        'final_price'               => 1,
                        'setear_precio_final'       => 0,
                        'cotizar_desde_otra_moneda' => $moneda_id === self::USD ? 1 : 0,
                        'created_at'                => $ahora,
                        'updated_at'                => $ahora,
                    ];
                }
            }
        }

        foreach (array_chunk($descuentos, 500) as $tanda) {
            DB::table('article_discounts')->insert($tanda);
        }

        foreach (array_chunk($recargos, 500) as $tanda) {
            DB::table('article_surchages')->insert($tanda);
        }

        foreach (array_chunk($pivots, 500) as $tanda) {
            DB::table('article_price_type')->insert($tanda);
        }

        foreach (array_chunk($monedas, 500) as $tanda) {
            DB::table('article_price_type_monedas')->insert($tanda);
        }

        return [
            'dueno' => $dueno,
            'ids'   => $ids,
        ];
    }
}
