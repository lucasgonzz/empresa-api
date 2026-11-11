<?php

namespace Tests\Feature\DimensionSearch;

use App\Http\Controllers\Helpers\DimensionReferenceSiteHelper;
use App\Models\DimensionSearchItem;
use App\Services\DimensionSearch\DimensionPageText;
use Illuminate\Support\Facades\Http;

/**
 * Los planes nuevos del motor (misión medidas-sitios-de-referencia, 11/11/2026): la búsqueda en los sitios de
 * referencia del comercio (primera, con `site:` y todos los códigos del artículo) y la del código de barras fuera
 * de Argentina (`gl=us`, `hl=en`).
 *
 * El caso real: en la corrida de Masquito (4.143 artículos) obi.de, verdefacile.eu, meteorpiston.com y
 * carbydoctor.com.au no aparecieron ni una vez, porque la búsqueda era en Argentina y en castellano.
 *
 * Todo con Serper, páginas e IA falsos: nada sale a internet.
 */
class Planes_de_sitios_y_global_Test extends DimensionSearchTestCase
{
    /** El EAN real de la manguera Gardena de OBI. */
    const GTIN_OBI = '4078500001694';

    const URL_OBI = 'https://www.obi.de/gartenschlauch/gardena-classic-schlauch/p/1234567';

    /**
     * Los gl de cada consulta que recibió el Serper falso, en orden (consulta => gl).
     *
     * @return array  Lista de ['q', 'gl', 'hl'].
     */
    protected function busquedas_hechas()
    {
        $hechas = [];

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'google.serper.dev') !== false) {
                $datos    = $par[0]->data();
                $hechas[] = ['q' => $datos['q'], 'gl' => $datos['gl'], 'hl' => $datos['hl']];
            }
        }

        return $hechas;
    }

    /**
     * La entrada del diagnóstico de un criterio (null si no está).
     *
     * @param  \App\Models\DimensionSearchItem $item
     * @param  string $criterio
     * @return array|null
     */
    protected function entrada($item, $criterio)
    {
        foreach ($item->diagnostico as $entrada) {
            if ($entrada['criterio'] === $criterio) {
                return $entrada;
            }
        }

        return null;
    }

    /**
     * Los cuatro datos de la manguera, citados de la página de prueba (en inglés: las etiquetas en alemán son de
     * otro test).
     *
     * @param  string $url
     * @return array
     */
    protected function datos_de_la_manguera($url)
    {
        return [
            'peso'  => $this->dato(2.7, 'kg', 'Weight 2.70 kg', $url),
            'alto'  => $this->dato(9, 'cm', 'Height 9.0 cm', $url),
            'ancho' => $this->dato(38, 'cm', 'Width 38.0 cm', $url),
            'largo' => $this->dato(38, 'cm', 'Depth 38.0 cm', $url),
        ];
    }

    /**
     * @group dimension-search
     * @test
     */
    public function con_sitios_la_primera_consulta_es_en_los_sitios_con_todos_los_codigos_y_sin_region_argentina()
    {
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['obi.de', 'verdefacile.eu']);

        $articulo = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M', self::GTIN_OBI, ['provider_code' => 'GA-18000']);
        $consulta = '("'.self::GTIN_OBI.'" OR "GA-18000" OR "GA18000") (site:obi.de OR site:verdefacile.eu)';

        $this->falsear(
            [$consulta => [$this->resultado(self::URL_OBI, 'GARDENA Classic Schlauch')]],
            [self::URL_OBI => $this->pagina('GARDENA Classic Schlauch', 'EAN '.self::GTIN_OBI.'. Weight 2.70 kg Height 9.0 cm Width 38.0 cm Depth 38.0 cm')],
            $this->ficha('exacto', $this->datos_de_la_manguera(self::URL_OBI))
        );

        list($item, $resultado) = $this->procesar($this->corrida([$articulo]), $articulo);

        $this->assertSame([['q' => $consulta, 'gl' => 'us', 'hl' => 'en']], $this->busquedas_hechas(), 'Una sola búsqueda: la de los sitios, y ya dio los datos.');
        $this->assertSame(1, $resultado['busquedas_intentadas']);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        // criterio_usado NO cambia de dominio: sale del código que traía la página.
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_DE_BARRAS, $item->criterio_usado);
        $this->assertEquals(2.7, (float) $articulo->fresh()->peso);

        $entrada = $item->diagnostico[0];
        $this->assertSame('sitios_de_referencia', $entrada['criterio']);
        $this->assertSame('datos', $entrada['resultado']);
        $this->assertSame('us', $entrada['gl']);
    }

    /**
     * En el plan de los sitios la página cuenta si trae CUALQUIER código del artículo, no solo el GTIN.
     *
     * @group dimension-search
     * @test
     */
    public function en_los_sitios_una_pagina_con_el_codigo_de_proveedor_cuenta_aunque_no_traiga_el_de_barras()
    {
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['verdefacile.eu']);

        $url      = 'https://www.verdefacile.eu/it/catena-motosega-42550194950';
        $articulo = $this->nuevo_articulo('CADENA MOTOSIERRA 3/8 50 ESLABONES', self::GTIN_OBI, ['provider_code' => '42550194950']);

        $this->falsear(
            ['("'.self::GTIN_OBI.'" OR "42550194950") site:verdefacile.eu' => [$this->resultado($url, 'Catena motosega 42550194950')]],
            [$url => $this->pagina('Catena motosega', 'Riferimento 42550194950. Weight: 4,5 Kg')],
            $this->ficha('exacto', ['peso' => $this->dato(4.5, 'kg', 'Weight: 4,5 Kg', $url)])
        );

        list($item) = $this->procesar($this->corrida([$articulo]), $articulo);

        $this->assertSame('("'.self::GTIN_OBI.'" OR "42550194950") site:verdefacile.eu', $this->consultas_serper[0]);
        $this->assertTrue($this->entrada($item, 'sitios_de_referencia')['paginas'][0]['leida'], 'La página trae el código de proveedor: se leyó.');
        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status, 'Solo el peso: medidas parciales.');
        $this->assertSame('medidas_parciales', $item->motivo);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_PROVEEDOR, $item->criterio_usado);
        $this->assertEquals(4.5, (float) $item->peso_kg);
    }

    /**
     * El código de proveedor compacto (sin espacios ni guiones) también se busca, y sin GTIN el plan es del proveedor.
     *
     * @group dimension-search
     * @test
     */
    public function sin_gtin_busca_el_proveedor_y_su_forma_compacta_en_los_sitios()
    {
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['carbydoctor.com.au', 'meteorpiston.com']);

        $articulo = $this->nuevo_articulo('CARBURADOR KOHLER CH18', null, ['provider_code' => '32 853 63-S']);

        $this->falsear([], [], $this->ficha('otro_producto'));

        list($item) = $this->procesar($this->corrida([$articulo]), $articulo);

        $this->assertSame([
            '("32 853 63-S" OR "3285363S") (site:carbydoctor.com.au OR site:meteorpiston.com)',
            '"32 853 63-S" CARBURADOR KOHLER CH18',
            'CARBURADOR KOHLER CH18 ficha técnica peso medidas',
        ], $this->consultas_serper);

        $this->assertSame(['sitios_de_referencia', 'codigo_de_barras', 'codigo_proveedor', 'nombre'], array_column($item->diagnostico, 'criterio'));
        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function sin_sitios_no_hay_plan_de_sitios()
    {
        $articulo = $this->nuevo_articulo('CARBURADOR KOHLER CH18', null, ['provider_code' => '32 853 63-S']);

        $this->falsear([], [], $this->ficha('otro_producto'));

        list($item) = $this->procesar($this->corrida([$articulo]), $articulo);

        foreach ($this->consultas_serper as $consulta) {
            $this->assertStringNotContainsString('site:', $consulta);
        }

        $this->assertNull($this->entrada($item, 'sitios_de_referencia'), 'Sin lista, ni siquiera figura como salteado.');
    }

    /**
     * Con sitios pero sin ningún código útil: el plan de sitios figura como salteado y se sigue con el nombre.
     *
     * @group dimension-search
     * @test
     */
    public function con_sitios_y_sin_codigos_el_plan_de_sitios_se_saltea_con_su_motivo()
    {
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['obi.de']);

        $articulo = $this->nuevo_articulo('MANGUERA DE JARDIN VERDE 20 METROS', '12345', ['provider_code' => 'TRANSPORTE']);

        $this->falsear([], [], $this->ficha('otro_producto'));

        list($item) = $this->procesar($this->corrida([$articulo]), $articulo);

        $this->assertSame(['MANGUERA DE JARDIN VERDE 20 METROS ficha técnica peso medidas'], $this->consultas_serper);

        $sitios = $this->entrada($item, 'sitios_de_referencia');
        $this->assertFalse($sitios['usado']);
        $this->assertStringContainsString('sitios de referencia', $sitios['motivo']);
        $this->assertSame('sitios_de_referencia', $item->diagnostico[0]['criterio'], 'Va primero, en el orden de los planes.');
    }

    /**
     * El GTIN sin resultados en Argentina se busca de nuevo fuera (gl=us, hl=en), antes que el código de proveedor.
     *
     * @group dimension-search
     * @test
     */
    public function el_gtin_sin_resultado_en_argentina_se_busca_fuera_con_gl_us()
    {
        $articulo = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M', self::GTIN_OBI, ['provider_code' => 'GA-18000']);

        $this->falsear(
            // Solo la búsqueda de EE. UU. devuelve la ficha (la clave «gl|consulta» la distingue de la argentina).
            ['us|"'.self::GTIN_OBI.'"' => [$this->resultado(self::URL_OBI, 'GARDENA Classic Schlauch')]],
            [self::URL_OBI => $this->pagina('GARDENA Classic Schlauch', 'EAN '.self::GTIN_OBI.'. Weight 2.70 kg Height 9.0 cm Width 38.0 cm Depth 38.0 cm')],
            $this->ficha('exacto', $this->datos_de_la_manguera(self::URL_OBI))
        );

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertSame([
            ['q' => '"'.self::GTIN_OBI.'"', 'gl' => 'ar', 'hl' => 'es'],
            ['q' => '"'.self::GTIN_OBI.'"', 'gl' => 'us', 'hl' => 'en'],
        ], $this->busquedas_hechas());
        $this->assertSame(2, $resultado['busquedas_intentadas']);

        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_DE_BARRAS, $item->criterio_usado);

        $this->assertSame('sin_resultados', $this->entrada($item, 'codigo_de_barras')['resultado']);
        $global = $this->entrada($item, 'codigo_de_barras_global');
        $this->assertSame('datos', $global['resultado']);
        $this->assertSame('us', $global['gl']);
        $this->assertSame('en', $global['hl']);
        $this->assertArrayNotHasKey('gl', $this->entrada($item, 'codigo_de_barras'), 'La argentina va con la región de la configuración.');
    }

    /**
     * Una propuesta sin respaldo que salió de un plan NUEVO guarda en `criterio_usado` uno de los tres de siempre.
     * Antes guardaba el criterio del plan: «codigo_de_barras_global» mide 23 y la columna es string(20); con MySQL
     * estricto el cierre del artículo fallaba y el job lo reprocesaba (gastando Serper e IA) hasta dejarlo en error.
     * Lo encontró la revisión independiente de la misión.
     *
     * @group dimension-search
     * @test
     */
    public function una_propuesta_sin_respaldo_de_los_planes_nuevos_guarda_un_criterio_de_los_de_siempre()
    {
        $articulo = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M', self::GTIN_OBI, ['provider_code' => 'GA-18000']);

        $this->falsear(
            ['us|"'.self::GTIN_OBI.'"' => [$this->resultado(self::URL_OBI, 'GARDENA Classic Schlauch')]],
            [self::URL_OBI => $this->pagina('GARDENA Classic Schlauch', 'EAN '.self::GTIN_OBI.'. Gartenschlauch für den Garten.')],
            // La cita no está en la página: el valor queda como propuesta sin respaldo.
            $this->ficha('exacto', ['peso' => $this->dato(2.7, 'kg', 'Gewicht 2,70 kg', self::URL_OBI)])
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('valor_sin_respaldo', $item->motivo);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_DE_BARRAS, $item->criterio_usado);
        $this->assertEquals(2.7, (float) $item->peso_kg);
        $this->assertSame('sin_medidas', $this->entrada($item, 'codigo_de_barras_global')['resultado']);
    }

    /**
     * Lo mismo en el plan de los sitios de referencia, buscando por el código de proveedor (sin GTIN).
     *
     * @group dimension-search
     * @test
     */
    public function una_propuesta_sin_respaldo_del_plan_de_sitios_por_proveedor_guarda_codigo_proveedor()
    {
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['carbydoctor.com.au']);

        $articulo = $this->nuevo_articulo('CARBURADOR KOHLER SV710', null, ['provider_code' => '32 853 63-S']);
        $url      = 'https://www.carbydoctor.com.au/carburettor-kohler';

        $this->falsear(
            ['("32 853 63-S" OR "3285363S") site:carbydoctor.com.au' => [$this->resultado($url, 'Carburettor Kohler 32 853 63-S')]],
            [$url => $this->pagina('Carburettor Kohler', 'OEM REPLACEMENT: 32 853 63-S. Carburettor for selected Kohler engines.')],
            // La cita no está en la página: propuesta sin respaldo.
            $this->ficha('exacto', ['peso' => $this->dato(750, 'g', 'Shipping Weight 750 g', $url)])
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status, json_encode([$this->consultas_serper, $item->motivo, $item->diagnostico], JSON_UNESCAPED_UNICODE));
        $this->assertSame('valor_sin_respaldo', $item->motivo);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_PROVEEDOR, $item->criterio_usado);
    }

    /**
     * Sin GTIN válido no hay búsqueda global; y el orden completo son cinco planes como mucho.
     *
     * @group dimension-search
     * @test
     */
    public function el_orden_completo_es_sitios_barras_barras_global_proveedor_y_nombre()
    {
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['obi.de']);

        $articulo = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M', self::GTIN_OBI, ['provider_code' => 'GA-18000']);

        $this->falsear([], [], $this->ficha('otro_producto'));

        list($item, $resultado) = $this->buscar($articulo);

        $this->assertSame([
            ['q' => '("'.self::GTIN_OBI.'" OR "GA-18000" OR "GA18000") site:obi.de', 'gl' => 'us', 'hl' => 'en'],
            ['q' => '"'.self::GTIN_OBI.'"', 'gl' => 'ar', 'hl' => 'es'],
            ['q' => '"'.self::GTIN_OBI.'"', 'gl' => 'us', 'hl' => 'en'],
            ['q' => '"GA-18000" MANGUERA GARDENA CLASSIC 13MM 20M', 'gl' => 'ar', 'hl' => 'es'],
            ['q' => 'MANGUERA GARDENA CLASSIC 13MM 20M ficha técnica peso medidas', 'gl' => 'ar', 'hl' => 'es'],
        ], $this->busquedas_hechas());

        $this->assertSame(5, $resultado['busquedas_intentadas']);
        $this->assertSame(['sitios_de_referencia', 'codigo_de_barras', 'codigo_de_barras_global', 'codigo_proveedor', 'nombre'], array_column($item->diagnostico, 'criterio'));

        // Un código de barras interno (no GTIN): ni la búsqueda argentina ni la global.
        $otro = $this->nuevo_articulo('MANGUERA GARDENA CLASSIC 13MM 20M', '12345');
        $this->falsear([], [], $this->ficha('otro_producto'));
        list($item_otro) = $this->buscar($otro);

        $this->assertNull($this->entrada($item_otro, 'codigo_de_barras_global'));
    }

    /**
     * 🔴 Revisión independiente: el plan de sitios BUSCA la forma compacta del código de proveedor («3285363S»), así
     * que la página que la trae tiene que contar como «con el código». Antes quedaba sin_datos / no_coincide.
     *
     * @group dimension-search
     * @test
     */
    public function la_pagina_con_el_codigo_de_proveedor_compacto_cuenta_como_con_el_codigo()
    {
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['meteorpiston.com']);

        $articulo = $this->nuevo_articulo('CARBURADOR KOHLER CH20', null, ['provider_code' => '32 853 63-S']);
        $url      = 'https://meteorpiston.com/p/1';

        $this->falsear(
            ['us|("32 853 63-S" OR "3285363S") site:meteorpiston.com' => [$this->resultado($url, 'Carburetor 3285363S')]],
            [$url => $this->pagina('Carburetor', 'OEM 3285363S. Weight: 1 kg.')],
            $this->ficha('exacto', ['peso' => $this->dato(1, 'kg', 'Weight: 1 kg', $url)])
        );

        list($item) = $this->buscar($articulo);

        $this->assertTrue($this->entrada($item, 'sitios_de_referencia')['paginas'][0]['leida']);
        $this->assertTrue($this->entrada($item, 'sitios_de_referencia')['paginas'][0]['codigo_proveedor']);
        $this->assertSame(DimensionSearchItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('medidas_parciales', $item->motivo);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_PROVEEDOR, $item->criterio_usado);
        $this->assertEquals(1, (float) $item->peso_kg);
    }

    /**
     * Al revés NO: cargado compacto («3285363S»), la página lo escribe con espacios y guion («32 853 63-S»). Para
     * reconocerlo habría que inventar separadores donde el código cargado no los tiene, y eso daba falsos positivos
     * (un código «123456» coincidía con el precio «$123.456,00»; revisión independiente). Es un límite aceptado: la
     * página no cuenta como «con el código».
     *
     * @group dimension-search
     * @test
     */
    public function el_codigo_cargado_compacto_no_se_reconoce_escrito_con_separadores()
    {
        $articulo = $this->nuevo_articulo('CARBURADOR KOHLER CH20', null, ['provider_code' => '3285363S']);
        $url      = 'https://tienda.test/carburador';

        $this->falsear(
            ['"3285363S" CARBURADOR KOHLER CH20' => [$this->resultado($url, 'Carburador')]],
            [$url => $this->pagina('Carburador', 'OEM REPLACEMENT: 32 853 63-S. Weight: 1 kg.')],
            $this->ficha('exacto', ['peso' => $this->dato(1, 'kg', 'Weight: 1 kg', $url)])
        );

        list($item) = $this->buscar($articulo);

        $pagina = $this->entrada($item, 'codigo_proveedor')['paginas'][0];
        $this->assertFalse($pagina['leida']);
        $this->assertSame('La página no contiene el código buscado.', $pagina['motivo']);
        $this->assertSame(DimensionSearchItem::STATUS_SIN_DATOS, $item->status);
    }

    /**
     * Las reglas del reconocimiento del código de proveedor: el literal; la forma compacta exacta (sin ningún
     * separador); o con separadores distintos SOLO donde el código cargado ya tenía uno. Nada pegado antes ni después,
     * y la forma compacta y la de separadores solo para códigos de 6 o más alfanuméricos que parecen un código.
     *
     * @group dimension-search
     * @test
     */
    public function el_codigo_de_proveedor_con_separadores_respeta_los_bordes_y_los_codigos_cortos()
    {
        $p = DimensionPageText::CODIGO_PROVEEDOR;

        foreach (['ABC1234', 'abc 1234', 'ABC/1234', 'Código: ABC-1234.'] as $texto) {
            $this->assertTrue(DimensionPageText::contiene_codigo($texto, 'ABC-1234', $p), $texto);
        }

        // «ABC.12.34»: el código cargado no tiene separador entre «12» y «34» (revisión independiente: antes valía).
        foreach (['XABC1234', 'ABC12345', 'ABC-12345', 'AB-C1234-9', 'ABC_1234', 'ABC.12.34', 'A BC-1234'] as $texto) {
            $this->assertFalse(DimensionPageText::contiene_codigo($texto, 'ABC-1234', $p), $texto);
        }

        foreach (['32 853 63-S', '32-853-63-S', '32.853.63 S', '3285363S', 'OEM 32/853/63/S.'] as $texto) {
            $this->assertTrue(DimensionPageText::contiene_codigo($texto, '32 853 63-S', $p), $texto);
        }

        // Corto (menos de 6 alfanuméricos): solo el literal, como antes.
        $this->assertTrue(DimensionPageText::contiene_codigo('ref AB-12 ok', 'AB-12', $p));
        $this->assertFalse(DimensionPageText::contiene_codigo('ref AB12 ok', 'AB-12', $p));

        // Un código de seis dígitos escrito con puntos donde no los tiene: no (antes valía, revisión independiente).
        $this->assertTrue(DimensionPageText::contiene_codigo('ref 559014 ok', '559014', $p));
        $this->assertFalse(DimensionPageText::contiene_codigo('ref 55.90.14 ok', '559014', $p));
        $this->assertFalse(DimensionPageText::contiene_codigo('ref 12.345 ok', '12345', $p));
    }

    /**
     * 🔴 Revisión independiente: separadores inventados donde el código no los tiene hacían coincidir un código con
     * un precio, una fecha, un teléfono o una potencia.
     *
     * @group dimension-search
     * @test
     */
    public function un_codigo_sin_separadores_no_coincide_con_precios_fechas_ni_telefonos()
    {
        $p = DimensionPageText::CODIGO_PROVEEDOR;

        foreach ([
            ['123456', 'Precio: $123.456,00'],
            ['120345', 'Fecha 12/03/45'],
            ['112345678', 'Tel 11 2345 678'],
            ['MAX1000', 'Potencia MAX 1000 W'],
            ['HP2000', 'Hp 2000 rpm'],
        ] as $caso) {
            $this->assertFalse(DimensionPageText::contiene_codigo($caso[1], $caso[0], $p), $caso[0].' en «'.$caso[1].'»');
        }
    }

    /**
     * De punta a punta: un código de proveedor «123456» y una página que solo trae el precio «$123.456,00» y las
     * cuatro medidas. Antes quedaba asignada sola (codigo_exacto); la página no trae el código.
     *
     * @group dimension-search
     * @test
     */
    public function un_precio_igual_al_codigo_no_asigna_las_medidas()
    {
        $articulo = $this->nuevo_articulo('TALADRO PERCUTOR 750W', null, ['provider_code' => '123456']);
        $url      = 'https://tienda.test/taladro';

        $this->falsear(
            ['"123456" TALADRO PERCUTOR 750W' => [$this->resultado($url, 'Taladro percutor 750W')]],
            [$url => $this->pagina('Taladro percutor 750W', 'Precio: $123.456,00. Peso: 2 kg. Alto: 20 cm. Ancho: 8 cm. Largo: 30 cm.')],
            $this->ficha('exacto', [
                'peso'  => $this->dato(2, 'kg', 'Peso: 2 kg', $url),
                'alto'  => $this->dato(20, 'cm', 'Alto: 20 cm', $url),
                'ancho' => $this->dato(8, 'cm', 'Ancho: 8 cm', $url),
                'largo' => $this->dato(30, 'cm', 'Largo: 30 cm', $url),
            ])
        );

        list($item) = $this->buscar($articulo);

        $this->assertNotSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertFalse($this->entrada($item, 'codigo_proveedor')['paginas'][0]['leida']);
        $this->assertNull($articulo->fresh()->peso);
    }

    /**
     * En el plan de sitios (que busca por el GTIN y el proveedor juntos), el `criterio_usado` de una propuesta sin
     * respaldo sale del código que traía la página de la que salió, no del primer código de la consulta.
     *
     * @group dimension-search
     * @test
     */
    public function la_propuesta_del_plan_de_sitios_guarda_el_criterio_del_codigo_que_traia_la_pagina()
    {
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['verdefacile.eu']);

        $url      = 'https://www.verdefacile.eu/it/catena-42550194950';
        $articulo = $this->nuevo_articulo('CADENA MOTOSIERRA 3/8 50 ESLABONES', self::GTIN_OBI, ['provider_code' => '42550194950']);

        $this->falsear(
            ['("'.self::GTIN_OBI.'" OR "42550194950") site:verdefacile.eu' => [$this->resultado($url, 'Catena 42550194950')]],
            [$url => $this->pagina('Catena motosega', 'Riferimento 42550194950. Catena per motosega.')],
            // La cita no está en la página: propuesta sin respaldo.
            $this->ficha('exacto', ['peso' => $this->dato(4.5, 'kg', 'Peso: 4,5 Kg', $url)])
        );

        list($item) = $this->buscar($articulo);

        $this->assertSame('valor_sin_respaldo', $item->motivo);
        $this->assertSame(DimensionSearchItem::CRITERIO_CODIGO_PROVEEDOR, $item->criterio_usado);
    }
}
