<?php

namespace Tests\Feature\Semilla;

use Database\Seeders\FerreteriaArticlesSeeder;
use ReflectionMethod;
use Tests\EmpresaTestCase;

/**
 * Mision "costos de la demo netos de IVA" (27/8/2026).
 *
 * Los `cost` de `FerreteriaArticlesSeeder::get_catalog()` son los precios de la lista del
 * proveedor, o sea BRUTOS. Desde que las cuentas nuevas nacen con
 * `usar_condicion_fiscal_en_costeo = 1`, `articles.cost` tiene que guardar el NETO: un
 * Responsable Inscripto recupera el IVA de sus compras como credito fiscal, asi que el IVA no es
 * costo y se suma recien al vender. Guardar el bruto ahi hacia que el IVA se cobrara dos veces y
 * el precio final de la demo subia 21%.
 *
 * Este archivo no prueba el pipeline de precios (eso ya lo cubre `Feature\Costeo`): fija la
 * descomposicion y, sobre todo, **que el redondeo no mueva el precio final** en la cola barata
 * del catalogo, que es donde la primera version de este cambio se rompia.
 *
 * @group semilla
 */
class CostosDelCatalogoNetosDeIvaTest extends EmpresaTestCase
{
    /**
     * Margen con el que `FerreteriaArticlesSeeder` siembra todo el catalogo
     * (`'percentage_gain' => 50` en el payload de `run()`).
     */
    const MARGEN = 1.5;

    /**
     * Tolerancia del precio final, en porcentaje. El redondeo a dos decimales del costo neto
     * introduce un desvio; lo que se fija aca es que ese desvio sea despreciable, no cero.
     * Medido el 27/8/2026 sobre los 49 articulos: el maximo es 0,1275%.
     */
    const TOLERANCIA_PORCENTUAL = 0.2;

    /**
     * Invoca el `costo_neto()` privado del seeder.
     *
     * @param  int|float $costo_con_iva
     * @return float
     */
    protected function costo_neto($costo_con_iva)
    {
        $metodo = new ReflectionMethod(FerreteriaArticlesSeeder::class, 'costo_neto');
        $metodo->setAccessible(true);

        return $metodo->invoke(new FerreteriaArticlesSeeder(), $costo_con_iva);
    }

    /**
     * Factor de IVA del catalogo (1,21), derivado de la constante y no escrito a mano.
     *
     * @return float
     */
    protected function factor_iva()
    {
        return 1 + (FerreteriaArticlesSeeder::IVA_ALICUOTA_DEL_CATALOGO / 100);
    }

    /**
     * @return void
     */
    public function test_el_costo_neto_le_saca_el_iva_al_costo_de_lista()
    {
        $this->assertEquals(15426.45, $this->costo_neto(18666), '', 0.01);
        $this->assertEquals(8.26, $this->costo_neto(10), '', 0.01);
    }

    /**
     * 🔴 El corazon de esta mision: el precio final que ve el cliente en la demo tiene que quedar
     * donde estaba. Antes de este cambio subia 21% porque el IVA se cobraba dos veces.
     *
     * @return void
     */
    public function test_el_precio_final_no_se_mueve_para_ningun_articulo_del_catalogo()
    {
        $catalogo = (new FerreteriaArticlesSeeder())->get_catalog();

        $this->assertNotEmpty($catalogo, 'El catalogo de la semilla quedo vacio.');

        foreach ($catalogo as $item) {
            $bruto = $item['cost'];

            // Lo que daba el camino viejo: el costo de lista por el margen, sin sumarle IVA
            // (la cuenta nacia en legacy con `aplicar_iva_al_costo = 1`, o sea que el sistema
            // daba el IVA por incluido dentro del costo y no lo volvia a sumar).
            $precio_viejo = $bruto * self::MARGEN;

            // Lo que da el camino nuevo: el costo NETO por el margen, y recien ahi el IVA.
            $precio_nuevo = $this->costo_neto($bruto) * self::MARGEN * $this->factor_iva();

            $desvio = abs(($precio_nuevo / $precio_viejo) - 1) * 100;

            $this->assertLessThan(
                self::TOLERANCIA_PORCENTUAL,
                $desvio,
                'El articulo "'.$item['name'].'" (costo de lista '.$bruto.') mueve su precio final '.
                'un '.round($desvio, 4).'%: de '.round($precio_viejo, 2).' a '.round($precio_nuevo, 2).'. '.
                'Si esto salta con un costo chico, es el redondeo de costo_neto().'
            );
        }
    }

    /**
     * Las dos constantes del IVA del catalogo tienen que describir la misma alicuota: una se usa
     * para descomponer el costo y la otra viaja a `articles.iva_id`, o sea a los comprobantes.
     * Desalinearlas no lo denuncia nada en tiempo de ejecucion.
     *
     * @return void
     */
    public function test_las_dos_constantes_de_iva_del_catalogo_describen_la_misma_alicuota()
    {
        $iva = \App\Models\Iva::find(FerreteriaArticlesSeeder::IVA_ID_DEL_CATALOGO);

        $this->assertNotNull($iva, 'IVA_ID_DEL_CATALOGO no apunta a ninguna fila de `ivas`.');

        $this->assertEquals(
            FerreteriaArticlesSeeder::IVA_ALICUOTA_DEL_CATALOGO,
            (float) $iva->percentage,
            'IVA_ALICUOTA_DEL_CATALOGO ('.FerreteriaArticlesSeeder::IVA_ALICUOTA_DEL_CATALOGO.'%) no '.
            'coincide con la alicuota de IVA_ID_DEL_CATALOGO ('.$iva->percentage.'%). Los costos se '.
            'descomponen con un porcentaje y los comprobantes se emiten con otro.'
        );
    }
}
