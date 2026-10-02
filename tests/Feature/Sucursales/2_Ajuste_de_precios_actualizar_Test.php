<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\AjusteDePreciosDeSucursalHelper;
use App\Models\Address;
use App\Models\AuditLog;
use App\Services\AuditLog\AuditContext;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 2 — ACTUALIZAR una sucursal: el ajuste se cambia, se quita, y NO se pisa cuando la SPA no
 * lo manda.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LO QUE MAS IMPORTA DE ESTE ARCHIVO: LA COMPATIBILIDAD CON LA SPA VIEJA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Una SPA anterior a esta mision no conoce las claves del ajuste y no las manda. `update` asigna
 *  los campos de la sucursal uno por uno; si el ajuste se escribiera "siempre", el primer guardado de
 *  una sucursal desde esa SPA le borraria el recargo o el descuento en silencio (tipo y porcentaje a
 *  NULL) y nadie se enteraria hasta que Vender dejara de aplicarlo. Test 1 lo fija.
 *
 *  Las dos puntas de la compatibilidad son del mismo contrato: SPA vieja + API nueva (este archivo)
 *  y SPA nueva + API vieja (la API vieja ignora las claves y no devuelve las columnas: nada que
 *  probar aca, es un no-op de la API anterior).
 *
 * @group sucursales
 */
class Ajuste_de_precios_actualizar_Test extends SucursalesTestCase
{
    /**
     * Test 1 — PUT SIN ninguna de las dos claves sobre una sucursal con ajuste: el ajuste SIGUE ahi,
     * y el resto de los campos si se actualizan (el PUT no quedo ignorado entero).
     *
     * @test
     */
    public function el_put_sin_las_claves_del_ajuste_no_toca_el_ajuste()
    {
        $id = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 12.5,
        ]);

        $this->assert_ajuste($id, 'recargo', 12.5, 'escenario de partida');

        /* El payload de una SPA vieja: los campos de siempre y ninguna clave del ajuste. */
        $this->putJson('api/address/'.$id, $this->payload_sucursal([
            'street' => 'zz Sucursal renombrada por la SPA vieja',
            'city'   => 'Rosario',
        ]))->assertStatus(200);

        $this->assert_ajuste($id, 'recargo', 12.5, 'PUT sin las claves del ajuste');

        $this->assertSame('zz Sucursal renombrada por la SPA vieja', DB::table('addresses')->where('id', $id)->value('street'), 'El PUT tiene que haber actualizado la calle.');
        $this->assertSame('Rosario', DB::table('addresses')->where('id', $id)->value('city'), 'El PUT tiene que haber actualizado la ciudad.');
    }

    /**
     * Test 2 — PUT con `ajuste_precio_tipo` vacio / null: las dos columnas quedan en NULL (se quita el
     * ajuste). Se prueba con null, con texto vacio, con `sin_ajuste` y con el 0 (numerico o de texto)
     * que el motor del ABM le pone a un select sin valor, que son las formas en que la SPA puede decir
     * "sin ajuste".
     *
     * @test
     */
    public function el_put_con_el_tipo_vacio_quita_el_ajuste()
    {
        foreach ([null, '', 'sin_ajuste', 0, '0'] as $vacio) {

            $id = $this->crear_sucursal([
                'ajuste_precio_tipo'       => 'descuento',
                'ajuste_precio_porcentaje' => 5,
            ]);

            $this->assert_ajuste($id, 'descuento', 5, 'escenario de partida');

            $this->putJson('api/address/'.$id, $this->payload_sucursal([
                'ajuste_precio_tipo'       => $vacio,
                'ajuste_precio_porcentaje' => null,
            ]))->assertStatus(200);

            $this->assert_ajuste($id, null, null, 'PUT con el tipo '.var_export($vacio, true));
        }
    }

    /**
     * Test 3 — PUT que CAMBIA el ajuste de recargo a descuento, y que lo agrega a una sucursal que no
     * tenia.
     *
     * @test
     */
    public function el_put_cambia_y_agrega_el_ajuste()
    {
        $id = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 10,
        ]);

        $this->putJson('api/address/'.$id, $this->payload_sucursal([
            'ajuste_precio_tipo'       => 'descuento',
            'ajuste_precio_porcentaje' => '7,25',
        ]))->assertStatus(200);

        $this->assert_ajuste($id, 'descuento', 7.25, 'PUT de recargo a descuento');

        $sin_ajuste = $this->crear_sucursal();

        $this->assert_ajuste($sin_ajuste, null, null, 'escenario de partida');

        $this->putJson('api/address/'.$sin_ajuste, $this->payload_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 3,
        ]))->assertStatus(200);

        $this->assert_ajuste($sin_ajuste, 'recargo', 3, 'PUT que agrega el ajuste');
    }

    /**
     * Test 4 — 🔴 el porcentaje normalizado NO se ve distinto del que ya esta en la base: asignarlo al
     * modelo no lo marca como modificado.
     *
     * La base entrega el DECIMAL como string ("10.00"). Si `normalizar()` devolviera el float 10.0,
     * Eloquent lo compararia como "10" contra "10.00", lo daria por cambiado y `update` lo reescribiria
     * en cada guardado de la sucursal. Es lo que se prueba, directo, con el mismo modelo que lee y
     * asigna `AddressController@update`.
     *
     * @test
     */
    public function el_valor_normalizado_no_marca_el_ajuste_como_modificado()
    {
        foreach ([10, '12,5', '5.5', '99,99'] as $porcentaje) {

            $id = $this->crear_sucursal([
                'ajuste_precio_tipo'       => 'recargo',
                'ajuste_precio_porcentaje' => $porcentaje,
            ]);

            $modelo = Address::find($id);

            $resultado = AjusteDePreciosDeSucursalHelper::normalizar('recargo', $porcentaje);

            foreach ($resultado['valores'] as $columna => $valor) {
                $modelo->{$columna} = $valor;
            }

            $this->assertFalse(
                $modelo->isDirty('ajuste_precio_porcentaje'),
                'Reasignar el mismo porcentaje ('.var_export($porcentaje, true).') no puede marcarlo como modificado.'
            );

            $this->assertFalse($modelo->isDirty('ajuste_precio_tipo'), 'Reasignar el mismo tipo no puede marcarlo como modificado.');

            $this->assertFalse($modelo->isDirty(), 'No cambio ningun atributo: el modelo no tiene que quedar sucio.');
        }
    }

    /**
     * Test 5 — de punta a punta: un PUT que cambia solo el NOMBRE y manda el mismo ajuste de siempre
     * (como hace la SPA nueva: el ABM manda todos los campos) deja UNA fila `updated` en `audit_logs`
     * con la calle, y NINGUNA mencion al ajuste. Sin esto, cada guardado dejaria una entrada espuria
     * "10.00 -> 10" del porcentaje.
     *
     * Incluye una asercion de control (la calle SI esta en la fila): sin ella, una auditoria que no
     * registrara nada haria pasar el test sin probar nada.
     *
     * @test
     */
    public function un_put_que_no_cambia_el_ajuste_no_deja_el_ajuste_en_la_auditoria()
    {
        AuditContext::reiniciar();

        $id = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 10,
        ]);

        $desde = (int) AuditLog::max('id');

        $this->putJson('api/address/'.$id, $this->payload_sucursal([
            'street'                   => 'zz Sucursal renombrada con el mismo ajuste',
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 10,
        ]))->assertStatus(200);

        $filas = AuditLog::where('id', '>', $desde)
                            ->where('auditable_type', Address::class)
                            ->where('auditable_id', $id)
                            ->where('event', 'updated')
                            ->get();

        $this->assertCount(1, $filas, 'El PUT tiene que dejar exactamente una fila updated de la sucursal.');

        $nuevos  = json_decode($filas->first()->new_values, true);
        $viejos  = json_decode($filas->first()->old_values, true);

        /* Control: lo que SI cambio esta registrado. */
        $this->assertArrayHasKey('street', $nuevos, 'La calle cambio y tiene que figurar en la auditoria.');

        foreach (['ajuste_precio_porcentaje', 'ajuste_precio_tipo'] as $columna) {
            $this->assertArrayNotHasKey($columna, $nuevos, $columna.': no cambio y no tiene que figurar en los valores nuevos.');
            $this->assertArrayNotHasKey($columna, $viejos, $columna.': no cambio y no tiene que figurar en los valores viejos.');
        }

        AuditContext::reiniciar();
    }
}
