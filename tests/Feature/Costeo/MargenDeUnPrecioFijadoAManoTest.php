<?php

namespace Tests\Feature\Costeo;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\DesglosePrecioHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\Iva;
use App\Models\SaleTax;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tarea 9 — el margen y la ganancia que el sistema deriva de un precio de lista fijado a mano.
 *
 * El caso que lo motivo, medido por Lucas el 10/8/2026: costo real 1.000, IVA 21%, lista con
 * "setear precio final" y precio cargado a mano de 3.000. La tarjeta mostraba margen 200% y
 * ganancia $2.000, porque comparaba un precio CON IVA contra un costo SIN IVA. Son dos magnitudes
 * distintas y el resultado no significa nada: los $520 de diferencia son IVA, plata que se le debe
 * a AFIP y no ganancia de nadie.
 *
 * 🔴 EL TEST QUE IMPORTA es el de ida y vuelta: tomar el margen derivado, aplicarlo por el camino
 * normal, y confirmar que vuelve a dar el mismo precio. Si no vuelve, la inversion no es el espejo
 * del camino forward y hay algo mal. Es tambien el que denuncia el dia que alguien agregue una capa
 * nueva al pipeline de precios sin tocar la inversion.
 *
 * @group costeo-precios
 */
class MargenDeUnPrecioFijadoAManoTest extends TestCase
{
    use DatabaseTransactions;

    /** Tolerancia de centavos: los dos caminos hacen divisiones y multiplicaciones en float. */
    const DELTA = 0.01;

    const COSTO = 1000;
    const PRECIO_FIJADO_A_MANO = 3000;

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\Article */
    protected $article;

    /** @var array<string,mixed> */
    protected $condicion_original = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->condicion_original = [
            'usar_condicion_fiscal_en_costeo' => $this->user->usar_condicion_fiscal_en_costeo,
            'condicion_iva_precios' => $this->user->condicion_iva_precios,
        ];

        $iva_21 = Iva::where('percentage', 21)->first();

        if (is_null($iva_21)) {
            $this->markTestSkipped('La base de testing no tiene el IVA de 21% sembrado.');
        }

        /**
         * La base de testing tiene un IIBB del 3,5% sembrado para el usuario 500, y los numeros de
         * esta tarea (2479,34 y 147,93%) son los del escenario que describe la mision: costo 1.000,
         * IVA 21% y NINGUN impuesto sobre ventas. Se limpian aca para partir de ese escenario y que
         * cada test agregue el impuesto que necesita.
         *
         * Ojo: esto NO es acomodar un numero esperado para que pase. La primera corrida de este test
         * dio 2392,56 en vez de 2479,34, que es exactamente 2479,34 x 0,965 — o sea el codigo estaba
         * bien y lo que fallaba era el escenario. Queda escrito porque la proxima persona que vea
         * este delete va a querer saber por que esta.
         *
         * DatabaseTransactions revierte el borrado al terminar cada test.
         */
        SaleTax::where('user_id', 500)->delete();
        ArticlePricesHelper::$sale_taxes_cache = [];

        $this->article = Article::create([
            'name' => 'zz Articulo de la tarea 9',
            'user_id' => 500,
            'cost' => self::COSTO,
            'iva_id' => $iva_21->id,
            'aplicar_iva' => 1,
            'status' => 'active',
        ]);

        $this->article = Article::with('iva')->find($this->article->id);
    }

    protected function tearDown(): void
    {
        if (!is_null($this->user) && count($this->condicion_original) > 0) {
            $this->user->usar_condicion_fiscal_en_costeo = $this->condicion_original['usar_condicion_fiscal_en_costeo'];
            $this->user->condicion_iva_precios = $this->condicion_original['condicion_iva_precios'];
            $this->user->save();
        }

        parent::tearDown();
    }

    /**
     * Responsable Inscripto: el IVA se suma al vender, asi que la inversion tiene que deshacerlo.
     *
     * @return \App\Models\User
     */
    protected function cuenta_responsable_inscripto()
    {
        $this->user->usar_condicion_fiscal_en_costeo = 1;
        $this->user->condicion_iva_precios = 'RRII';
        $this->user->save();

        return User::find(500);
    }

    /**
     * Monotributista: el IVA ya viene adentro del costo, asi que el camino forward NO lo suma y la
     * inversion NO lo tiene que deshacer.
     *
     * @return \App\Models\User
     */
    protected function cuenta_monotributista()
    {
        $this->user->usar_condicion_fiscal_en_costeo = 1;
        $this->user->condicion_iva_precios = 'MT';
        $this->user->save();

        return User::find(500);
    }

    /**
     * El camino FORWARD de una lista, tal cual lo hace aplicar_precios_segun_listas_de_precios():
     * costo -> margen -> sale_taxes -> IVA. Se reproduce aca para poder cerrar el ida y vuelta sin
     * montar toda la maquinaria de pivotes.
     *
     * @param float $percentage
     * @param \App\Models\User $user
     * @return float
     */
    protected function precio_por_el_camino_normal($percentage, $user)
    {
        $price = self::COSTO + (self::COSTO * $percentage / 100);

        $res = ArticlePricesHelper::aplicar_sale_taxes($this->article, $price, $user, []);
        $price = $res['price'];

        if (!ArticlePricesHelper::iva_va_al_costo($user)) {
            $res = ArticlePricesHelper::aplicar_iva($this->article, $price, $user, []);
            $price = $res['price'];
        }

        return $price;
    }

    /**
     * Criterio 1: costo 1.000, IVA 21%, precio fijado a mano 3.000 -> margen 147,93% (antes: 200%).
     *
     * @group costeo-precios
     * @test
     */
    public function el_margen_de_un_precio_fijado_a_mano_se_deriva_sin_el_iva()
    {
        $user = $this->cuenta_responsable_inscripto();

        $base = ArticlePricesHelper::quitar_iva_y_sale_taxes(
            $this->article,
            self::PRECIO_FIJADO_A_MANO,
            $user
        );

        $percentage = ($base - self::COSTO) / self::COSTO * 100;

        $this->assertEqualsWithDelta(2479.34, $base, self::DELTA);
        $this->assertEqualsWithDelta(147.93, $percentage, self::DELTA);

        /** Y que efectivamente NO sea el numero viejo. */
        $this->assertNotEqualsWithDelta(200, $percentage, 1);
    }

    /**
     * 🔴 Criterio 2, el que hace real a todo lo demas: tomar el margen derivado, aplicarlo por el
     * camino normal, y confirmar que vuelve a dar exactamente el precio que la persona fijo.
     *
     * @group costeo-precios
     * @test
     */
    public function el_ida_y_vuelta_devuelve_el_mismo_precio()
    {
        $user = $this->cuenta_responsable_inscripto();

        $base = ArticlePricesHelper::quitar_iva_y_sale_taxes(
            $this->article,
            self::PRECIO_FIJADO_A_MANO,
            $user
        );

        $percentage = ($base - self::COSTO) / self::COSTO * 100;

        $de_vuelta = $this->precio_por_el_camino_normal($percentage, $user);

        $this->assertEqualsWithDelta(self::PRECIO_FIJADO_A_MANO, $de_vuelta, self::DELTA);
    }

    /**
     * Criterio 3: lo mismo con un impuesto sobre ventas del 3% activo -> margen 140,50%, y el ida y
     * vuelta tiene que seguir cerrando en 3.000.
     *
     * @group costeo-precios
     * @test
     */
    public function con_un_impuesto_sobre_ventas_el_margen_baja_y_el_ida_y_vuelta_sigue_cerrando()
    {
        $user = $this->cuenta_responsable_inscripto();

        SaleTax::create([
            'name' => 'zz Ingresos Brutos de la tarea 9',
            'percentage' => 3,
            'user_id' => 500,
        ]);

        /** El helper cachea los sale_taxes por request: se limpia para que vea el nuevo. */
        ArticlePricesHelper::$sale_taxes_cache = [];

        $base = ArticlePricesHelper::quitar_iva_y_sale_taxes(
            $this->article,
            self::PRECIO_FIJADO_A_MANO,
            $user
        );

        $percentage = ($base - self::COSTO) / self::COSTO * 100;

        $de_vuelta = $this->precio_por_el_camino_normal($percentage, $user);

        $this->assertEqualsWithDelta(2404.96, $base, self::DELTA);
        $this->assertEqualsWithDelta(140.50, $percentage, self::DELTA);
        $this->assertEqualsWithDelta(self::PRECIO_FIJADO_A_MANO, $de_vuelta, self::DELTA);
    }

    /**
     * Criterio 4: en una cuenta Monotributista el camino forward no suma IVA, asi que la inversion
     * tampoco lo deshace y el margen sigue siendo 200%. Es el unico caso donde el numero viejo
     * estaba bien, y por eso tiene que quedar igual.
     *
     * @group costeo-precios
     * @test
     */
    public function en_monotributo_la_inversion_no_deshace_el_iva()
    {
        $user = $this->cuenta_monotributista();

        ArticlePricesHelper::$sale_taxes_cache = [];

        $base = ArticlePricesHelper::quitar_iva_y_sale_taxes(
            $this->article,
            self::PRECIO_FIJADO_A_MANO,
            $user
        );

        $percentage = ($base - self::COSTO) / self::COSTO * 100;

        $this->assertEqualsWithDelta(self::PRECIO_FIJADO_A_MANO, $base, self::DELTA);
        $this->assertEqualsWithDelta(200, $percentage, self::DELTA);
    }

    /**
     * Criterio 5: la ganancia tambien deja de incluir el IVA. Una lista al 40% sobre costo 1.000
     * mostraba $694; la ganancia real es $400.
     *
     * @group costeo-precios
     * @test
     */
    public function la_ganancia_es_neta_de_iva()
    {
        $user = $this->cuenta_responsable_inscripto();

        ArticlePricesHelper::$sale_taxes_cache = [];

        /** El precio de una lista al 40%, por el camino normal: 1000 -> 1400 -> +21% = 1694. */
        $precio_de_la_lista = $this->precio_por_el_camino_normal(40, $user);

        $this->assertEqualsWithDelta(1694, $precio_de_la_lista, self::DELTA);

        /** Una lista sin recargos: precio_luego_de_recargos == el precio de la lista. */
        $price_type = (object) ['price_type_surchages' => []];

        $con_la_cuenta_nueva = ArticlePricesHelper::aplicar_price_type_surchages(
            $price_type,
            $precio_de_la_lista,
            self::COSTO,
            null,
            $this->article,
            $user
        );

        $con_la_cuenta_vieja = ArticlePricesHelper::aplicar_price_type_surchages(
            $price_type,
            $precio_de_la_lista,
            self::COSTO,
            null
        );

        $this->assertEqualsWithDelta(400, $con_la_cuenta_nueva['monto_ganancia'], self::DELTA);
        $this->assertEqualsWithDelta(694, $con_la_cuenta_vieja['monto_ganancia'], self::DELTA);

        /**
         * Y precio_luego_de_recargos NO cambia: sigue siendo el precio CON IVA, que es el que se le
         * cobra a la persona. Lo unico que cambio es contra que se lo compara.
         */
        $this->assertEqualsWithDelta(
            $con_la_cuenta_vieja['precio_luego_de_recargos'],
            $con_la_cuenta_nueva['precio_luego_de_recargos'],
            self::DELTA
        );
    }

    /**
     * Parte 3: el desglose de una lista no cuenta el precio final unico. Se testea el corte, que es
     * lo unico que esa mision cambiaba del desglose — el calculo no se toca.
     *
     * ⚠️ La ENTRADA de este test cambio el 21/8/2026, no sus aserciones. El desglose dejo de ser un
     * array de strings planos y paso a ser un array de entradas estructuradas
     * (DesglosePrecioHelper), asi que el corte ya no busca la prosa 'CALCULO DEL PRECIO FINAL' sino
     * la clave estable `precio_final`. Lo que este test verifica sigue siendo exactamente lo mismo:
     * donde corta, que conserva y que descarta.
     *
     * El fixture se arma con el propio DesglosePrecioHelper y no a mano, para que no pueda quedar
     * describiendo una forma de dato que produccion ya no produce.
     *
     * @group costeo-precios
     * @test
     */
    public function el_corte_saca_la_seccion_del_precio_final_y_deja_la_del_costo_real()
    {
        $des = [
            DesglosePrecioHelper::seccion(DesglosePrecioHelper::CLAVE_COSTO_REAL, 'Calculo del costo real', 'CALCULO DEL COSTO REAL'),
            DesglosePrecioHelper::linea(DesglosePrecioHelper::COSTO, 'Costo', null, '$1.000', 'Comienza con costo de $1.000'),
            DesglosePrecioHelper::linea(DesglosePrecioHelper::TOTAL, 'Costo real', null, '$1.000', 'Costo Real queda en = $1.000'),
            DesglosePrecioHelper::seccion(DesglosePrecioHelper::CLAVE_PRECIO_FINAL, 'Calculo del precio final', 'CALCULO DEL PRECIO FINAL'),
            DesglosePrecioHelper::linea(DesglosePrecioHelper::COSTO, 'Costo real', null, '$1.000', 'Comienza con costo real en = $1.000'),
            DesglosePrecioHelper::linea(DesglosePrecioHelper::MARGEN, 'Margen del articulo', '100.00%', null, 'Mas margen del articulo del 100.00%'),
            DesglosePrecioHelper::linea(DesglosePrecioHelper::IVA, 'IVA', '21%', '$2.420', 'Mas IVA de 21% = $2.420'),
        ];

        $cortado = ArticleHelper::quitar_seccion_del_precio_final_unico($des);

        $textos = array_column($cortado, 'texto');

        $this->assertCount(3, $cortado);
        $this->assertEquals('CALCULO DEL COSTO REAL', $textos[0]);
        $this->assertNotContains('CALCULO DEL PRECIO FINAL', $textos);
        $this->assertNotContains('Mas IVA de 21% = $2.420', $textos);
    }

    /**
     * Bordes del corte: sin la seccion no toca nada, y con claves salteadas tampoco se le escapa
     * (array_slice cuenta posiciones, no claves — por eso el helper normaliza antes).
     *
     * @group costeo-precios
     * @test
     */
    public function el_corte_no_toca_un_desglose_que_no_tiene_esa_seccion()
    {
        $sin_la_seccion = [
            DesglosePrecioHelper::seccion(DesglosePrecioHelper::CLAVE_COSTO_REAL, 'Calculo del costo real', 'CALCULO DEL COSTO REAL'),
            DesglosePrecioHelper::linea(DesglosePrecioHelper::COSTO, 'Costo', null, '$1.000', 'Comienza con costo de $1.000'),
        ];

        $this->assertEquals(
            $sin_la_seccion,
            ArticleHelper::quitar_seccion_del_precio_final_unico($sin_la_seccion)
        );

        $con_claves_salteadas = [
            5  => DesglosePrecioHelper::seccion(DesglosePrecioHelper::CLAVE_COSTO_REAL, 'Calculo del costo real', 'CALCULO DEL COSTO REAL'),
            9  => DesglosePrecioHelper::seccion(DesglosePrecioHelper::CLAVE_PRECIO_FINAL, 'Calculo del precio final', 'CALCULO DEL PRECIO FINAL'),
            11 => DesglosePrecioHelper::linea(DesglosePrecioHelper::IVA, 'IVA', '21%', '$2.420', 'Mas IVA de 21% = $2.420'),
        ];

        $cortado = ArticleHelper::quitar_seccion_del_precio_final_unico($con_claves_salteadas);

        $this->assertCount(1, $cortado);
        $this->assertEquals('CALCULO DEL COSTO REAL', $cortado[0]['texto']);
    }

    /**
     * Un desglose de strings planos NO se corta, y eso es correcto.
     *
     * Los desgloses guardados en sales.price_description y provider_orders.price_description siguen
     * siendo arrays de strings, pero nunca pasan por este corte: el unico llamador en produccion es
     * ArticleHelper::setFinalPrice(), que desde el 21/8/2026 siempre arma entradas estructuradas.
     *
     * Este test existe para dejar escrito que la tolerancia es deliberada --el helper devuelve el
     * array tal cual en vez de romper-- y para que, si algun dia alguien le enchufa strings a esta
     * funcion esperando que corte, el rojo aparezca aca y no en el modal de un cliente.
     *
     * @group costeo-precios
     * @test
     */
    public function el_corte_deja_intacto_un_desglose_de_strings_planos()
    {
        $des_viejo = [
            'CALCULO DEL COSTO REAL',
            'Comienza con costo de $1.000',
            'CALCULO DEL PRECIO FINAL',
            'Mas IVA de 21% = $2.420',
        ];

        $this->assertEquals(
            $des_viejo,
            ArticleHelper::quitar_seccion_del_precio_final_unico($des_viejo),
            'un desglose de strings no tiene la clave estable con la que se corta: se devuelve entero'
        );
    }
}
