<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfColumnProfileHelper;
use App\Http\Controllers\Helpers\PdfColumnRemitoSetupHelper;
use App\Http\Controllers\Helpers\Seeders\PdfColumnProfileSeederHelper;
use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Services\PdfColumnService;
use Database\Seeders\PdfColumnProfileSeeder;
use Database\Seeders\PdfColumnSinPreciosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los perfiles de PDF "Remito" y "Sin Precios" tienen que sumar el ancho útil de la hoja
 * (imprimible - 2 x margen): misión perfiles-remito-ancho-completo (Quino2, 30/9/2026: el "Sin
 * Precios" de un cliente sumaba 140 de 200 mm y la tabla quedaba a dos tercios del ancho).
 *
 * DatabaseTransactions contra la base del slot: cada test arma sus perfiles y todo se deshace.
 * No se instancia NewSalePdf (hace `exit` en el constructor); el ancho que se verifica es el de
 * las columnas visibles, que es lo que ese PDF imprime tal cual.
 *
 * @group pdf-perfiles-remito
 */
class Perfiles_de_remito_ancho_completo_Test extends TestCase
{
    use DatabaseTransactions;

    /** Dueño de los tests (mismo criterio que el resto de tests/Feature/Pdf). */
    const OWNER_ID = 500;

    protected function setUp(): void
    {
        parent::setUp();

        if (is_null(User::whereNull('owner_id')->where('id', self::OWNER_ID)->first())) {
            $this->markTestSkipped('La base de testing no tiene el dueño 500 sembrado.');
        }

        PdfColumnService::sync_catalog_options('sale');

        /** Arrancar sin ningún perfil de venta no fiscal del dueño: cada test arma los suyos. */
        PdfColumnProfile::where('user_id', self::OWNER_ID)->where('model_name', 'sale')->delete();
    }

    /**
     * Perfil de venta con las columnas dadas (nombre => ancho); las que no se nombran quedan ocultas.
     *
     * @param  string $name
     * @param  array  $anchos  [['Nombre del artículo', 72], ...] en orden de impresión.
     * @param  array  $attrs
     * @return \App\Models\PdfColumnProfile
     */
    protected function perfil($name, array $anchos, array $attrs = [])
    {
        $perfil = PdfColumnProfile::create(array_merge([
            'user_id' => self::OWNER_ID,
            'model_name' => 'sale',
            'name' => $name,
            'is_default' => false,
            'paper_width_mm' => 210,
            'printable_width_mm' => 210,
            'margin_mm' => 5,
            'is_afip_ticket' => false,
            'show_totals_on_each_page' => false,
            'columns' => [],
        ], $attrs));

        $definicion = [];
        foreach ($anchos as $par) {
            $definicion[] = ['name' => $par[0], 'width' => $par[1]];
        }
        PdfColumnProfileSeederHelper::assign_profile_options($perfil, 'sale', $definicion);

        return $perfil->fresh();
    }

    /** Ancho de cada columna visible, en orden, como "label=ancho". */
    protected function anchos(PdfColumnProfile $perfil)
    {
        $out = [];
        foreach (PdfColumnProfileHelper::columnas_visibles($perfil->fresh()) as $fila) {
            $out[$fila['nombre']] = $fila['ancho_mm'];
        }

        return $out;
    }

    /** Columnas del Sin Precios tal como las dejó el catálogo (Quino2): suman 140. */
    protected function sin_precios_quino()
    {
        return [
            ['Índice de fila', 8], ['Número de artículo', 15], ['Código de barras', 30],
            ['Nombre del artículo', 72], ['Cantidad', 15],
        ];
    }

    /** @test */
    public function la_definicion_de_remito_suma_exactamente_el_ancho_util()
    {
        $suma = array_sum(array_column(PdfColumnRemitoSetupHelper::visible_columns_definition(PdfColumnRemitoSetupHelper::REMITO), 'width'));

        $this->assertSame(200, $suma);
    }

    /** @test */
    public function la_definicion_de_sin_precios_suma_exactamente_el_ancho_util_y_el_nombre_se_lleva_132()
    {
        $definicion = PdfColumnRemitoSetupHelper::visible_columns_definition(PdfColumnRemitoSetupHelper::SIN_PRECIOS);

        $this->assertSame(200, array_sum(array_column($definicion, 'width')));
        $por_nombre = array_column($definicion, 'width', 'name');
        $this->assertSame(132, $por_nombre['Nombre del artículo']);
    }

    /** @test */
    public function la_definicion_respeta_un_ancho_util_distinto()
    {
        $definicion = PdfColumnRemitoSetupHelper::visible_columns_definition(PdfColumnRemitoSetupHelper::SIN_PRECIOS, 190);

        $this->assertSame(190, array_sum(array_column($definicion, 'width')));
    }

    /** @test */
    public function el_sin_precios_de_quino_pasa_de_140_a_200_y_solo_cambia_el_nombre()
    {
        $perfil = $this->perfil('Sin Precios', $this->sin_precios_quino());
        $this->assertSame(140, PdfColumnProfileHelper::suma_de_anchos_mm($perfil));

        PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        $this->assertSame(200, PdfColumnProfileHelper::suma_de_anchos_mm($perfil->fresh()));
        $this->assertSame([
            'Índice de fila' => 8, 'Número de artículo' => 15, 'Código de barras' => 30,
            'Nombre del artículo' => 132, 'Cantidad' => 15,
        ], $this->anchos($perfil));
    }

    /** @test */
    public function un_remito_que_se_pasa_de_ancho_se_achica_al_util()
    {
        $perfil = $this->perfil('Remito', [
            ['Índice de fila', 8], ['Número de artículo', 15], ['Código de barras', 30], ['Nombre del artículo', 72],
            ['Cantidad', 15], ['Precio unitario', 28], ['Bonificación porcentaje', 13], ['Subtotal línea', 32],
        ]);
        $this->assertSame(213, PdfColumnProfileHelper::suma_de_anchos_mm($perfil));

        PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        $this->assertSame(200, PdfColumnProfileHelper::suma_de_anchos_mm($perfil->fresh()));
        $this->assertSame(59, $this->anchos($perfil)['Nombre del artículo']);
    }

    /** @test */
    public function el_espejo_columns_del_perfil_queda_igual_al_pivot()
    {
        $perfil = $this->perfil('Sin Precios', $this->sin_precios_quino());

        PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        $espejo = collect($perfil->fresh()->columns)->where('visible', true)->pluck('width', 'name')->all();
        $this->assertSame(132, $espejo['Nombre del artículo']);
        $this->assertSame(200, array_sum($espejo));
    }

    /** @test */
    public function una_segunda_pasada_no_encuentra_nada_que_ajustar()
    {
        $this->perfil('Sin Precios', $this->sin_precios_quino());

        PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);
        $segunda = PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        foreach ($segunda['perfiles'] as $fila) {
            $this->assertSame('sin_cambios', $fila['accion'], $fila['nombre']);
        }
    }

    /** @test */
    public function crea_los_dos_perfiles_cuando_faltan_y_los_dos_suman_el_ancho_util()
    {
        $resultado = PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        $this->assertSame(['creado', 'creado'], array_column($resultado['perfiles'], 'accion'));

        foreach (['Remito', 'Sin Precios'] as $nombre) {
            $perfil = PdfColumnProfile::where('user_id', self::OWNER_ID)->where('model_name', 'sale')->where('name', $nombre)->first();
            $this->assertNotNull($perfil, "Falta el perfil {$nombre}.");
            $this->assertFalse((bool) $perfil->is_afip_ticket);
            $this->assertFalse((bool) $perfil->is_default);
            $this->assertSame(210, (int) $perfil->paper_width_mm);
            $this->assertSame(5, (int) $perfil->margin_mm);
            $this->assertSame(200, PdfColumnProfileHelper::suma_de_anchos_mm($perfil), $nombre);
        }
    }

    /** @test */
    public function el_dry_run_no_escribe_nada()
    {
        $perfil = $this->perfil('Sin Precios', $this->sin_precios_quino());
        $antes = PdfColumnProfile::where('user_id', self::OWNER_ID)->count();

        $resultado = PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID, true);

        $this->assertSame(['creado', 'ajustado'], array_column($resultado['perfiles'], 'accion'));
        $this->assertSame($antes, PdfColumnProfile::where('user_id', self::OWNER_ID)->count());
        $this->assertSame(140, PdfColumnProfileHelper::suma_de_anchos_mm($perfil->fresh()));
    }

    /** @test */
    public function respeta_el_margen_propio_del_perfil()
    {
        $perfil = $this->perfil('Sin Precios', $this->sin_precios_quino(), ['margin_mm' => 10]);

        PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        $this->assertSame(190, PdfColumnProfileHelper::suma_de_anchos_mm($perfil->fresh()));
    }

    /** @test */
    public function no_toca_a_los_fiscales_ni_a_otros_perfiles_de_remito()
    {
        $costos = $this->perfil('Remito costos', $this->sin_precios_quino());
        $fiscal = $this->perfil('Remito', $this->sin_precios_quino(), ['is_afip_ticket' => true]);

        PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        $this->assertSame(140, PdfColumnProfileHelper::suma_de_anchos_mm($costos->fresh()));
        $this->assertSame(140, PdfColumnProfileHelper::suma_de_anchos_mm($fiscal->fresh()));
    }

    /** @test */
    public function reconoce_el_nombre_sin_importar_mayusculas()
    {
        $perfil = $this->perfil('SIN PRECIOS', $this->sin_precios_quino());

        $resultado = PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        $this->assertSame(200, PdfColumnProfileHelper::suma_de_anchos_mm($perfil->fresh()));
        $this->assertCount(2, $resultado['perfiles'], 'No debe crear un segundo "Sin Precios".');
    }

    /** @test */
    public function no_pisa_las_demas_propiedades_del_perfil()
    {
        $perfil = $this->perfil('Sin Precios', $this->sin_precios_quino(), [
            'is_default' => true,
            'is_default_whatsapp' => true,
            'footer_text' => 'Gracias por su compra',
            'show_total_in_footer' => false,
        ]);

        PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        $recargado = $perfil->fresh();
        $this->assertTrue((bool) $recargado->is_default);
        $this->assertTrue((bool) $recargado->is_default_whatsapp);
        $this->assertSame('Gracias por su compra', $recargado->footer_text);
        $this->assertFalse((bool) $recargado->show_total_in_footer);
    }

    /** @test */
    public function si_el_nombre_quedaria_muy_angosto_reparte_en_proporcion_y_cierra_igual()
    {
        /** Suma 258 (58 mm de más): absorberlos dejaría al Nombre en 40 - 58 < 30, así que se escala todo. */
        $perfil = $this->perfil('Sin Precios', [
            ['Índice de fila', 8], ['Número de artículo', 80], ['Código de barras', 90],
            ['Nombre del artículo', 40], ['Cantidad', 40],
        ]);
        $this->assertSame(258, PdfColumnProfileHelper::suma_de_anchos_mm($perfil));

        PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        $this->assertSame(200, PdfColumnProfileHelper::suma_de_anchos_mm($perfil->fresh()));
        foreach ($this->anchos($perfil) as $nombre => $ancho) {
            $this->assertGreaterThanOrEqual(1, $ancho, $nombre);
        }
    }

    /** @test */
    public function sin_columna_nombre_visible_el_resto_se_reparte_y_cierra_en_el_util()
    {
        $perfil = $this->perfil('Sin Precios', [
            ['Índice de fila', 8], ['Código de barras', 30], ['Cantidad', 15],
        ]);

        PdfColumnRemitoSetupHelper::apply_for_owner(self::OWNER_ID);

        $this->assertSame(200, PdfColumnProfileHelper::suma_de_anchos_mm($perfil->fresh()));
    }

    /** @test */
    public function los_seeders_dejan_remito_y_sin_precios_sumando_200()
    {
        config(['app.USER_ID' => self::OWNER_ID]);

        (new PdfColumnProfileSeeder())->run();
        (new PdfColumnSinPreciosSeeder())->run();

        foreach (['Remito', 'Sin Precios'] as $nombre) {
            $perfil = PdfColumnProfile::where('user_id', self::OWNER_ID)->where('model_name', 'sale')->where('name', $nombre)->first();
            $this->assertNotNull($perfil, $nombre);
            $this->assertSame(200, PdfColumnProfileHelper::suma_de_anchos_mm($perfil), $nombre);
        }
    }

    /**
     * El alta de un cliente nuevo (real, demo y DatabaseSeeder) tiene que sembrar "Sin Precios":
     * hasta el 30/9/2026 el seeder existía pero no lo llamaba nadie. Se lee el código fuente porque
     * UserSetupHelper::run() y DemoSetupHelper::run() arrancan con migrate:fresh y vaciarían la base
     * del slot (mismo motivo que Preferencias/5_Default_pdf_a4_en_setup_Test).
     *
     * @test
     */
    public function el_alta_de_un_cliente_nuevo_siembra_sin_precios()
    {
        foreach ([
            'app/Http/Controllers/Helpers/UserSetupHelper.php' => "'PdfColumnSinPreciosSeeder'",
            'app/Http/Controllers/Helpers/DemoSetupHelper.php' => "'PdfColumnSinPreciosSeeder'",
            'database/seeders/DatabaseSeeder.php' => 'PdfColumnSinPreciosSeeder::class',
        ] as $archivo => $llamada) {
            $fuente = file_get_contents(base_path($archivo));
            $this->assertNotFalse(strpos($fuente, $llamada), "{$archivo} no siembra PdfColumnSinPreciosSeeder.");
            $this->assertLessThan(
                strpos($fuente, $llamada),
                strpos($fuente, 'PdfColumnProfileSeeder'),
                "{$archivo}: Sin Precios tiene que ir después de PdfColumnProfileSeeder."
            );
        }
    }
}
