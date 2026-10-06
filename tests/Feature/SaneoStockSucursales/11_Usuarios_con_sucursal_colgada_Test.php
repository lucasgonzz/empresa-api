<?php

namespace Tests\Feature\SaneoStockSucursales;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * `users.address_id` COLGADO: se REPORTA y no se toca (misión sanear-stock-de-sucursales-borradas,
 * 6/10/2026).
 *
 * La causa de que los fantasmas vuelvan a nacer es un `users.address_id` que apunta a una sucursal
 * borrada: cada venta de ese usuario manda el id muerto y el motor reabre la fila. El saneo borra
 * los fantasmas que ya existen, pero mientras el `address_id` siga colgado van a aparecer nuevos.
 *
 * Por eso el reporte nombra a esos usuarios (por dueño) en `--ver` y en `--aplicar`. Y NO los
 * corrige: a qué sucursal mandar a un usuario es una decisión del dueño, no del comando.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Usuarios_con_sucursal_colgada_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Un empleado del dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  string            $etiqueta
     * @return \App\Models\User
     */
    protected function empleado($dueno, $etiqueta)
    {
        return User::create([
            'name' => 'zz Empleado saneo ' . $etiqueta,
            'email' => 'zz-saneo-empleado-' . $etiqueta . '-' . uniqid() . '@test.local',
            'password' => Hash::make(uniqid('', true)),
            'owner_id' => $dueno->id,
        ]);
    }

    /**
     * Un dueño con cinco usuarios: dos con el `address_id` colgado (él mismo y un empleado) y tres
     * que NO lo están (sucursal viva, 0 y NULL).
     *
     *  - el dueño       → sucursal muerta M1 (colgado)
     *  - empleado 1     → sucursal muerta M2 (colgado)
     *  - empleado 2     → sucursal viva S1   (bien)
     *  - empleado 3     → 0                  (sin sucursal elegida: no es un puntero roto)
     *  - empleado 4     → NULL               (ídem)
     *
     * Y un artículo con un fantasma, para que `--aplicar` tenga algo que hacer.
     *
     * @return array
     */
    protected function escenario()
    {
        $dueno = $this->dueno('colgado');
        $s1 = $this->sucursal($dueno);
        $m1 = $this->sucursal_muerta($dueno);
        $m2 = $this->sucursal_muerta($dueno);

        $colgado = $this->empleado($dueno, 'colgado');
        $bien = $this->empleado($dueno, 'bien');
        $cero = $this->empleado($dueno, 'cero');
        $nulo = $this->empleado($dueno, 'nulo');

        DB::table('users')->where('id', $dueno->id)->update(['address_id' => $m1]);
        DB::table('users')->where('id', $colgado->id)->update(['address_id' => $m2]);
        DB::table('users')->where('id', $bien->id)->update(['address_id' => $s1->id]);
        DB::table('users')->where('id', $cero->id)->update(['address_id' => 0]);
        DB::table('users')->where('id', $nulo->id)->update(['address_id' => null]);

        $articulo = $this->articulo_con_fantasmas($dueno, 'Colgado', [$s1->id => 10], [[$m1, -1]])['articulo'];

        return compact('dueno', 's1', 'm1', 'm2', 'colgado', 'bien', 'cero', 'nulo', 'articulo');
    }

    /**
     * Foto del contenido completo de `users`.
     *
     * @return string
     */
    protected function huella_de_users()
    {
        return md5(serialize(DB::table('users')->orderBy('id')->get()->all()));
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_reporte_nombra_a_los_usuarios_con_address_id_colgado_y_solo_a_ellos()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->ver($e['dueno']), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('users.address_id COLGADO', $this->salida);

        $sucursales = [$e['m1'], $e['m2']];
        sort($sucursales);

        // Los dos colgados, con su dueño y las sucursales muertas a las que apuntan. La línea
        // completa fija la cantidad (2) y los ids: los que NO están colgados (sucursal viva, 0 y
        // NULL) no pueden figurar, porque entonces serían más de 2 o habría otros ids.
        $this->assertStringContainsString(
            'dueño ' . $e['dueno']->id . ': 2 usuarios (ids ' . $e['dueno']->id . ', ' . $e['colgado']->id . ') · sucursales muertas ' . implode(', ', $sucursales) . '.',
            $this->salida,
            'El reporte tiene que listar al dueño y al empleado con el address_id colgado, y solo a ellos (no a los que apuntan a una sucursal viva, a 0 o a NULL).'
        );
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function aplicar_tambien_lo_reporta_y_no_escribe_nunca_en_users()
    {
        $e = $this->escenario();

        $antes = $this->huella_de_users();

        $direcciones_antes = DB::table('users')->whereIn('id', [$e['dueno']->id, $e['colgado']->id, $e['bien']->id, $e['cero']->id, $e['nulo']->id])->orderBy('id')->pluck('address_id', 'id')->all();

        $this->assertSame(0, $this->aplicar($e['dueno']), 'Salida:' . "\n" . $this->salida);

        // Se sanearon los fantasmas...
        $this->assertSame(0, $this->filas_en($e['articulo'], $e['m1']), 'El saneo tenía que haberse hecho.');

        // ...se reportó el address_id colgado...
        $this->assertStringContainsString('users.address_id COLGADO', $this->salida, '--aplicar también tiene que reportar los address_id colgados.');
        $this->assertStringContainsString('ids ' . $e['dueno']->id . ', ' . $e['colgado']->id, $this->salida);

        // ...y NO se tocó ni una fila de users.
        $this->assertSame($antes, $this->huella_de_users(), '--aplicar escribió en la tabla users: el address_id colgado se reporta y no se corrige.');

        $direcciones_despues = DB::table('users')->whereIn('id', [$e['dueno']->id, $e['colgado']->id, $e['bien']->id, $e['cero']->id, $e['nulo']->id])->orderBy('id')->pluck('address_id', 'id')->all();

        $this->assertSame($direcciones_antes, $direcciones_despues, 'users.address_id cambió.');
    }

    /**
     * Acotado a un dueño no se listan los usuarios de otro.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function acotado_a_un_dueno_no_lista_los_usuarios_de_otro()
    {
        $e = $this->escenario();

        $otro = $this->dueno('colgado-otro');
        $muerta_del_otro = $this->sucursal_muerta($otro);

        DB::table('users')->where('id', $otro->id)->update(['address_id' => $muerta_del_otro]);

        $this->assertSame(0, $this->ver($e['dueno']), 'Salida:' . "\n" . $this->salida);

        $this->assertStringNotContainsString('dueño ' . $otro->id . ':', $this->salida, 'Acotado por --user_id no tiene que aparecer otro dueño.');

        // Sin acotar sí aparecen los dos dueños.
        $this->assertSame(0, $this->sanear(['--ver' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('dueño ' . $e['dueno']->id . ': 2 usuarios', $this->salida);
        $this->assertStringContainsString('dueño ' . $otro->id . ': 1 usuarios', $this->salida);
    }
}
