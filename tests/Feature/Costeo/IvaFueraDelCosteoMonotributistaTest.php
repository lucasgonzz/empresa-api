<?php

namespace Tests\Feature\Costeo;

use App\Models\Article;
use App\Models\Iva;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión `iva-fuera-del-costeo-monotributista` (21/8/2026) — para un Monotributista, el IVA no
 * participa del cálculo en ningún punto.
 *
 * 🔴 LA REGLA, en palabras de Lucas: *"el monotributista no tiene que configurar nada de IVA (...)
 * él simplemente carga el costo que su proveedor le pasa (...) el IVA no tiene que cambiar nada del
 * precio en un monotributista"*.
 *
 * Hasta esta misión el sistema le hacía al MT un ida y vuelta: le SACABA el IVA al guardar el costo
 * (`articles.cost` es neto por convención) y se lo VOLVÍA A SUMAR al calcular el costo real
 * (`iva_va_al_costo()` daba true para MT). Las dos operaciones se cancelan, así que los precios
 * finales daban bien — pero el número interno se filtraba a cualquier pantalla que mostrara
 * `articles.cost` crudo: la columna "Costo base" del listado y la columna "Costo" del export a
 * Excel mostraban 826,45 sobre un artículo que la persona había cargado en 1000.
 *
 * Ahora el MT guarda lo que carga y el IVA sale del pipeline: ni al costo, ni a la venta.
 *
 * 🔴 EL CAMBIO ES NEUTRO EN PRECIOS, y eso es lo que estos tests fijan. Antes:
 * cost 826,45 -> costo_real 1000 -> final 1400. Ahora: cost 1000 -> costo_real 1000 -> final 1400.
 * Si algún día un cambio mueve `final_price` acá, movió la plata de todos los clientes
 * monotributistas.
 *
 * Los números son la especificación. 🔴 Está prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el código.
 *
 * @group costeo-precios
 */
class IvaFueraDelCosteoMonotributistaTest extends EmpresaTestCase
{
    const DELTA = 0.01;

    /** @var array */
    private $previos = [];

    /** @var \App\Models\Article */
    private $article;

    /** @var array Ids de sale_taxes que este test apaga y tiene que volver a prender. */
    private $sale_taxes_apagados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $owner = $this->owner();

        $this->previos = [
            'condicion_iva_precios'           => $owner->condicion_iva_precios,
            'usar_condicion_fiscal_en_costeo' => $owner->usar_condicion_fiscal_en_costeo,
        ];

        /*
         * La cuenta tiene que estar MIGRADA: con `usar_condicion_fiscal_en_costeo` apagado manda la
         * tilde histórica `aplicar_iva_al_costo` y la condición fiscal se ignora por completo. Es
         * justamente el interruptor con el que Lucas va a dejar afuera a los clientes que ya están
         * usando el sistema, así que estos tests miden la cuenta migrada, que es la que cambia.
         */
        $owner->condicion_iva_precios = User::CONDICION_MT;
        $owner->usar_condicion_fiscal_en_costeo = 1;
        $owner->save();

        $iva21 = Iva::where('percentage', '21')->first();
        $this->assertNotNull($iva21, 'La base de testing no tiene la alícuota 21 sembrada.');

        $this->article = Article::where('user_id', $owner->id)
                                    ->where('name', TestingFerreteriaSeeder::ARTICULO_CENTINELA)
                                    ->first();
        $this->assertNotNull($this->article, 'La base de testing no tiene el artículo centinela.');

        /*
         * Sin descuentos, recargos ni impuestos de venta: lo único que puede mover el número en
         * estos tests tiene que ser el IVA. El artículo del fixture trae un impuesto de venta de
         * ~3,63% que, si se deja, se mezcla con el efecto que se quiere medir.
         */
        DB::table('article_discounts')->where('article_id', $this->article->id)->delete();
        DB::table('article_surchages')->where('article_id', $this->article->id)->delete();
        DB::table('article_sale_tax')->where('article_id', $this->article->id)->delete();

        /*
         * Y el impuesto de venta de la CUENTA (IIBB 3,5% con apply_to_all), que se aplica por
         * división sobre el precio final y mete un +3,627% que se confunde con el efecto del IVA.
         * Se apaga acá y se restaura en tearDown: DatabaseTransactions lo revertiría solo, pero es
         * una fila global de la cuenta y el precedente del fallo del grupo 216 pide restaurarla a
         * mano igual.
         */
        $this->sale_taxes_apagados = DB::table('sale_taxes')
                                        ->where('user_id', $owner->id)
                                        ->where('activo', 1)
                                        ->pluck('id')
                                        ->toArray();

        if (count($this->sale_taxes_apagados)) {
            DB::table('sale_taxes')->whereIn('id', $this->sale_taxes_apagados)->update(['activo' => 0]);
        }

        /*
         * 🔴 Y hay que limpiar el cache ESTATICO de sale_taxes, si no apagarlos en la base no sirve
         * de nada. `ArticlePricesHelper::$sale_taxes_cache` sobrevive entre tests del mismo proceso
         * de PHPUnit, asi que si otro test ya lo lleno con el IIBB activo, este test lo sigue viendo
         * activo y los numeros salen con un +3,627% encima. Corriendo el archivo solo no pasa, y
         * dentro de la carpeta si: es el tipo de fallo que aparece y desaparece segun el orden.
         * Mismo criterio que CascadaDePreciosTest y MargenDeUnPrecioFijadoAManoTest.
         */
        ArticlePricesHelper::$sale_taxes_cache = [];

        $this->article->iva_id = $iva21->id;
        $this->article->aplicar_iva = 1;
        $this->article->percentage_gain = 40;
        $this->article->save();
        $this->article->load(['article_discounts', 'article_surchages', 'iva']);
    }

    protected function tearDown(): void
    {
        $owner = $this->owner();
        $owner->condicion_iva_precios = $this->previos['condicion_iva_precios'];
        $owner->usar_condicion_fiscal_en_costeo = $this->previos['usar_condicion_fiscal_en_costeo'];
        $owner->save();

        if (count($this->sale_taxes_apagados)) {
            DB::table('sale_taxes')->whereIn('id', $this->sale_taxes_apagados)->update(['activo' => 1]);
            ArticlePricesHelper::$sale_taxes_cache = [];
        }

        parent::tearDown();
    }

    /**
     * @return \App\Models\User
     */
    private function owner()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Guarda el artículo por el endpoint real del ABM, con el costo que la persona tipeó.
     *
     * @param  mixed $cost
     * @return void
     */
    private function guardar_costo($cost)
    {
        $payload = array_merge($this->article->getAttributes(), [
            'id'                 => $this->article->id,
            'cost'               => $cost,
            'aplicar_iva'        => 1,
            'percentage_gain'    => 40,
            /*
             * El formulario del Monotributista tiene UN solo campo y no declara nada de IVA: para él
             * la distinción bruto/neto no existe. La clave viaja igual, en false, porque el
             * formulario la manda siempre (si no viajara, un guardado que no toca el costo podría
             * descomponerlo).
             */
            'cost_incluye_iva'   => 0,
            'price_types'        => [],
            'price_type_monedas' => [],
            'tags'               => [],
        ]);

        $this->putJson('api/article/'.$this->article->id, $payload)->assertStatus(200);

        $this->article->refresh();
    }

    /**
     * Test 1 — lo que el Monotributista carga es lo que se guarda. Sin back-out.
     *
     * Antes de esta misión se guardaba 826,45 y la columna "Costo base" del listado mostraba ese
     * número, que no figura en ninguna factura del cliente.
     *
     * @group costeo-precios
     * @test
     */
    public function el_costo_que_carga_el_monotributista_se_guarda_tal_cual()
    {
        $this->guardar_costo(1000);

        $this->assertEqualsWithDelta(1000, (float) $this->article->cost, self::DELTA,
            'el MT carga 1000 y se guarda 1000: no hay bruto ni neto, hay un solo costo');
    }

    /**
     * Test 2 — el IVA no entra al costo real.
     *
     * Sin descuentos ni recargos, el costo real ES el costo cargado. Antes daba lo mismo (1000) pero
     * por otro camino: 826,45 x 1,21.
     *
     * @group costeo-precios
     * @test
     */
    public function el_iva_no_entra_al_costo_real()
    {
        $this->guardar_costo(1000);

        $this->assertEqualsWithDelta(1000, (float) $this->article->costo_real, self::DELTA,
            'sin descuentos ni recargos, el costo real de un MT es el costo que cargó');
    }

    /**
     * Test 3 — 🔴 el IVA tampoco se suma a la venta, y este es el test que cuida la plata.
     *
     * Es el que se rompe si alguien "arregla" lo anterior poniendo `iva_va_al_costo()` en false sin
     * tocar `aplicar_iva()`: ahí el IVA no desaparece, se MUDA al precio de venta, y el
     * monotributista termina cobrando un IVA que no cobra. Con margen 40% sobre 1000, el precio es
     * 1400; si el IVA se colara, daría 1694.
     *
     * @group costeo-precios
     * @test
     */
    public function el_iva_no_se_suma_al_precio_de_venta()
    {
        $this->guardar_costo(1000);

        $this->assertEqualsWithDelta(1400, (float) $this->article->final_price, self::DELTA,
            'margen 40% sobre 1000 da 1400; con IVA colado daría 1694');
    }

    /**
     * Test 4 — el cambio es NEUTRO en precios, que es la razón por la que se puede hacer sin migrar
     * datos de los clientes que ya están usando el sistema.
     *
     * Antes: cost 826,45 -> costo_real 1000 -> final 1400.
     * Ahora: cost 1000    -> costo_real 1000 -> final 1400.
     *
     * Lo que cambia es qué número está guardado en `articles.cost`, no la plata.
     *
     * @group costeo-precios
     * @test
     */
    public function el_precio_final_no_se_movio_respecto_del_comportamiento_anterior()
    {
        $this->guardar_costo(1000);

        $this->assertEqualsWithDelta(1400, (float) $this->article->final_price, self::DELTA,
            'el precio final es el mismo que daba el ida y vuelta anterior');

        $this->assertEqualsWithDelta(1000, (float) $this->article->costo_real, self::DELTA,
            'y el costo real también');
    }

    /**
     * Test 5 — al Monotributista se le ignora la declaracion, venga de donde venga.
     *
     * 🔴 El resolvedor no le cree a `cost_incluye_iva` para un MT migrado, a proposito. El
     * formulario nuevo manda false, pero por la API o por una pantalla vieja puede llegar un true, y
     * creerle le hundiria el costo un 21% en silencio. La decision es de la condicion fiscal, no del
     * que llama.
     *
     * @group costeo-precios
     * @test
     */
    public function al_monotributista_no_se_le_cree_una_declaracion_de_bruto()
    {
        $payload = array_merge($this->article->getAttributes(), [
            'id'                 => $this->article->id,
            'cost'               => 1000,
            'aplicar_iva'        => 1,
            'percentage_gain'    => 40,
            // Alguien manda que el costo viene con IVA. Para un MT no cambia nada.
            'cost_incluye_iva'   => 1,
            'price_types'        => [],
            'price_type_monedas' => [],
            'tags'               => [],
        ]);

        $this->putJson('api/article/'.$this->article->id, $payload)->assertStatus(200);

        $this->article->refresh();

        $this->assertEqualsWithDelta(1000, (float) $this->article->cost, self::DELTA,
            'el costo de un MT no se descompone ni aunque el request diga que viene con IVA');
    }

    /**
     * Test 6 — el Responsable Inscripto NO cambia: a él el IVA se le suma en la venta.
     *
     * Es el contrapeso: sacar el IVA del pipeline es una regla sobre el Monotributista, no sobre
     * todos. Con costo neto 1000 y margen 40%, el RRII vende a 1400 + 21% = 1694.
     *
     * @group costeo-precios
     * @test
     */
    public function el_responsable_inscripto_sigue_sumando_el_iva_en_la_venta()
    {
        $owner = $this->owner();
        $owner->condicion_iva_precios = 'RRII';
        $owner->save();

        $this->guardar_costo(1000);

        $this->assertEqualsWithDelta(1694, (float) $this->article->final_price, self::DELTA,
            'un RRII sí le suma el IVA al precio de venta: 1000 + 40% + 21%');
    }
}
