<?php

namespace Tests\Feature\Pdf;

use App\Models\PdfColumnProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests del flag show_client_description: si el cliente de la venta tiene
 * observaciones cargadas, hasta ahora se imprimían siempre en el PDF sin que ningún
 * perfil pudiera apagarlas. Ver plan de la misión "observaciones-cliente-opcional-pdf".
 *
 * Lo que se testea es la PERSISTENCIA del flag (migración + cast) y que la lectura en
 * NewSalePdf esté correctamente gateada, leyendo el código fuente. No se instancia
 * NewSalePdf: las clases de Pdf/ hacen `require` de fpdf.php (sin `_once`) y terminan su
 * constructor con `$this->Output(); exit;`, así que instanciar cualquiera de ellas desde
 * PHPUnit mata el proceso o revienta con "Constant FPDF_VERSION already defined" si ya
 * se cargó otra en el mismo proceso (mismo criterio que 2_Logo_Por_Sucursal_Test.php).
 *
 * @group pdf-observaciones-cliente
 */
class Observaciones_del_cliente_opcionales_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Perfil de venta de mentira, con las columnas mínimas que exige la tabla.
     *
     * @param array $overrides
     * @return \App\Models\PdfColumnProfile
     */
    protected function crear_perfil(array $overrides = [])
    {
        return PdfColumnProfile::create(array_merge([
            'user_id' => 500,
            'model_name' => 'sale',
            'name' => 'zz Perfil de test observaciones',
            'columns' => [],
        ], $overrides));
    }

    /**
     * Un perfil creado sin declarar el flag tiene que quedar en true: es el default de la
     * migración y lo que mantiene el comportamiento de los perfiles que ya existen en
     * producción (las observaciones se seguían imprimiendo siempre).
     *
     * @test
     */
    public function un_perfil_nuevo_sin_declarar_el_flag_muestra_las_observaciones_por_default()
    {
        $perfil = $this->crear_perfil();

        $this->assertTrue(
            (bool) $perfil->fresh()->show_client_description,
            'El default de la migración tiene que ser true para no romper perfiles existentes.'
        );
    }

    /**
     * El flag persiste en false y se relee como false, casteado a boolean.
     *
     * @test
     */
    public function el_flag_en_false_persiste_y_se_relee_como_false()
    {
        $perfil = $this->crear_perfil(['show_client_description' => false]);

        $recargado = PdfColumnProfile::find($perfil->id);

        $this->assertFalse((bool) $recargado->show_client_description);
        $this->assertIsBool($recargado->show_client_description, 'La columna tiene que castear a boolean.');
    }

    /**
     * El flag en true persiste igual, explícito.
     *
     * @test
     */
    public function el_flag_en_true_persiste_y_se_relee_como_true()
    {
        $perfil = $this->crear_perfil(['show_client_description' => true]);

        $recargado = PdfColumnProfile::find($perfil->id);

        $this->assertTrue((bool) $recargado->show_client_description);
    }

    /**
     * El bloque de observaciones del cliente en NewSalePdf::Header() tiene que estar
     * gateado por show_client_description, además de las condiciones de siempre (venta
     * con cliente y cliente con descripción). Sin este gate, el flag persistiría en la
     * base sin tener ningún efecto sobre el PDF real.
     *
     * @test
     */
    public function el_header_del_pdf_gatea_las_observaciones_del_cliente_con_el_flag()
    {
        $codigo = file_get_contents(app_path('Http/Controllers/Pdf/NewSalePdf.php'));

        $this->assertStringContainsString(
            '$this->show_client_description',
            $codigo,
            'NewSalePdf tiene que leer show_client_description en algún lado.'
        );

        // El if que envuelve PdfHelper::client_description() tiene que empezar chequeando el flag.
        $this->assertMatchesRegularExpression(
            '/if \(\s*\$this->show_client_description\s*&&\s*!is_null\(\$this->sale->client\)/',
            $codigo,
            'El bloque que imprime PdfHelper::client_description() tiene que estar gateado por '
                .'show_client_description, antes de chequear si la venta tiene cliente.'
        );
    }

    /**
     * El constructor inicializa el flag con default true cuando el perfil no lo tiene
     * (perfiles vivos hoy en producción, creados antes de esta migración) y también
     * cuando no hay perfil en absoluto (pdf_column_profile_id inválido).
     *
     * @test
     */
    public function el_constructor_default_a_true_cuando_no_hay_perfil_o_el_perfil_no_declara_el_flag()
    {
        $codigo = file_get_contents(app_path('Http/Controllers/Pdf/NewSalePdf.php'));

        $this->assertStringContainsString(
            "\$this->normalize_boolean(\$this->pdf_column_profile->show_client_description, true)",
            $codigo,
            'La lectura tiene que usar normalize_boolean con default true, igual que los demás flags '
                .'de este perfil (show_total_in_footer, show_subtotal_in_footer).'
        );
    }
}
