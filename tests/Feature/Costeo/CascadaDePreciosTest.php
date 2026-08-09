<?php

namespace Tests\Feature\Costeo;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\ArticlePriceTypeMoneda;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\SaleTax;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Grupo 379 · Prompt 04 — suite `costeo-precios`: `ArticleHelper::setFinalPrice()` de punta a
 * punta, por sus cuatro caminos y cruzado por las tres configuraciones fiscales de la cuenta.
 *
 * Los cuatro caminos (línea ~277 de `ArticleHelper::setFinalPrice()`):
 *   1. Costo + margen, sin listas de precio.
 *   2. Listas de precio (`aplicar_precios_segun_listas_de_precios()`).
 *   3. Listas por moneda, extensión `ventas_en_dolares` (`ArticlePriceTypeMonedaHelper`).
 *   4. Listas por categoría, extensión `lista_de_precios_por_categoria`.
 *
 * Las tres configuraciones fiscales (documentadas en `ArticlePricesHelper::iva_va_al_costo()`):
 *   - RRII migrada (`usar_condicion_fiscal_en_costeo=1`, `condicion_iva_precios='RRII'`): el IVA
 *     se suma AL VENDER.
 *   - Monotributista migrada (`condicion_iva_precios='MT'`): el IVA ya viene DENTRO del costo real.
 *   - Cuenta legacy sin migrar (`usar_condicion_fiscal_en_costeo=0`, `aplicar_iva_al_costo=1`):
 *     mismo resultado que MT, pero por OTRO motivo (la tilde vieja, no la condición fiscal real).
 *     Confundir estos dos motivos costó un día entero el 5/8/2026 (ver comentario de
 *     `ArticlePricesHelper.php` ~línea 277).
 *
 * Usa `DatabaseTransactions` sobre la base ya sembrada (`TestingFerreteriaSeeder`) — no
 * `migrate:fresh` ni seeder externo — y salta con `markTestSkipped()` si falta el usuario 500 o el
 * fixture, siguiendo el mismo patrón que `tests/Feature/Sales/5_No_se_puede_editar_venta_Test.php`
 * (grupo 317) y `tests/Feature/Preferencias/1_Modo_oscuro_Test.php` (grupo 304): extiende
 * `Tests\TestCase` directo, no `Tests\EmpresaTestCase` (que hace `fail()`, no `markTestSkipped()`,
 * si falta el fixture).
 *
 * @group costeo-precios
 */
class CascadaDePreciosTest extends TestCase
{
    use DatabaseTransactions;

    const DELTA = 0.5;

    const SLUG_VENTAS_DOLARES = 'ventas_en_dolares';
    const SLUG_LISTA_CATEGORIA = 'lista_de_precios_por_categoria';

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\Article */
    protected $pinza;

    /** @var \App\Models\Provider */
    protected $provider;

    /** @var \App\Models\PriceType */
    protected $distribuidor;

    /** @var \App\Models\PriceType */
    protected $mayorista;

    /** @var \App\Models\Category */
    protected $category;

    /** @var array<string,mixed> */
    protected $condicion_original;

    /** @var array<string,mixed> */
    protected $pinza_snapshot;

    protected $provider_flag_original;

    /** @var \App\Models\SaleTax|null */
    protected $iibb;

    protected $iibb_activo_original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);
        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($this->user, 'web');

        $this->pinza = Article::where('name', 'Pinza')->where('user_id', 500)->first();
        if (is_null($this->pinza)) {
            $this->markTestSkipped('La base de testing no tiene el articulo "Pinza" sembrado (TestingFerreteriaSeeder).');
        }

        $this->provider = Provider::where('name', TestingFerreteriaSeeder::PROVIDER_BSAS)->where('user_id', 500)->first();
        if (is_null($this->provider)) {
            $this->markTestSkipped('La base de testing no tiene el proveedor "Buenos Aires" sembrado.');
        }

        $this->distribuidor = PriceType::where('user_id', 500)->where('name', 'Distribuidor')->first();
        $this->mayorista = PriceType::where('user_id', 500)->where('name', 'Mayorista')->first();
        if (is_null($this->distribuidor) || is_null($this->mayorista)) {
            $this->markTestSkipped('La base de testing no tiene los price_types "Distribuidor"/"Mayorista" sembrados.');
        }

        // Snapshot de todo lo que esta suite muta, para restaurar en tearDown(). DatabaseTransactions
        // debería revertir todo esto solo, pero esta suite no pasa por Tests\EmpresaTestCase (que
        // verifica InnoDB) y varias filas tocadas son globales (PriceType, ExtencionEmpresa,
        // SaleTax), así que se restaura a mano igual -- mismo criterio que
        // tests/Concerns/EscenariosDePlata.php y el precedente del fallo del grupo 216.
        $this->condicion_original = [
            'usar_condicion_fiscal_en_costeo' => $this->user->usar_condicion_fiscal_en_costeo,
            'condicion_iva_precios'           => $this->user->condicion_iva_precios,
            'aplicar_iva_al_costo'            => $this->user->aplicar_iva_al_costo,
            'listas_de_precio'                => $this->user->listas_de_precio,
        ];

        $this->pinza_snapshot = [
            'cost'            => $this->pinza->cost,
            'costo_real'      => $this->pinza->costo_real,
            'category_id'     => $this->pinza->category_id,
            'sub_category_id' => $this->pinza->sub_category_id,
            'provider_id'     => $this->pinza->provider_id,
            'percentage_gain' => $this->pinza->percentage_gain,
            'price'           => $this->pinza->price,
            'final_price'     => $this->pinza->final_price,
            'base_margen'     => $this->pinza->base_margen,
        ];

        $this->provider_flag_original = $this->provider->price_from_cost_mas_iva;

        // Margen unico (40%) para que los cuatro caminos sean directamente comparables en la tabla
        // de doce casos: camino 1 lo toma de articles.percentage_gain, caminos 2/3/4 de su propio
        // pivote (price_type / price_type_moneda / category_price_type), pisado mas abajo.
        $this->pinza->percentage_gain = 40;
        $this->pinza->save();

        $this->pinza->price_types()->syncWithoutDetaching([
            $this->distribuidor->id => ['percentage' => 40, 'setear_precio_final' => 0],
        ]);

        $this->category = new Category();
        $this->category->user_id = 500;
        $this->category->name = 'zz-temp-categoria-379-04';
        $this->category->save();
        $this->category->price_types()->attach($this->mayorista->id, ['percentage' => 40]);

        ArticlePriceTypeMoneda::where('article_id', $this->pinza->id)->delete();
        ArticlePriceTypeMoneda::create([
            'article_id'              => $this->pinza->id,
            'price_type_id'           => $this->distribuidor->id,
            'moneda_id'               => 1,
            'percentage'              => 40,
            'final_price'             => null,
            'setear_precio_final'     => 0,
            'cotizar_desde_otra_moneda' => 0,
        ]);

        // El impuesto IIBB del fixture (3.5%, apply_to_all=1) se aplica siempre en los caminos 1, 2
        // y 4 (via ArticlePricesHelper::aplicar_sale_taxes()), pero el camino 3
        // (ArticlePriceTypeMonedaHelper) todavia no lo aplica -- gap real, fuera de alcance de los
        // prompts 01/02/03, ya registrado en el hallazgo
        // 20260808-listas-dolares-no-convergen-con-pesos.json. Sin desactivarlo aca, la
        // convergencia entre caminos que prueba esta suite fallaria por ese motivo AJENO al que
        // prueban los prompts de este grupo, no por una regresion real de IVA.
        $this->iibb = SaleTax::where('user_id', 500)->where('name', TestingFerreteriaSeeder::IMPUESTO_IIBB)->first();
        if (!is_null($this->iibb)) {
            $this->iibb_activo_original = $this->iibb->activo;
            $this->iibb->activo = 0;
            $this->iibb->save();
            ArticlePricesHelper::$sale_taxes_cache = [];
        }
    }

    protected function tearDown(): void
    {
        // PHPUnit corre tearDown() igual despues de un markTestSkipped() en setUp() (grupo 379,
        // hallazgo del checker del prompt 04): si el fixture no estaba (usuario 500, articulo,
        // proveedor o price_types faltantes), $this->pinza/$this->provider siguen en null y el resto
        // de este metodo explotaria con un Error, no con el skip limpio que se busca.
        if (is_null($this->pinza) || is_null($this->user) || is_null($this->provider)) {
            parent::tearDown();
            return;
        }

        if (!is_null($this->iibb)) {
            $this->iibb->activo = $this->iibb_activo_original;
            $this->iibb->save();
            ArticlePricesHelper::$sale_taxes_cache = [];
        }

        ArticlePriceTypeMoneda::where('article_id', $this->pinza->id)->delete();

        $this->pinza->price_types()->detach([$this->distribuidor->id, $this->mayorista->id]);

        $this->category->delete();

        $this->desactivar_extension(self::SLUG_VENTAS_DOLARES);
        $this->desactivar_extension(self::SLUG_LISTA_CATEGORIA);

        $this->provider->price_from_cost_mas_iva = $this->provider_flag_original;
        $this->provider->save();

        $this->pinza->refresh();
        $this->pinza->cost = $this->pinza_snapshot['cost'];
        $this->pinza->costo_real = $this->pinza_snapshot['costo_real'];
        $this->pinza->category_id = $this->pinza_snapshot['category_id'];
        $this->pinza->sub_category_id = $this->pinza_snapshot['sub_category_id'];
        $this->pinza->provider_id = $this->pinza_snapshot['provider_id'];
        $this->pinza->percentage_gain = $this->pinza_snapshot['percentage_gain'];
        $this->pinza->price = $this->pinza_snapshot['price'];
        $this->pinza->final_price = $this->pinza_snapshot['final_price'];
        $this->pinza->base_margen = $this->pinza_snapshot['base_margen'];
        $this->pinza->timestamps = false;
        $this->pinza->save();

        $this->user->usar_condicion_fiscal_en_costeo = $this->condicion_original['usar_condicion_fiscal_en_costeo'];
        $this->user->condicion_iva_precios = $this->condicion_original['condicion_iva_precios'];
        $this->user->aplicar_iva_al_costo = $this->condicion_original['aplicar_iva_al_costo'];
        $this->user->listas_de_precio = $this->condicion_original['listas_de_precio'];
        $this->user->save();

        parent::tearDown();
    }

    /**
     * Activa/desactiva una extension y fuerza el reload de la relacion `extencions` en memoria.
     *
     * Bug real encontrado escribiendo esta suite (8/8/2026): `UserHelper::hasExtencion()` lee
     * `$user->extencions` como propiedad magica (cacheada). Si algo ya la disparo ANTES en el mismo
     * test (por ejemplo, el dispatch de un camino anterior de `setFinalPrice()`, que llama a
     * `hasExtencion()` para decidir la rama), `$user->extencions` queda cacheada en memoria -- y
     * `syncWithoutDetaching()`/`detach()` escriben la base pero NO invalidan esa cache. El resultado
     * es que activar/desactivar una extension a mitad de un test podia no verse hasta el PROXIMO
     * test (objeto `$user` nuevo). `load('extencions')` fuerza la relectura.
     */
    protected function activar_extension($slug)
    {
        $extension = ExtencionEmpresa::where('slug', $slug)->first();
        if (is_null($extension)) {
            $extension = new ExtencionEmpresa();
            $extension->name = $slug;
            $extension->slug = $slug;
            $extension->save();
        }
        $this->user->extencions()->syncWithoutDetaching([$extension->id]);
        $this->user->load('extencions');
    }

    protected function desactivar_extension($slug)
    {
        $extension = ExtencionEmpresa::where('slug', $slug)->first();
        if (!is_null($extension)) {
            $this->user->extencions()->detach($extension->id);
        }
        $this->user->load('extencions');
    }

    /**
     * Fija las TRES columnas relevantes en cada caso, aunque alguna quede sin usar segun la
     * condicion (`condicion_iva_precios` no participa cuando `usar_condicion_fiscal_en_costeo=0`,
     * por ejemplo). Bug real encontrado por el checker (opus) del prompt 04: el caso 'legacy' no
     * fijaba `condicion_iva_precios`, y como los doce casos corren en el orden RRII/MT/legacy por
     * cada camino, 'legacy' heredaba el 'MT' que habia dejado la fila anterior -- asi que las
     * cuatro filas "legacy" de la tabla en realidad NUNCA ejercian
     * `ArticlePricesHelper::iva_va_al_costo()` por la rama vieja (`!usar_condicion_fiscal_en_costeo`
     * -> `aplicar_iva_al_costo`), sino que colaban por `es_monotributista_para_costeo()` con
     * `condicion_iva_precios='MT'` heredado, dando el mismo numero final pero SIN probar la
     * distincion "mismo resultado, otro motivo" que es justamente lo que costo un dia entero el
     * 5/8/2026 (ver comentario de ArticlePricesHelper.php ~linea 277). Fijar las tres columnas en
     * los tres casos evita que este bug de la suite (o uno parecido) vuelva a colarse.
     */
    protected function configurar_condicion_fiscal($condicion)
    {
        switch ($condicion) {
            case 'RRII':
                $this->user->usar_condicion_fiscal_en_costeo = 1;
                $this->user->condicion_iva_precios = 'RRII';
                $this->user->aplicar_iva_al_costo = 1;
                break;
            case 'MT':
                $this->user->usar_condicion_fiscal_en_costeo = 1;
                $this->user->condicion_iva_precios = 'MT';
                $this->user->aplicar_iva_al_costo = 1;
                break;
            case 'legacy':
                $this->user->usar_condicion_fiscal_en_costeo = 0;
                $this->user->condicion_iva_precios = 'RRII';
                $this->user->aplicar_iva_al_costo = 1;
                break;
            default:
                $this->fail('condicion fiscal desconocida: '.$condicion);
        }
        $this->user->save();
        $this->user = $this->user->fresh();
    }

    protected function configurar_camino($camino)
    {
        switch ($camino) {
            case 1:
                $this->user->listas_de_precio = 0;
                $this->desactivar_extension(self::SLUG_VENTAS_DOLARES);
                $this->desactivar_extension(self::SLUG_LISTA_CATEGORIA);
                $this->pinza->category_id = null;
                $this->pinza->save();
                break;
            case 2:
                $this->user->listas_de_precio = 1;
                $this->desactivar_extension(self::SLUG_VENTAS_DOLARES);
                break;
            case 3:
                $this->user->listas_de_precio = 1;
                $this->activar_extension(self::SLUG_VENTAS_DOLARES);
                break;
            case 4:
                $this->user->listas_de_precio = 0;
                $this->desactivar_extension(self::SLUG_VENTAS_DOLARES);
                $this->activar_extension(self::SLUG_LISTA_CATEGORIA);
                $this->pinza->category_id = $this->category->id;
                $this->pinza->save();
                break;
            default:
                $this->fail('camino desconocido: '.$camino);
        }
        $this->user->save();
        // Mismo motivo que en activar_extension()/desactivar_extension(): $this->pinza puede tener
        // la relacion `category` (o `price_types`) cacheada de un camino anterior en el mismo test
        // (aplicar_category_percentage_gain() la lee en TODOS los caminos, no solo en el 4). Un
        // fresh() completo evita arrastrar cualquier relacion vieja en memoria.
        $this->user = $this->user->fresh();
        $this->pinza = $this->pinza->fresh();
    }

    protected function precio_resultante($camino)
    {
        switch ($camino) {
            case 1:
                $this->pinza->refresh();
                return is_null($this->pinza->final_price) ? null : (float) $this->pinza->final_price;
            case 2:
                $this->pinza->load('price_types');
                $pivot = $this->pinza->price_types->where('id', $this->distribuidor->id)->first();
                return is_null($pivot) ? null : (float) $pivot->pivot->final_price;
            case 3:
                $entry = ArticlePriceTypeMoneda::where('article_id', $this->pinza->id)->where('moneda_id', 1)->first();
                return is_null($entry) ? null : (float) $entry->final_price;
            case 4:
                $this->pinza->load('price_types');
                $pivot = $this->pinza->price_types->where('id', $this->mayorista->id)->first();
                return is_null($pivot) ? null : (float) $pivot->pivot->final_price;
        }
        return null;
    }

    /**
     * El test que mas importa de la suite: recorre los cuatro caminos por las tres
     * configuraciones fiscales, mismo articulo (Pinza, alicuota 21%) y mismo costo (1000), y afirma
     * el precio final de cada una de las doce combinaciones.
     *
     * DE DONDE SALE EL NUMERO (a mano, no corriendo el codigo -- ver la regla del prompt: "un test
     * que se escribe corriendo el codigo y pegando el resultado no prueba nada, consagra el bug si
     * lo habia"):
     *
     * Costo 1000, margen unico 40% (unificado en los cuatro caminos para que sean comparables),
     * alicuota 21%, IIBB del fixture desactivado en setUp() (para que el numero no dependa de un
     * impuesto que el camino 3 ni siquiera aplica hoy -- ver el comentario de setUp() y el hallazgo
     * 20260808-listas-dolares-no-convergen-con-pesos.json).
     *
     * Con un unico margen y sin sale_taxes, "costo -> margen -> IVA" (RRII) y "costo -> IVA (Capa 1,
     * ya adentro del costo real) -> margen" (MT/legacy) dan el MISMO numero, porque las tres
     * operaciones son multiplicaciones y el orden no importa:
     *
     *   1000 x 1.40 x 1.21 = 1000 x 1.21 x 1.40 = 1694
     *
     * Por eso los DOCE casos dan 1694. No es un test flojo ni casualidad: es la propiedad que
     * demuestra que los cuatro caminos -con sus cuatro implementaciones de codigo distintas- llegan
     * al mismo resultado economico. Si alguno rompe su logica de IVA (la duplica, la omite, o la
     * aplica en el orden equivocado respecto del margen), ESE numero puntual se despega de 1694
     * mientras los otros once quedan quietos: la fila que se pone roja dice exactamente que camino y
     * que configuracion fiscal rompio.
     *
     * @test
     */
    public function doce_casos_de_la_cascada_de_precios()
    {
        $esperado = 1694.0;

        // costo_real (Capa 1, ArticleHelper::aplicar_descuentos_e_iva()) es lo UNICO que distingue
        // las tres configuraciones fiscales -- el precio final da 1694 en las doce por la
        // conmutatividad explicada arriba, asi que sin esta asercion el eje "configuracion fiscal"
        // no discrimina nada (un bug que ignorara iva_va_al_costo() por completo y aplicara SIEMPRE
        // el IVA al vender dejaria las doce celdas en 1694 igual). RRII: costo neto (1000). MT y
        // legacy: costo con el IVA ya adentro (1000 x 1.21 = 1210), aunque por motivos DISTINTOS
        // (condicion_iva_precios='MT' vs aplicar_iva_al_costo=1 con usar_condicion_fiscal_en_costeo
        // apagado) -- exactamente la distincion que costo un dia el 5/8/2026 (ver comentario de
        // ArticlePricesHelper.php ~linea 277).
        $costo_real_esperado = [
            'RRII'   => 1000.0,
            'MT'     => 1210.0,
            'legacy' => 1210.0,
        ];

        // El costo del articulo tiene que estar fijado a proposito (no heredado del seeder): si el
        // fixture cambiara, esta tabla se pondria roja con un mensaje que apunta al IVA en vez de al
        // costo real.
        $this->pinza->cost = 1000;
        $this->pinza->save();

        $casos = [
            [1, 'RRII'], [1, 'MT'], [1, 'legacy'],
            [2, 'RRII'], [2, 'MT'], [2, 'legacy'],
            [3, 'RRII'], [3, 'MT'], [3, 'legacy'],
            [4, 'RRII'], [4, 'MT'], [4, 'legacy'],
        ];

        foreach ($casos as $caso) {
            list($camino, $condicion) = $caso;

            $this->configurar_condicion_fiscal($condicion);
            $this->configurar_camino($camino);

            ArticleHelper::setFinalPrice($this->pinza, null, $this->user, null, true);

            $this->pinza = $this->pinza->fresh();
            $this->assertEqualsWithDelta(
                $costo_real_esperado[$condicion],
                (float) $this->pinza->costo_real,
                self::DELTA,
                "camino $camino / $condicion: costo_real esperado ".$costo_real_esperado[$condicion].
                ", dio ".$this->pinza->costo_real." -- esto es lo que prueba que la condicion fiscal ".
                "se aplico de verdad, no solo que el precio final dio el numero esperado"
            );

            $resultado = $this->precio_resultante($camino);

            $this->assertNotNull(
                $resultado,
                "camino $camino / $condicion: no se encontro el pivote/precio esperado (revisar el dispatch de setFinalPrice() o el fixture de este test)"
            );
            $this->assertEqualsWithDelta(
                $esperado,
                $resultado,
                self::DELTA,
                "camino $camino / $condicion: esperaba $esperado (1000 x 1.40 x 1.21, ver docblock de este test), dio $resultado"
            );
        }
    }

    /**
     * Bug del prompt 01 (doble IVA): proveedor con `price_from_cost_mas_iva`, cuenta RRII. El
     * precio final tiene que ser costo x (1 + alicuota) UNA sola vez: 1000 x 1.21 = 1210, no
     * 1000 x 1.21^2 = 1464,10.
     *
     * Este test tiene que ponerse ROJO si alguien saca la condicion `$iva_ya_aplicado` del bloque
     * comun de IVA en ArticleHelper::setFinalPrice() (~linea 500).
     *
     * @test
     */
    public function doble_iva_no_se_duplica_prompt_01()
    {
        $this->configurar_condicion_fiscal('RRII');

        $this->provider->price_from_cost_mas_iva = 1;
        $this->provider->save();

        $this->pinza->provider_id = $this->provider->id;
        $this->pinza->cost = 1000;
        $this->pinza->save();

        $res = ArticleHelper::setFinalPrice($this->pinza, null, $this->user, null, false);

        $this->assertEqualsWithDelta(
            1210,
            $res['final_price'],
            self::DELTA,
            'proveedor price_from_cost_mas_iva, cuenta RRII: el precio final tiene que ser '.
            'costo x (1+alicuota) UNA sola vez (1000 x 1.21 = 1210), no costo x (1+alicuota)^2 '.
            '(1000 x 1.21^2 = 1464,10). Si esto da 1464,10 (o cerca), alguien saco la condicion '.
            '$iva_ya_aplicado del bloque comun de IVA en ArticleHelper::setFinalPrice().'
        );
    }

    /**
     * Bug del prompt 01 (listas que no se recalculan): mismo proveedor `price_from_cost_mas_iva`,
     * cuenta RRII con listas de precio activas. Despues de `setFinalPrice()`, el pivote
     * `article_price_type` de la lista tiene que tener `final_price` NO nulo y coherente con su
     * margen (antes del fix quedaba en null o con el valor de la ultima vez que el articulo paso
     * por el modo normal).
     *
     * @test
     */
    public function listas_se_recalculan_con_price_from_cost_mas_iva_prompt_01()
    {
        $this->configurar_condicion_fiscal('RRII');

        $this->provider->price_from_cost_mas_iva = 1;
        $this->provider->save();

        $this->pinza->provider_id = $this->provider->id;
        $this->pinza->cost = 1000;
        $this->pinza->save();

        $this->user->listas_de_precio = 1;
        $this->user->save();

        ArticleHelper::setFinalPrice($this->pinza, null, $this->user, null, true);

        $this->pinza->load('price_types');
        $pivot = $this->pinza->price_types->where('id', $this->distribuidor->id)->first();

        $this->assertNotNull($pivot, 'la lista Distribuidor tiene que seguir asociada al articulo');
        $this->assertNotNull(
            $pivot->pivot->final_price,
            'BUG (prompt 01): el pivote de la lista quedo en null para un articulo de proveedor '.
            'price_from_cost_mas_iva -- antes del fix esta rama nunca llamaba a la cascada de listas.'
        );

        // Margen 40% (Distribuidor) + IVA 21%: 1000 x 1.40 x 1.21 = 1694.
        $this->assertEqualsWithDelta(
            1694,
            (float) $pivot->pivot->final_price,
            self::DELTA,
            'el final_price de la lista tiene que ser coherente con su margen sobre el costo de este proveedor'
        );
    }

    /**
     * Bug del prompt 02: el mismo articulo, la misma lista (margen 0%, para aislar el problema de
     * las bases del calculo, no del margen), calculada por el camino 2 (listas, moneda implicita
     * ARS) y por el camino 3 (listas por moneda, extension `ventas_en_dolares`, misma moneda ARS)
     * tienen que dar el MISMO numero. Es la aserción que hubiera atrapado el bug de
     * `ArticlePriceTypeMonedaHelper` (IVA nunca aplicado) el dia que se introdujo.
     *
     * @test
     */
    public function convergencia_camino_2_y_3_con_margen_cero_prompt_02()
    {
        $this->configurar_condicion_fiscal('RRII');

        // Camino 2: listas de precio, sin la extension de dolares. Margen 0% a proposito.
        $this->user->listas_de_precio = 1;
        $this->user->save();
        $this->desactivar_extension(self::SLUG_VENTAS_DOLARES);

        $this->pinza->price_types()->syncWithoutDetaching([
            $this->distribuidor->id => ['percentage' => 0, 'setear_precio_final' => 0],
        ]);

        $this->pinza->refresh();
        ArticleHelper::setFinalPrice($this->pinza, null, $this->user, null, true);
        $this->pinza->load('price_types');
        $precio_camino_2 = (float) $this->pinza->price_types->where('id', $this->distribuidor->id)->first()->pivot->final_price;

        // Camino 3: listas de precio + extension de dolares, moneda ARS, mismo margen 0%.
        $this->activar_extension(self::SLUG_VENTAS_DOLARES);

        ArticlePriceTypeMoneda::where('article_id', $this->pinza->id)->delete();
        ArticlePriceTypeMoneda::create([
            'article_id'              => $this->pinza->id,
            'price_type_id'           => $this->distribuidor->id,
            'moneda_id'               => 1,
            'percentage'              => 0,
            'final_price'             => null,
            'setear_precio_final'     => 0,
            'cotizar_desde_otra_moneda' => 0,
        ]);

        $this->pinza->refresh();
        ArticleHelper::setFinalPrice($this->pinza, null, $this->user, null, true);
        $entry = ArticlePriceTypeMoneda::where('article_id', $this->pinza->id)->where('moneda_id', 1)->first();
        $precio_camino_3 = (float) $entry->final_price;

        // Restaura Distribuidor al 40% que usa el resto de la suite, por si este test corre antes
        // que otro dentro del mismo proceso (DatabaseTransactions no siempre alcanza a re-sincronizar
        // relaciones ya cargadas en memoria entre tests).
        $this->pinza->price_types()->updateExistingPivot($this->distribuidor->id, ['percentage' => 40]);

        // Con margen 0%: costo x 1.21 = 1210 en los dos caminos.
        $this->assertEqualsWithDelta(1210, $precio_camino_2, self::DELTA, 'camino 2 (listas de precio), margen 0%');
        $this->assertEqualsWithDelta(1210, $precio_camino_3, self::DELTA, 'camino 3 (listas por moneda), margen 0%');
        $this->assertEqualsWithDelta(
            $precio_camino_2,
            $precio_camino_3,
            0.01,
            'BUG (prompt 02) si esto no cierra: los dos caminos tienen que dar EXACTAMENTE el mismo '.
            'numero con el mismo margen -- antes del fix el camino 3 nunca aplicaba IVA.'
        );
    }

    /**
     * Bug del prompt 02 (precio manual): una entrada de lista con `setear_precio_final = 1` (precio
     * cargado a mano) no se tiene que tocar: ni se le aplica margen, ni se le suma IVA por encima.
     *
     * @test
     */
    public function precio_manual_no_se_toca_prompt_02()
    {
        $this->configurar_condicion_fiscal('RRII');

        $this->user->listas_de_precio = 1;
        $this->user->save();
        $this->activar_extension(self::SLUG_VENTAS_DOLARES);

        ArticlePriceTypeMoneda::where('article_id', $this->pinza->id)->delete();
        ArticlePriceTypeMoneda::create([
            'article_id'              => $this->pinza->id,
            'price_type_id'           => $this->distribuidor->id,
            'moneda_id'               => 1,
            'percentage'              => 0,
            'final_price'             => 2000,
            'setear_precio_final'     => 1,
            'cotizar_desde_otra_moneda' => 0,
        ]);

        $this->pinza->refresh();
        ArticleHelper::setFinalPrice($this->pinza, null, $this->user, null, true);

        $entry = ArticlePriceTypeMoneda::where('article_id', $this->pinza->id)->where('moneda_id', 1)->first();

        $this->assertEqualsWithDelta(
            2000,
            (float) $entry->final_price,
            0.01,
            'el precio cargado a mano (setear_precio_final=1) no se tiene que tocar ni sumarle IVA por encima'
        );
    }

    /**
     * Bug del prompt 03 (simetria camino 2 / camino 4): misma cuenta, mismo costo, mismo margen
     * (40%), calculado por el camino 2 (listas, price_type "Distribuidor") y por el camino 4
     * (listas por categoria, price_type "Mayorista" vinculado a la categoria temporal). Los tres
     * campos que escriben ambos metodos en el mismo pivote (`final_price`,
     * `precio_luego_de_recargos`, `monto_ganancia`) tienen que coincidir -- antes del fix del
     * prompt 03 daban distinto en los tres.
     *
     * @test
     */
    public function simetria_camino_2_y_4_prompt_03()
    {
        $this->configurar_condicion_fiscal('RRII');

        // Camino 2 (Distribuidor, 40%, seteado en setUp()). Se usa configurar_camino() (no se
        // repite la configuracion a mano) porque ya deja $this->pinza/$this->user recien releidos
        // de la base -- sin eso, la relacion `category` de $this->pinza queda cacheada desde ANTES
        // (aplicar_category_percentage_gain() la lee en todos los caminos) y el camino 4 de mas
        // abajo no ve el category_id nuevo.
        $this->configurar_camino(2);

        ArticleHelper::setFinalPrice($this->pinza, null, $this->user, null, true);
        $this->pinza->load('price_types');
        $pivot_2 = $this->pinza->price_types->where('id', $this->distribuidor->id)->first()->pivot;

        // Camino 4 (Mayorista, 40% via category_price_type, seteado en setUp()).
        $this->configurar_camino(4);

        ArticleHelper::setFinalPrice($this->pinza, null, $this->user, null, true);
        $this->pinza->load('price_types');
        $pivot_4 = $this->pinza->price_types->where('id', $this->mayorista->id)->first()->pivot;

        $this->assertEqualsWithDelta(
            (float) $pivot_2->final_price,
            (float) $pivot_4->final_price,
            self::DELTA,
            'BUG (prompt 03) si no coincide: final_price tiene que ser igual entre camino 2 y camino 4 con el mismo margen'
        );
        $this->assertEqualsWithDelta(
            (float) $pivot_2->precio_luego_de_recargos,
            (float) $pivot_4->precio_luego_de_recargos,
            self::DELTA,
            'BUG (prompt 03) si no coincide: precio_luego_de_recargos tiene que ser igual entre los dos caminos'
        );
        $this->assertEqualsWithDelta(
            (float) $pivot_2->monto_ganancia,
            (float) $pivot_4->monto_ganancia,
            self::DELTA,
            'BUG (prompt 03) si no coincide: monto_ganancia tiene que ser igual entre los dos caminos'
        );
    }
}
