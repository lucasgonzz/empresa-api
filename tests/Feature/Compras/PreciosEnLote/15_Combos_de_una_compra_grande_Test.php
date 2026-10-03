<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Jobs\RecalcularCombosCalculados;
use App\Models\Combo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Combos calculados y una compra grande (misión compras-precios-en-lote, 2/10/2026).
 *
 * El gancho de los combos calculados vive en ArticleHelper::setFinalPrice() y NO corre en modo
 * lote, así que con el recálculo diferido la compra los recalcula ella misma después del motor
 * (NewProviderOrderHelper::recalcular_precios_pendientes()). Lo encontraron los chequeos del 2/10:
 * si esa llamada se hace UNA vez con todos los ids de la compra, ComboCalculadoHelper ve más combos
 * que MAXIMO_EN_LINEA y ENCOLA RecalcularCombosCalculados adentro de la transacción de la compra
 * (sin afterCommit: con redis el job puede correr antes del commit y recalcular con los precios
 * viejos). El camino de antes llamaba artículo por artículo, y con un combo por artículo nunca
 * pasaba el tope: los recalculaba en línea.
 *
 * Este test arma ese caso (más artículos que el tope, cada uno en su propio combo), corre la misma
 * compra por los dos caminos sobre la misma base y exige: ningún job encolado, y los combos
 * exactamente iguales en los dos caminos. Mata la versión de "una llamada con todos los ids".
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class Combos_de_una_compra_grande_Test extends ComprasPreciosEnLoteTestCase
{
    /** Artículos de la compra, cada uno en su propio combo calculado: uno más que el tope en línea. */
    const ARTICULOS = 26;

    /** Instante congelado de las dos corridas, para comparar updated_at por valor. */
    const AHORA_DE_LAS_CORRIDAS = '2030-01-15 10:00:00';

    /**
     * @group compras
     * @test
     */
    public function una_compra_que_toca_mas_combos_que_el_tope_los_recalcula_en_linea_como_el_camino_de_antes()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $this->assertGreaterThan(
            ComboCalculadoHelper::MAXIMO_EN_LINEA,
            self::ARTICULOS,
            'Precondición: la compra tiene que tocar más combos que el tope en línea, que es el caso que se prueba.'
        );

        /* Cada artículo en su propio combo calculado: con el gancho por artículo, una llamada = un combo. */
        $articulos = [];
        $combo_ids = [];

        for ($i = 0; $i < self::ARTICULOS; $i++) {

            $article = $this->crear_articulo(['cost' => 1000 + $i]);

            $combo = Combo::create([
                'num'                      => 973000 + $i,
                'name'                     => 'zz Combo de una compra grande ' . $i . ' ' . uniqid(),
                'user_id'                  => $article->user_id,
                'calcular_desde_articulos' => 1,
            ]);

            $combo->articles()->attach($article->id, ['amount' => 2]);

            ComboCalculadoHelper::guardar($combo);

            $articulos[] = $article;
            $combo_ids[] = (int) $combo->id;
        }

        $renglones = [];

        foreach ($articulos as $i => $article) {
            $renglones[] = $this->renglon($article, 2000 + ($i * 10), 5);
        }

        $payload = $this->payload_compra(['articles' => $renglones]);

        $antes = $this->foto_de_combos($combo_ids);

        /*
         * El job de combos se intercepta: si alguno de los dos caminos lo encolara, queda registrado
         * y el assert de abajo lo denuncia. Con el job interceptado, lo que se recalcula en línea es
         * lo único que llega a la base, que es justo lo que se compara.
         */
        Bus::fake([RecalcularCombosCalculados::class]);

        Carbon::setTestNow(self::AHORA_DE_LAS_CORRIDAS);

        $hoy   = $this->correr_y_fotografiar(true, $payload, $combo_ids);
        $motor = $this->correr_y_fotografiar(false, $payload, $combo_ids);

        /*
         * Con assertCount sobre Bus::dispatched() y no con Bus::assertNotDispatched(): el segundo
         * parámetro de ese último es un callback de filtro, no un mensaje, y con un job encolado
         * intentaba llamar al texto como función (un error en vez de una falla que diga qué pasó).
         */
        $this->assertCount(
            0,
            Bus::dispatched(RecalcularCombosCalculados::class),
            'Ningún camino tiene que encolar el recálculo de combos: con un combo por artículo, el gancho por artículo los recalcula en línea.'
        );

        $this->assertNotEquals($antes, $hoy, 'Precondición: la compra movió el costo y el precio de los combos.');

        $this->assertSame(
            $hoy,
            $motor,
            'Con el recálculo diferido los combos tienen que quedar exactamente como los deja el camino de antes.'
        );
    }

    /**
     * Una corrida de la compra en un savepoint: el interruptor del camino, el alta por el endpoint
     * real, la foto de los combos y el rollback. El estado estático vuelve a su default pase lo que
     * pase.
     *
     * @param  bool  $por_articulo true = camino de antes; false = recálculo diferido con el motor.
     * @param  array $payload
     * @param  int[] $combo_ids
     * @return array
     */
    protected function correr_y_fotografiar($por_articulo, array $payload, array $combo_ids)
    {
        $this->limpiar_estado_del_proceso();

        NewProviderOrderHelper::recalcular_por_articulo($por_articulo);

        DB::beginTransaction();

        try {

            $this->alta($payload);

            return $this->foto_de_combos($combo_ids);

        } finally {

            DB::rollBack();

            $this->limpiar_estado_del_proceso();
        }
    }

    /**
     * Lo que el recálculo de combos escribe: las columnas de `combos` (menos el id) y sus precios
     * por lista (`combo_price_type`, sin el id de fila), como texto, en orden estable.
     *
     * @param  int[] $combo_ids
     * @return array
     */
    protected function foto_de_combos(array $combo_ids)
    {
        $foto = [
            'combos' => [],
            'listas' => [],
        ];

        foreach (DB::table('combos')->whereIn('id', $combo_ids)->orderBy('id')->get() as $fila) {

            $fila = (array) $fila;
            $id   = (int) $fila['id'];

            unset($fila['id']);

            $foto['combos'][$id] = array_map(function ($valor) {
                return is_null($valor) ? null : (string) $valor;
            }, $fila);
        }

        $listas = DB::table('combo_price_type')
                        ->whereIn('combo_id', $combo_ids)
                        ->orderBy('combo_id')
                        ->orderBy('price_type_id')
                        ->get();

        foreach ($listas as $fila) {

            $fila = (array) $fila;

            unset($fila['id']);

            $foto['listas'][] = array_map(function ($valor) {
                return is_null($valor) ? null : (string) $valor;
            }, $fila);
        }

        return $foto;
    }
}
