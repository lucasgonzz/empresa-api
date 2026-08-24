<?php

namespace Tests\Feature\Preferencias;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Models\PdfColumnProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Default de impresión de la factura ARCA para usuarios nuevos (mision
 * mejora-visual-factura-ventas, 19/8/2026): al terminar el setup (real o demo), el owner queda
 * con `sale_factura_print_option` apuntando al perfil PdfColumnProfile "Factura comun" (PDF A4
 * fiscal) en vez de quedar en null (ticket común, el comportamiento de antes).
 *
 * No se llama a UserSetupHelper::run() ni a DemoSetupHelper::run(): las dos arrancan con
 * `migrate:fresh`, que vaciaría empresa_testing_s1, la base sembrada de la que vive el resto de
 * la suite del slot (mismo motivo que documenta AvisoDeSetupCompletadoTest en
 * tests/Feature/Demo/). Lo que se ejercita es el método privado nuevo por reflexión — es la
 * misma pieza que run() invoca, aislada del resto del setup.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes.
 */
class Default_pdf_a4_en_setup_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario dueño de los tests de esta rama (mismo patrón que el resto de Preferencias).
     *
     * @return \App\Models\User
     */
    protected function owner()
    {
        $owner = User::find(500);
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        return $owner;
    }

    /**
     * Crea el perfil "Factura comun" (A4 fiscal) que PdfColumnProfileSeeder siembra en un setup
     * real, con los mismos campos que usa ese seeder.
     *
     * @param int $owner_id
     * @return \App\Models\PdfColumnProfile
     */
    protected function crear_perfil_factura_comun($owner_id)
    {
        return PdfColumnProfile::create([
            'user_id'                  => $owner_id,
            'model_name'               => 'sale',
            'name'                     => 'Factura comun',
            'is_afip_ticket'           => true,
            'is_default'               => false,
            'paper_width_mm'           => 210,
            'printable_width_mm'       => 210,
            'margin_mm'                => 5,
            'show_totals_on_each_page' => false,
            'columns'                  => [],
        ]);
    }

    /**
     * Invoca el método privado set_default_sale_factura_print_option del helper dado.
     *
     * @param string $helper_class UserSetupHelper::class o DemoSetupHelper::class
     * @param \App\Models\User $user
     * @return void
     */
    protected function invocar($helper_class, $user)
    {
        $metodo = new \ReflectionMethod($helper_class, 'set_default_sale_factura_print_option');
        $metodo->setAccessible(true);
        $metodo->invoke(null, $user);
    }

    /**
     * @group preferencias
     * @test
     */
    public function user_setup_helper_deja_el_owner_apuntando_al_perfil_factura_comun()
    {
        $owner = $this->owner();
        $owner->sale_factura_print_option = null;
        $owner->save();

        $perfil = $this->crear_perfil_factura_comun($owner->id);

        $this->invocar(UserSetupHelper::class, $owner);

        $owner->refresh();

        $this->assertSame('factura_a4:' . $perfil->id, $owner->sale_factura_print_option);
    }

    /**
     * @group preferencias
     * @test
     */
    public function demo_setup_helper_deja_el_mismo_default_que_user_setup_helper()
    {
        $owner = $this->owner();
        $owner->sale_factura_print_option = null;
        $owner->save();

        $perfil = $this->crear_perfil_factura_comun($owner->id);

        $this->invocar(DemoSetupHelper::class, $owner);

        $owner->refresh();

        $this->assertSame('factura_a4:' . $perfil->id, $owner->sale_factura_print_option);
    }

    /**
     * Sin el perfil "Factura comun" sembrado, no hay nada válido para apuntar: la preferencia
     * queda como estaba (null = ticket común) en vez de guardar una referencia inexistente.
     *
     * @group preferencias
     * @test
     */
    public function sin_perfil_factura_comun_no_toca_la_preferencia()
    {
        $owner = $this->owner();
        $owner->sale_factura_print_option = null;
        $owner->save();

        PdfColumnProfile::where('user_id', $owner->id)
            ->where('model_name', 'sale')
            ->where('is_afip_ticket', true)
            ->delete();

        $this->invocar(UserSetupHelper::class, $owner);

        $owner->refresh();

        $this->assertNull($owner->sale_factura_print_option);
    }
}
