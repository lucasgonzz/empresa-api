<?php

namespace Tests\Feature\ConsolidarFacturacion;

use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Models\AfipInformation;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Archivo 1 — LOS RENGLONES DE LA VENTA CONSOLIDADA, CON Y SIN "AGRUPAR ARTICULOS IGUALES"
 * (mision consolidar-agrupar-articulos, 5/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL BUG QUE ESTE ARCHIVO PERSIGUE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `ConsolidarFacturacionHelper::copiar_articulos()` armaba la clave de cada renglon con
 *  "articulo-venta", agrupara o no:
 *
 *   - Agrupando, dos ventas distintas nunca compartian clave: no se juntaba nada entre ventas.
 *     Medido en demo (4.3.6): TUBO x 4, FLEXIBLE x 2 y TUBO x 6 consolidadas con la casilla tildada
 *     dieron una Factura A con tres renglones en vez de dos (TUBO x 10).
 *   - Sin agrupar, dos renglones del mismo articulo en UNA venta compartian clave y el segundo
 *     pisaba al primero: un renglon desaparecia de la contenedora y de la factura.
 *
 *  Todo se lee de `article_sale` despues de consolidar: lo que importa es lo que queda guardado,
 *  que es lo que factura AFIP.
 *
 *  Se llama al helper con `emitir_afip = false`: el endpoint `sales/consolidar-facturacion`
 *  siempre emite, y emitir pegaria contra ARCA de verdad.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group consolidar_facturacion
 */
class Agrupar_articulos_iguales_Test extends EmpresaTestCase
{
    /** Tolerancia de cantidades y plata: nunca igualdad exacta sobre floats. */
    const DELTA = 0.001;

    /** Segundo articulo del fixture, distinto del centinela. */
    const OTRO_ARTICULO = 'Pinza';

    /**
     * Test 1 — Agrupando, el mismo articulo de dos ventas distintas se junta en un renglon.
     *
     * Es el caso medido en demo, con articulos del fixture: Martillo x 4, Pinza x 2 y Martillo x 6
     * tienen que dar DOS renglones, el martillo con 10. La ganancia del renglon fundido es la suma.
     *
     * @test
     */
    public function agrupando_junta_el_mismo_articulo_de_ventas_distintas()
    {
        $martillo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $pinza    = $this->articulo(self::OTRO_ARTICULO);

        $venta_1 = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 4, 'price' => 1000, 'ganancia' => 400]],
        ]);
        $venta_2 = $this->venta_con_renglones([
            [$pinza->id, ['amount' => 2, 'price' => 500, 'ganancia' => 100]],
        ]);
        $venta_3 = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 6, 'price' => 1000, 'ganancia' => 600]],
        ]);

        $renglones = $this->consolidar([$venta_1->id, $venta_2->id, $venta_3->id], true);

        $this->assertCount(2, $renglones, 'Agrupando, el martillo de las dos ventas tiene que quedar en un solo renglon.');

        $renglon_martillo = $this->renglones_de($renglones, $martillo->id);
        $renglon_pinza    = $this->renglones_de($renglones, $pinza->id);

        $this->assertCount(1, $renglon_martillo);
        $this->assertCount(1, $renglon_pinza);

        $this->assertEqualsWithDelta(10, (float) $renglon_martillo[0]->amount, self::DELTA, '4 + 6 del martillo.');
        $this->assertEqualsWithDelta(1000, (float) $renglon_martillo[0]->price, self::DELTA);
        $this->assertEqualsWithDelta(1000, (float) $renglon_martillo[0]->ganancia, self::DELTA, 'La ganancia se suma: 400 + 600.');
        $this->assertEqualsWithDelta(2, (float) $renglon_pinza[0]->amount, self::DELTA);
    }

    /**
     * Test 2 — Agrupando, el mismo articulo a PRECIOS DISTINTOS no se funde.
     *
     * El renglon de la factura es cantidad x precio unitario: fundir 4 a 1.000 con 6 a 1.100
     * facturaria 10 a 1.000 contra una contenedora de 10.600. Quedan dos renglones, a proposito.
     *
     * @test
     */
    public function agrupando_no_funde_el_mismo_articulo_a_precios_distintos()
    {
        $martillo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $venta_1 = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 4, 'price' => 1000]],
        ]);
        $venta_2 = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 6, 'price' => 1100]],
        ]);

        $renglones = $this->consolidar([$venta_1->id, $venta_2->id], true);

        $this->assertCount(2, $renglones, 'Dos precios distintos son dos renglones de factura distintos.');

        $this->assertEqualsWithDelta(4, (float) $renglones[0]->amount, self::DELTA);
        $this->assertEqualsWithDelta(1000, (float) $renglones[0]->price, self::DELTA);
        $this->assertEqualsWithDelta(6, (float) $renglones[1]->amount, self::DELTA);
        $this->assertEqualsWithDelta(1100, (float) $renglones[1]->price, self::DELTA);
    }

    /**
     * Test 3 — Agrupando, el mismo articulo con ALICUOTA o NOMBRE distintos no se funde.
     *
     * La alicuota es la del renglon en la factura y el nombre personalizado se imprime en lugar del
     * del catalogo: fundirlos le cambiaria el IVA o el nombre a la mitad de la cantidad.
     *
     * @test
     */
    public function agrupando_no_funde_alicuotas_ni_nombres_distintos()
    {
        $martillo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $venta_1 = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 1, 'price' => 1000, 'iva_percentage' => '21.00']],
            [$martillo->id, ['amount' => 1, 'price' => 1000, 'iva_percentage' => '21.00', 'name' => 'Martillo de regalo']],
        ]);
        $venta_2 = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 1, 'price' => 1000, 'iva_percentage' => '10.50']],
        ]);

        $renglones = $this->consolidar([$venta_1->id, $venta_2->id], true);

        $this->assertCount(3, $renglones, 'Alicuota o nombre distintos son renglones de factura distintos.');
    }

    /**
     * Test 4 — Agrupando, el renglon fundido suma lo devuelto y conserva alicuota, neto y nombre.
     *
     * `returned_amount` antes quedaba el del primer renglon; e `iva_percentage`, `price_sin_iva` y
     * `name` no se copiaban, asi que la factura consolidada salia con el IVA ACTUAL del articulo y
     * el nombre del catalogo.
     *
     * @test
     */
    public function agrupando_suma_lo_devuelto_y_conserva_alicuota_neto_y_nombre()
    {
        $martillo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $pivot = [
            'price'          => 1000,
            'iva_percentage' => '10.50',
            'price_sin_iva'  => 904.98,
            'name'           => 'Martillo acero forjado',
        ];

        $venta_1 = $this->venta_con_renglones([
            [$martillo->id, array_merge($pivot, ['amount' => 4, 'returned_amount' => 1])],
        ]);
        $venta_2 = $this->venta_con_renglones([
            [$martillo->id, array_merge($pivot, ['amount' => 6, 'returned_amount' => 2, 'delivered_amount' => 3])],
        ]);

        $renglones = $this->consolidar([$venta_1->id, $venta_2->id], true);

        $this->assertCount(1, $renglones);

        $this->assertEqualsWithDelta(10, (float) $renglones[0]->amount, self::DELTA);
        $this->assertEqualsWithDelta(3, (float) $renglones[0]->returned_amount, self::DELTA, 'Lo devuelto se suma: 1 + 2.');
        $this->assertEqualsWithDelta(3, (float) $renglones[0]->delivered_amount, self::DELTA, 'Lo entregado se suma aunque el primer renglon no lo tuviera cargado.');
        $this->assertEqualsWithDelta(10.5, (float) $renglones[0]->iva_percentage, self::DELTA, 'La alicuota es la del renglon, no la actual del articulo.');
        $this->assertEqualsWithDelta(904.98, (float) $renglones[0]->price_sin_iva, self::DELTA);
        $this->assertSame('Martillo acero forjado', $renglones[0]->name);
    }

    /**
     * Test 5 — Agrupando, la alicuota que se compara es la EFECTIVA, la que va a usar la factura.
     *
     * Las ventas que nacen de un presupuesto o de produccion no guardan `iva_percentage` ni
     * `price_sin_iva` en el renglon; la factura usa entonces la alicuota actual del articulo
     * (`AfipItemCalculator::resolve_article_iva_percentage()`). Un renglon asi y uno de VENDER al 21%
     * del mismo articulo (que esta al 21% en el fixture) se facturan igual: se juntan, y el fundido
     * se queda con la alicuota y el neto del que los tenia. Uno al 10,5% queda aparte.
     *
     * @test
     */
    public function agrupando_compara_la_alicuota_efectiva_del_renglon()
    {
        $martillo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $this->assertEqualsWithDelta(21, (float) $martillo->iva->percentage, self::DELTA, 'El fixture tiene el martillo al 21%.');

        $venta_de_presupuesto = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 4, 'price' => 1000]],
        ]);
        $venta_de_vender = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 6, 'price' => 1000, 'iva_percentage' => '21', 'price_sin_iva' => 826.45]],
        ]);
        $venta_al_diez_y_medio = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 1, 'price' => 1000, 'iva_percentage' => '10.5', 'price_sin_iva' => 904.98]],
        ]);

        $renglones = $this->consolidar([$venta_de_presupuesto->id, $venta_de_vender->id, $venta_al_diez_y_medio->id], true);

        $this->assertCount(2, $renglones, 'Sin alicuota en el renglon vale la del articulo (21%): se junta con el de VENDER; el de 10,5% no.');

        $fundido = $this->renglon_con_cantidad($renglones, 10);

        $this->assertEqualsWithDelta(21, (float) $fundido->iva_percentage, self::DELTA, 'El fundido completa la alicuota que el primero no tenia.');
        $this->assertEqualsWithDelta(826.45, (float) $fundido->price_sin_iva, self::DELTA, 'Y el neto.');

        $this->assertEqualsWithDelta(10.5, (float) $this->renglon_con_cantidad($renglones, 1)->iva_percentage, self::DELTA);
    }

    /**
     * Test 6 — Sin agrupar, no se pierde ningun renglon.
     *
     * Una venta con DOS renglones del mismo articulo (varios precios) y otra con el mismo articulo:
     * tienen que quedar los TRES. Antes, los dos de la primera venta compartian clave y el segundo
     * pisaba al primero.
     *
     * @test
     */
    public function sin_agrupar_cada_renglon_queda_aparte()
    {
        $martillo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $venta_1 = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 1, 'price' => 1000, 'name' => 'Martillo con nombre']],
            [$martillo->id, ['amount' => 2, 'price' => 900]],
        ]);
        $venta_2 = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 3, 'price' => 1000]],
        ]);

        $renglones = $this->consolidar([$venta_1->id, $venta_2->id], false);

        $this->assertCount(3, $renglones, 'Sin agrupar, cada renglon de cada venta es un renglon de la consolidada.');

        $cantidades = [];

        foreach ($renglones as $renglon) {
            $cantidades[] = (float) $renglon->amount;
        }

        sort($cantidades);

        $this->assertEquals([1.0, 2.0, 3.0], $cantidades);
        $this->assertSame('Martillo con nombre', $this->renglon_con_cantidad($renglones, 1)->name);
    }

    /**
     * Test 7 — Sin agrupar, el mismo articulo de dos ventas tambien queda en dos renglones.
     *
     * Es el comportamiento que ya tenia la opcion destildada entre ventas distintas; se deja escrito
     * para que el arreglo de la clave al agrupar no lo cambie.
     *
     * @test
     */
    public function sin_agrupar_el_mismo_articulo_de_dos_ventas_queda_en_dos_renglones()
    {
        $martillo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $venta_1 = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 4, 'price' => 1000]],
        ]);
        $venta_2 = $this->venta_con_renglones([
            [$martillo->id, ['amount' => 6, 'price' => 1000]],
        ]);

        $renglones = $this->consolidar([$venta_1->id, $venta_2->id], false);

        $this->assertCount(2, $renglones);
        $this->assertEqualsWithDelta(4, (float) $renglones[0]->amount, self::DELTA);
        $this->assertEqualsWithDelta(6, (float) $renglones[1]->amount, self::DELTA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El comercio del fixture.
     *
     * @return \App\Models\User
     */
    protected function comercio()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return $user;
    }

    /**
     * El cliente del fixture al que se le hacen todas las ventas de este archivo.
     *
     * @return \App\Models\Client
     */
    protected function cliente()
    {
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();

        $this->assertNotNull($client, 'Falta el cliente "'.TestingFerreteriaSeeder::CLIENTE_CC.'" del fixture.');

        return $client;
    }

    /**
     * Crea una venta terminada del cliente directo en la base, con los renglones indicados.
     *
     * Directo en la base y no por el endpoint: lo que se prueba es la copia de renglones de la
     * consolidacion, no el guardado de la venta. Y es la unica forma de tener dos renglones del
     * mismo articulo con el pivot exacto que se quiere medir.
     *
     * @param  array  $renglones  Lista de [article_id, datos del pivot].
     * @return \App\Models\Sale
     */
    protected function venta_con_renglones($renglones)
    {
        $total = 0;

        foreach ($renglones as $renglon) {
            $total += (float) $renglon[1]['amount'] * (float) $renglon[1]['price'];
        }

        $sale = Sale::create([
            'user_id'                    => $this->comercio()->id,
            'client_id'                  => $this->cliente()->id,
            'omitir_en_cuenta_corriente' => 1,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'sub_total'                  => $total,
            'total'                      => $total,
            'moneda_id'                  => 1,
            'descuento'                  => 0,
        ]);

        foreach ($renglones as $renglon) {
            $sale->articles()->attach($renglon[0], $renglon[1]);
        }

        return $sale;
    }

    /**
     * Consolida las ventas sin emitir y devuelve los renglones guardados de la contenedora, en el
     * orden en que se adjuntaron.
     *
     * @param  array  $sale_ids
     * @param  bool   $agrupar
     * @return array  Filas de `article_sale`.
     */
    protected function consolidar($sale_ids, $agrupar)
    {
        $afip_information = AfipInformation::where('user_id', $this->comercio()->id)->first();

        $this->assertNotNull($afip_information, 'Falta la configuracion de AFIP del fixture.');

        $consolidada = ConsolidarFacturacionHelper::consolidar(
            $sale_ids,
            $this->cliente()->id,
            $this->comercio()->id,
            $afip_information->id,
            1,
            $agrupar,
            [],
            // 🔴 Sin emitir: emitir pegaria contra ARCA de verdad.
            false
        );

        return DB::table('article_sale')
            ->where('sale_id', $consolidada->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Los renglones de un articulo.
     *
     * @param  array  $renglones
     * @param  int    $article_id
     * @return array
     */
    protected function renglones_de($renglones, $article_id)
    {
        return array_values(array_filter($renglones, function ($renglon) use ($article_id) {
            return (int) $renglon->article_id === (int) $article_id;
        }));
    }

    /**
     * El renglon con una cantidad dada (en este archivo las cantidades no se repiten dentro de un
     * mismo test).
     *
     * @param  array  $renglones
     * @param  float  $cantidad
     * @return object
     */
    protected function renglon_con_cantidad($renglones, $cantidad)
    {
        foreach ($renglones as $renglon) {
            if (abs((float) $renglon->amount - $cantidad) < self::DELTA) {
                return $renglon;
            }
        }

        $this->fail('No hay renglon con cantidad '.$cantidad.'.');
    }
}
