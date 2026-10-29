<?php

namespace Tests\Feature\CuentaCorriente;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Preferencia_de_consulta_Test extends TestCase
{
    use DatabaseTransactions;

    protected $dueno;
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertMatchesRegularExpression('/^empresa_testing(?:_s\d+)?$/', DB::connection()->getDatabaseName());
        $this->dueno = User::create(['name' => 'Preferencia dueño', 'email' => uniqid().'@test.local', 'password' => 'prueba']);
        $this->empleado = User::create(['name' => 'Preferencia empleado', 'email' => uniqid().'@test.local', 'password' => 'prueba', 'owner_id' => $this->dueno->id]);
    }

    protected function guardar(array $consulta)
    {
        return $this->putJson('api/user/current-acount/consulta', $consulta);
    }

    public function test_guarda_por_persona_y_restaura_en_get_user_sin_tocar_saldos()
    {
        $antes = DB::table('current_acounts')->count();
        $saldos = DB::table('current_acounts')->orderBy('id')->pluck('saldo', 'id')->all();
        $cuentas = DB::table('credit_accounts')->orderBy('id')->pluck('saldo', 'id')->all();
        $this->actingAs($this->empleado, 'web');
        $consulta = ['modo' => 'ultimos', 'clave' => 'ultimos', 'desde' => null, 'hasta' => null, 'cantidad' => 37];
        $this->guardar($consulta + ['user_id' => $this->dueno->id, 'credit_account_id' => 999])
            ->assertStatus(200)->assertJson(['current_acount_consulta' => $consulta]);
        $this->assertSame($consulta, $this->empleado->fresh()->current_acount_consulta);
        $this->assertNull($this->dueno->fresh()->current_acount_consulta);
        $this->getJson('api/user')->assertStatus(200)->assertJsonPath('user.current_acount_consulta.cantidad', 37);
        $this->assertSame($antes, DB::table('current_acounts')->count());
        $this->assertSame($saldos, DB::table('current_acounts')->orderBy('id')->pluck('saldo', 'id')->all());
        $this->assertSame($cuentas, DB::table('credit_accounts')->orderBy('id')->pluck('saldo', 'id')->all());
    }

    public function test_los_atajos_guardan_clave_sin_congelar_fechas()
    {
        $this->actingAs($this->dueno, 'web');
        $this->guardar(['modo' => 'atajo', 'clave' => 'ultimos_3_meses', 'cantidad' => 10, 'desde' => '2020-01-01', 'hasta' => '2020-03-01'])
            ->assertStatus(200)->assertJsonPath('current_acount_consulta.desde', null)->assertJsonPath('current_acount_consulta.hasta', null);
        $this->assertSame('ultimos_3_meses', $this->dueno->fresh()->current_acount_consulta['clave']);
    }

    public function test_fechas_personalizadas_y_cantidad_permanecen_entre_lecturas()
    {
        $this->actingAs($this->dueno, 'web');
        $consulta = ['modo' => 'personalizado', 'clave' => 'personalizado', 'desde' => '2024-02-29', 'hasta' => '2024-03-31', 'cantidad' => 2000];
        $this->guardar($consulta)->assertStatus(200);
        $this->assertSame($consulta['desde'], $this->dueno->fresh()->current_acount_consulta['desde']);
        $this->getJson('api/user')->assertStatus(200)->assertJsonPath('user.current_acount_consulta.hasta', '2024-03-31');
        $this->flushSession();
        $this->actingAs($this->empleado, 'web');
        // El login real reemplaza las cachés de persona/dueño; actingAs solo cambia el guard.
        \App\Http\Controllers\Helpers\UserHelper::set_sessions($this->empleado);
        $this->getJson('api/user')->assertStatus(200)->assertJsonPath('user.current_acount_consulta', null);
    }

    public function test_rechaza_consultas_invalidas_sin_sobrescribir_la_preferencia()
    {
        $this->actingAs($this->dueno, 'web');
        $valida = ['modo' => 'ultimos', 'clave' => 'ultimos', 'cantidad' => 10];
        $this->guardar($valida)->assertStatus(200);
        $esperada = $this->dueno->fresh()->current_acount_consulta;
        $invalidas = [
            ['cantidad' => 0], ['cantidad' => 2001], ['cantidad' => 3.2], ['cantidad' => 'abc'],
            ['modo' => 'inventado'], ['clave' => 'este_mes'], ['modo' => 'atajo', 'clave' => 'inventado'],
            ['modo' => 'personalizado', 'clave' => 'personalizado', 'desde' => '2026-02-30', 'hasta' => '2026-03-01'],
            ['modo' => 'personalizado', 'clave' => 'personalizado', 'desde' => '2026-04-02', 'hasta' => '2026-04-01'],
            ['modo' => 'personalizado', 'clave' => 'personalizado', 'desde' => '1999-12-31', 'hasta' => '2026-04-01'],
            ['modo' => 'personalizado', 'clave' => 'personalizado', 'desde' => '2026-04-01', 'hasta' => '2100-01-01'],
        ];
        foreach ($invalidas as $invalida) {
            $this->guardar(array_merge($valida, $invalida))->assertStatus(422);
            $this->assertSame($esperada, $this->dueno->fresh()->current_acount_consulta);
        }
    }

    public function test_sin_sesion_no_puede_guardar()
    {
        $this->guardar(['modo' => 'defecto', 'clave' => 'defecto', 'cantidad' => 10])->assertStatus(401);
    }
}
