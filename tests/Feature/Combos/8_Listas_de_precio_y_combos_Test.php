<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Editar o borrar una lista de precios recalcula los combos calculados (misión combos-calculados,
 * 30/9/2026, corrección del verificador del contrato).
 *
 * EL HUECO: solo el ALTA de una lista recalculaba los combos (por `ProcessSetFinalPrices` →
 * `FinalizeSetFinalPrices`). `PriceTypeController::update()` y `destroy()` no disparaban nada, así
 * que después de cambiar la position o la visibilidad de una lista, `combos.price` —lo único que lee
 * una tienda vieja— seguía saliendo de la lista que ya no era la por defecto, y una lista borrada
 * seguía con su fila en `combo_price_type` hasta la red de seguridad de la noche.
 *
 * Se prueba por los endpoints reales (`PUT/DELETE api/price-type/{id}`), no llamando al helper: lo
 * que se protege es que el controlador lo llame.
 *
 * Lo que NO se prueba acá: que una falla del recálculo no tumbe la edición de la lista. El controlador
 * lo garantiza con un try/catch (Throwable) y `recalcular_de_un_dueno()` ya atrapa cada combo, pero
 * provocar la falla exige un DDL, y un DDL en MySQL confirma la transacción de `DatabaseTransactions`
 * y deja la base de testing sucia.
 *
 * @group combos-calculados
 */
class Listas_de_precio_y_combos_Test extends ComboCalculadoTestCase
{
    /**
     * Payload de `PUT api/price-type/{id}` tal como lo manda el modal del ABM, partiendo de la lista
     * tal como está en la base.
     *
     * @param  \App\Models\PriceType  $lista
     * @param  array                  $cambios
     * @return array
     */
    protected function payload_de_lista(PriceType $lista, array $cambios = [])
    {
        return array_merge([
            'name'                 => $lista->name,
            'percentage'           => $lista->percentage,
            'position'             => $lista->position,
            'ocultar_al_publico'   => $lista->ocultar_al_publico,
            'categories'           => [],
            'sub_categories'       => [],
        ], $cambios);
    }

    /**
     * Arma una cuenta con dos listas propias y un combo calculado que las usa: la baja vale 300 y la
     * alta 180, y `combos.price` tiene que ser el de la alta (la de mayor position).
     *
     * @return array [$baja, $alta, $combo]
     */
    protected function combo_con_dos_listas()
    {
        $this->con_listas(1);

        $baja = $this->lista('Baja', 90);
        $alta = $this->lista('Alta', 91);

        $a = $this->nuevo_articulo();
        $this->precio_en_lista($a, $baja, 300);
        $this->precio_en_lista($a, $alta, 180);

        $combo = $this->combo_calculado([[$a, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(180.0, $this->precio_en_base($combo), 'Precondición: combos.price es el de la lista más alta.');

        return [$baja, $alta, $combo];
    }

    /**
     * 🔴 Cambiar la position de una lista mueve `combos.price`: la baja pasa a ser la de mayor
     * position y su precio es el que se copia.
     *
     * @test
     */
    public function cambiar_la_position_de_una_lista_mueve_combos_price()
    {
        list($baja, $alta, $combo) = $this->combo_con_dos_listas();

        $this->putJson('api/price-type/' . $baja->id, $this->payload_de_lista($baja, ['position' => 95]))->assertStatus(200);

        $this->assertSame(300.0, $this->precio_en_base($combo), 'combos.price pasa a ser el de la lista que ahora tiene la mayor position');

        // Y de vuelta.
        $this->putJson('api/price-type/' . $baja->id, $this->payload_de_lista($baja, ['position' => 50]))->assertStatus(200);

        $this->assertSame(180.0, $this->precio_en_base($combo));
    }

    /**
     * Ocultar al público la lista más alta saca su precio de `combos.price` (la regla de la
     * visibilidad, ahora también al editar la lista).
     *
     * @test
     */
    public function ocultar_la_lista_mas_alta_saca_su_precio_de_combos_price()
    {
        list($baja, $alta, $combo) = $this->combo_con_dos_listas();

        $this->putJson('api/price-type/' . $alta->id, $this->payload_de_lista($alta, ['ocultar_al_publico' => 1]))->assertStatus(200);

        $this->assertSame(300.0, $this->precio_en_base($combo), 'la oculta no se copia a combos.price');
        $this->assertSame(180.0, $this->precios_por_lista_en_base($combo)[$alta->id], 'pero su fila sigue existiendo');
    }

    /**
     * Borrar una lista: su fila de `combo_price_type` desaparece y `combos.price` pasa a la lista
     * que quedó como por defecto.
     *
     * @test
     */
    public function borrar_una_lista_limpia_sus_filas_y_recalcula_combos_price()
    {
        list($baja, $alta, $combo) = $this->combo_con_dos_listas();

        $this->assertArrayHasKey($alta->id, $this->precios_por_lista_en_base($combo));

        $this->deleteJson('api/price-type/' . $alta->id)->assertStatus(200);

        $this->assertArrayNotHasKey($alta->id, $this->precios_por_lista_en_base($combo), 'no queda la fila huérfana');
        $this->assertArrayHasKey($baja->id, $this->precios_por_lista_en_base($combo));
        $this->assertSame(300.0, $this->precio_en_base($combo), 'la lista más alta que queda pasa a ser la por defecto');
    }

    /**
     * Las filas de la lista borrada desaparecen aunque el combo YA NO sea calculado (el recálculo no
     * lo alcanzaría): el borrado de las filas no depende del recálculo.
     *
     * @test
     */
    public function borrar_una_lista_limpia_las_filas_aunque_el_combo_ya_no_sea_calculado()
    {
        $this->con_listas(1);

        $lista = $this->lista('Efimera', 92);

        $manual = $this->combo([], ['cost' => 1, 'price' => 2]);

        DB::table('combo_price_type')->insert([
            'combo_id' => $manual->id, 'price_type_id' => $lista->id, 'price' => 77,
        ]);

        $this->deleteJson('api/price-type/' . $lista->id)->assertStatus(200);

        $this->assertSame([], $this->precios_por_lista_en_base($manual));
    }

    /**
     * Un empleado que edita la lista recalcula los combos de su DUEÑO (no busca combos a nombre del
     * empleado, que no tiene).
     *
     * @test
     */
    public function un_empleado_que_edita_la_lista_recalcula_los_combos_del_dueno()
    {
        list($baja, $alta, $combo) = $this->combo_con_dos_listas();

        /* El fixture no trae empleados: se crea uno del dueño (la transacción del test lo deshace). */
        $empleado = User::create([
            'name'     => 'zz Empleado de listas',
            'email'    => 'zz-empleado-listas-' . uniqid() . '@testing.local',
            'password' => bcrypt('zz-no-se-usa'),
            'owner_id' => self::DUENO,
        ]);

        $this->actingAs($empleado, 'web');

        $this->putJson('api/price-type/' . $baja->id, $this->payload_de_lista($baja, ['position' => 95]))->assertStatus(200);

        $this->assertSame(300.0, $this->precio_en_base($combo));
    }
}
