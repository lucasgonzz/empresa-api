<?php

namespace Tests\Feature\ChatIa;

use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116 (rama C, 16/11/2026), ítem M3 — la localidad que no coincide.
 *
 * EL CASO REAL (Pesca, conversación 7, 14/11/2026): el alta de un cliente para un presupuesto no salía porque
 * la localidad dictada («CABA», «Ciudad de Buenos Aires») no coincidía por LIKE con la cargada, y el agente no
 * le listó las opciones al dueño. Lo que protege este archivo:
 *
 *  - un alias conocido («CABA») y un parecido por letras («Rosaio») vuelven como OPCIONES, sin elegirse solos;
 *  - sin nada parecido, vuelven las localidades del negocio y las dos salidas (crearla o seguir sin);
 *  - lo que antes coincidía sigue asignándose igual, y la localidad de otro negocio no aparece.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T171_Localidad_que_no_coincide_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        // En "resuelto" el alta queda como tarjeta.
        $this->dueno->agente_confianza = 'resuelto';
        $this->dueno->save();
    }

    /**
     * @param  string  $nombre
     * @param  int|null  $owner_id
     * @return int
     */
    protected function localidad($nombre, $owner_id = null)
    {
        return (int) DB::table('locations')->insertGetId([
            'name'       => $nombre,
            'user_id'    => is_null($owner_id) ? $this->dueno->id : $owner_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * El alta de un cliente con la localidad dictada, por la herramienta real.
     *
     * @param  string  $localidad
     * @return array
     */
    protected function alta_con_localidad($localidad)
    {
        list($conversation, $assistant) = $this->vuelta('dá de alta a Juan Pérez de ' . $localidad);

        return $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'client',
            'datos'   => ['name' => 'ZZC171 Juan Perez', 'location_id' => $localidad],
        ]);
    }

    /**
     * 🔴 El caso de Pesca: «CABA» ofrece la «Ciudad Autónoma de Buenos Aires» del negocio, sin elegirla sola.
     *
     * @test
     */
    public function caba_ofrece_la_ciudad_autonoma_sin_elegirla()
    {
        $id = $this->localidad('Ciudad Autónoma de Buenos Aires ZZ171');
        $this->localidad('Rosario ZZ171');

        $r = $this->alta_con_localidad('CABA');

        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertNotEmpty($r['faltan']);
        $this->assertSame([$id], array_column($r['opciones']['location_id'], 'id'));
        $this->assertStringContainsString('proponer_alta de location', $r['opciones']['si_no_es_ninguna']);
    }

    /**
     * Un parecido por letras («Rosaio») también vuelve como opción.
     *
     * @test
     */
    public function un_parecido_por_letras_vuelve_como_opcion()
    {
        $id = $this->localidad('Rosario ZZ171');

        $r = $this->alta_con_localidad('Rosaio ZZ171');

        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertContains($id, array_column($r['opciones']['location_id'], 'id'));
    }

    /**
     * Sin nada parecido: las localidades del negocio (no las de otro) y las dos salidas.
     *
     * @test
     */
    public function sin_parecidas_vuelven_todas_las_del_negocio_y_las_dos_salidas()
    {
        $rosario = $this->localidad('Rosario ZZ171');
        $funes = $this->localidad('Funes ZZ171');

        $otro = \App\Models\User::create(['name' => 'Otro ZZ171', 'email' => 'otro-zz171-' . uniqid() . '@test.local', 'password' => 'x']);
        $ajena = $this->localidad('Tandil ZZ171', $otro->id);

        $r = $this->alta_con_localidad('Tandil ZZ171');

        $this->assertFalse($r['ok'], json_encode($r));

        $ids = array_column($r['opciones']['location_id'], 'id');

        $this->assertContains($rosario, $ids);
        $this->assertContains($funes, $ids);
        $this->assertNotContains($ajena, $ids, 'La localidad de otro negocio no existe para éste.');
        $this->assertStringContainsString('seguir sin localidad', $r['opciones']['si_no_esta']);
        $this->assertStringContainsString('proponer_alta de location', $r['opciones']['si_no_esta']);
    }

    /**
     * Lo que coincidía se sigue asignando igual.
     *
     * @test
     */
    public function la_que_coincide_se_asigna_como_siempre()
    {
        $id = $this->localidad('Rosario ZZ171');

        $r = $this->alta_con_localidad('rosario zz171');

        $this->assertTrue($r['ok'], json_encode($r));

        $payload = json_decode(json_encode(\App\Models\AiMessageAction::find($r['tarjeta_id'])->datos), true)['payload'];

        $this->assertSame($id, (int) $payload['location_id']);
    }
}
