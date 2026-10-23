<?php

namespace Tests\Feature\SucursalSiempre;

use App\Models\Address;
use Illuminate\Support\Facades\DB;

/**
 * D2 de la misión sucursal-siempre: la sucursal PRINCIPAL (`default_address = 1`) es una sola por
 * dueño; `Address::principal_de()` resuelve marcada → madre → menor id; la migración de datos deja
 * exactamente una marcada (solo marca, no crea).
 */
class Sucursal_principal_Test extends SucursalSiempreTestCase
{
    /**
     * @test
     */
    public function marcar_una_principal_desmarca_las_otras_del_mismo_dueno()
    {
        $dueno = $this->dueno_nuevo('marca');
        $otro  = $this->dueno_nuevo('otro');

        $a = Address::create(['street' => 'zz A', 'user_id' => $dueno->id, 'default_address' => 1]);
        $b = Address::create(['street' => 'zz B', 'user_id' => $dueno->id, 'default_address' => 1]);
        $ajena = Address::create(['street' => 'zz Ajena', 'user_id' => $otro->id, 'default_address' => 1]);

        $this->assertSame(0, (int) $a->fresh()->default_address, 'Marcar B tenía que desmarcar A.');
        $this->assertSame(1, (int) $b->fresh()->default_address);
        $this->assertSame(1, (int) $ajena->fresh()->default_address, 'La de otro comercio no se toca.');

        $this->assertSame($b->id, Address::principal_de($dueno->id)->id);
    }

    /**
     * @test
     */
    public function un_domicilio_de_comprador_nunca_es_principal()
    {
        $comprador = Address::create(['street' => 'zz Domicilio', 'user_id' => null, 'buyer_id' => 987654, 'default_address' => 1]);

        $this->assertSame(0, (int) $comprador->fresh()->default_address);
    }

    /**
     * @test
     */
    public function sin_marca_la_principal_es_la_madre_y_si_no_la_de_menor_id()
    {
        $dueno = $this->dueno_nuevo('orden');

        $this->assertNull(Address::principal_de($dueno->id), 'Sin sucursales no hay principal.');

        $primera = Address::create(['street' => 'zz Primera', 'user_id' => $dueno->id]);
        $segunda = Address::create(['street' => 'zz Segunda', 'user_id' => $dueno->id]);

        $this->assertSame($primera->id, Address::principal_de($dueno->id)->id, 'Sin marca ni madre: la de menor id.');

        if (Address::columna_madre_existe()) {
            $segunda->es_deposito_madre = 1;
            $segunda->save();

            $this->assertSame($segunda->id, Address::principal_de($dueno->id)->id, 'Sin marca: la madre.');
        }

        $primera->default_address = 1;
        $primera->save();

        $this->assertSame($primera->id, Address::principal_de($dueno->id)->id, 'La marcada gana.');
    }

    /**
     * @test
     */
    public function una_sola_sucursal_se_reconoce_y_dos_no()
    {
        $dueno = $this->dueno_nuevo('unica');

        $this->assertNull(Address::unica_sucursal_de($dueno->id));

        $a = Address::create(['street' => 'zz Unica', 'user_id' => $dueno->id]);

        // Un domicilio de comprador no cuenta como sucursal.
        Address::create(['street' => 'zz Comprador', 'user_id' => $dueno->id, 'buyer_id' => 123]);

        $this->assertSame($a->id, Address::unica_sucursal_de($dueno->id)->id);

        Address::create(['street' => 'zz Otra', 'user_id' => $dueno->id]);

        $this->assertNull(Address::unica_sucursal_de($dueno->id));
    }

    /**
     * La migración de datos: sin marca → madre o menor id; varias marcadas (dato viejo, por SQL) → la
     * de menor id; una sola → no se toca. Idempotente.
     *
     * @test
     */
    public function la_migracion_de_datos_deja_exactamente_una_marcada()
    {
        $sin_marca = $this->dueno_nuevo('sin-marca');
        $varias    = $this->dueno_nuevo('varias');
        $una       = $this->dueno_nuevo('una');

        $s1 = Address::create(['street' => 'zz S1', 'user_id' => $sin_marca->id]);
        $s2 = Address::create(['street' => 'zz S2', 'user_id' => $sin_marca->id]);

        $v1 = Address::create(['street' => 'zz V1', 'user_id' => $varias->id]);
        $v2 = Address::create(['street' => 'zz V2', 'user_id' => $varias->id]);
        // Dato viejo: dos marcadas, escritas por SQL (el hook del modelo ya no deja).
        DB::table('addresses')->whereIn('id', [$v1->id, $v2->id])->update(['default_address' => 1]);

        $u1 = Address::create(['street' => 'zz U1', 'user_id' => $una->id]);
        $u2 = Address::create(['street' => 'zz U2', 'user_id' => $una->id, 'default_address' => 1]);

        if (!class_exists('MarcarSucursalPrincipal', false)) {
            require_once base_path('database/migrations/2026_10_23_120000_marcar_sucursal_principal.php');
        }

        $migracion = new \MarcarSucursalPrincipal();

        $migracion->up();
        $migracion->up();

        $marcadas = function ($user_id) {
            return DB::table('addresses')->where('user_id', $user_id)->where('default_address', 1)->pluck('id')->all();
        };

        $this->assertEquals([$s1->id], $marcadas($sin_marca->id), 'Sin marca: la de menor id.');
        $this->assertEquals([$v1->id], $marcadas($varias->id), 'Varias marcadas: queda la de menor id.');
        $this->assertEquals([$u2->id], $marcadas($una->id), 'Una marcada: no se toca.');
    }
}
