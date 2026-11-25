<?php

namespace Tests\Feature\CarritosAjusteStock;

use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Models\OnlineConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Stock\AuditoriaStockTestCase;

/**
 * Misión `carritos-ajuste-stock` — CA11 y CA12: el esquema (migración), el guardado de las tres opciones nuevas
 * de Tienda Online -> Configuración y su descripción para el asistente de IA.
 *
 * Opciones nuevas de `online_configurations` (todas boolean, default 1):
 *  - `ajustar_carritos_por_stock`: ajustar los carritos cuando baja el stock;
 *  - `avisar_ajuste_carrito_por_mail`: mandarle un mail al comprador cuando le ajustan el carrito;
 *  - `avisar_puede_agotarse`: avisar en el carrito que el artículo está en carritos de otros y puede agotarse.
 * Y `article_cart.completar_al_reponer` (boolean, default 1) más el índice `article_cart(article_id)` llamado
 * `ac_article_id_idx`.
 *
 * Falla hoy porque la migración todavía no existe (las columnas y el índice no están: el rojo de los tests de
 * esquema es "columna inexistente", el motivo esperado de esos CA) y porque el controlador y el catálogo del
 * asistente no conocen las claves.
 *
 * IMPORTANTE: PHP 7.4 estricto (ver RESTRICCIONES.md de la misión): nada de sintaxis de PHP 8.
 *
 * @group configuracion-online
 */
class ConfiguracionAjusteDeCarritosTest extends AuditoriaStockTestCase
{
    /** Las tres opciones nuevas del comercio. */
    const OPCIONES = ['ajustar_carritos_por_stock', 'avisar_ajuste_carrito_por_mail', 'avisar_puede_agotarse'];

    /**
     * CA11 — esquema: las tres columnas de `online_configurations` existen y una fila nueva las trae en 1.
     *
     * @test
     */
    public function test_las_opciones_nuevas_nacen_prendidas()
    {
        foreach (self::OPCIONES as $opcion) {
            $this->assertTrue(Schema::hasColumn('online_configurations', $opcion), 'Falta la columna online_configurations.' . $opcion . '.');
        }

        // Fila nueva de otro comercio (sin FK: alcanza con un user_id propio del test).
        $fila = OnlineConfiguration::create(['user_id' => 987654500])->fresh();

        foreach (self::OPCIONES as $opcion) {
            $this->assertEquals(1, (int) $fila->$opcion, $opcion . ' tiene que nacer prendida.');
        }
    }

    /**
     * CA11 — esquema: un renglón insertado sin la columna nueva (la tienda vieja) queda con la casilla marcada.
     *
     * @test
     */
    public function test_un_renglon_nuevo_nace_con_la_casilla_marcada()
    {
        $this->assertTrue(Schema::hasColumn('article_cart', 'completar_al_reponer'), 'Falta la columna article_cart.completar_al_reponer.');

        $id = DB::table('article_cart')->insertGetId([
            'cart_id'    => 987654501,
            'article_id' => 987654502,
            'amount'     => 1,
            'price'      => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEquals(1, (int) DB::table('article_cart')->where('id', $id)->value('completar_al_reponer'));
    }

    /**
     * CA11 — esquema: existe el índice `ac_article_id_idx` sobre `article_cart(article_id)` (la consulta del
     * ajuste filtra por artículo y la tabla no tenía índice).
     *
     * @test
     */
    public function test_existe_el_indice_por_articulo_en_article_cart()
    {
        $indice = DB::select(
            'select index_name, column_name from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ?',
            ['article_cart', 'ac_article_id_idx']
        );

        $this->assertCount(1, $indice, 'Falta el índice ac_article_id_idx en article_cart.');
        $this->assertEquals('article_id', $indice[0]->column_name);
    }

    /**
     * Configuración del dueño del fixture.
     *
     * @return \App\Models\OnlineConfiguration
     */
    protected function configuracion()
    {
        return OnlineConfiguration::where('user_id', $this->usuario()->id)->orderBy('id')->firstOrFail();
    }

    /**
     * CA11 — `PUT api/online-configuration/{id}` con las tres opciones en false las guarda y las devuelve en el JSON.
     *
     * Falla hoy porque el controlador ignora las claves (no se devuelven en `model`).
     *
     * @test
     */
    public function test_el_put_guarda_las_tres_opciones_apagadas()
    {
        $config = $this->configuracion();

        $payload = array_merge($config->fresh()->toArray(), [
            'ajustar_carritos_por_stock'     => false,
            'avisar_ajuste_carrito_por_mail' => false,
            'avisar_puede_agotarse'          => false,
        ]);

        $respuesta = $this->putJson('api/online-configuration/' . $config->id, $payload);

        $respuesta->assertStatus(200);

        $modelo = $respuesta->json('model');

        foreach (self::OPCIONES as $opcion) {
            $this->assertArrayHasKey($opcion, $modelo, $opcion . ' tiene que volver en el JSON del modelo.');
            $this->assertEquals(0, (int) $modelo[$opcion], $opcion . ' devuelta apagada.');
            $this->assertEquals(0, (int) DB::table('online_configurations')->where('id', $config->id)->value($opcion), $opcion . ' guardada apagada.');
        }
    }

    /**
     * CA11 — un PUT que no trae las claves (una pantalla vieja) conserva su valor: no apaga lo que el comercio
     * dejó prendido ni prende lo que apagó.
     *
     * Falla hoy porque las columnas no existen (aserción de esquema del arranque).
     *
     * @test
     */
    public function test_el_put_sin_las_claves_conserva_su_valor()
    {
        foreach (self::OPCIONES as $opcion) {
            $this->assertTrue(Schema::hasColumn('online_configurations', $opcion), 'Falta la columna online_configurations.' . $opcion . '.');
        }

        $config = $this->configuracion();

        // El comercio apagó la primera y la tercera y dejó prendida la del medio.
        DB::table('online_configurations')->where('id', $config->id)->update([
            'ajustar_carritos_por_stock'     => 0,
            'avisar_ajuste_carrito_por_mail' => 1,
            'avisar_puede_agotarse'          => 0,
        ]);

        $payload = $config->fresh()->toArray();

        foreach (self::OPCIONES as $opcion) {
            unset($payload[$opcion]);
        }

        $this->putJson('api/online-configuration/' . $config->id, $payload)->assertStatus(200);

        $fila = DB::table('online_configurations')->where('id', $config->id)->first();

        $this->assertEquals(0, (int) $fila->ajustar_carritos_por_stock);
        $this->assertEquals(1, (int) $fila->avisar_ajuste_carrito_por_mail);
        $this->assertEquals(0, (int) $fila->avisar_puede_agotarse);
    }

    /**
     * CA12 — el asistente de IA conoce las tres opciones: el catálogo de `online_configuration` las declara como
     * checkbox, con etiqueta.
     *
     * Falla hoy porque `EsquemaDeDatosIaHelper` no las declara.
     *
     * @test
     */
    public function test_el_asistente_conoce_las_opciones()
    {
        EsquemaDeDatosIaHelper::olvidar();

        $declaracion = EsquemaDeDatosIaHelper::declaracion('online_configuration');

        $this->assertNotNull($declaracion, 'El catálogo del asistente no tiene online_configuration.');

        foreach (self::OPCIONES as $opcion) {
            $this->assertArrayHasKey($opcion, $declaracion['campos'], 'El asistente no conoce ' . $opcion . '.');
            $this->assertEquals('checkbox', $declaracion['campos'][$opcion]['tipo'], $opcion . ' tiene que ser checkbox.');
        }

        // La descripción de la entidad explica el comportamiento de los carritos (no sólo lista los campos).
        $this->assertNotFalse(stripos($declaracion['descripcion'], 'carrito'), 'La descripción de online_configuration no habla de los carritos.');
    }
}
