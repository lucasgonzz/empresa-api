<?php

namespace Tests\Feature\ProduccionV2;

use App\Models\Address;
use App\Models\RecipeRoute;

/**
 * De que deposito sale el insumo cuando el movimiento no declara uno.
 *
 * 🔴 EL 0 DE LOS SELECTS DE LA SPA SIGNIFICA "SIN DEPOSITO", NO "EL DEPOSITO 0".
 *
 * Los selects de deposito del formulario del movimiento mandan `0` cuando el usuario no elige
 * nada --es el valor de la opcion "Seleccione Sucursal"--, y la cascada de
 * `calculate_planned_inputs()` filtraba solo por `is_null`. Con el 0 adentro, el primer nivel de
 * la cascada ganaba siempre y el "Deposito insumos" de la ruta no llegaba a usarse nunca: el
 * consumo se registraba contra un deposito que no existe y no bajaba ningun saldo.
 *
 * Lo peor del sintoma es que no se parece a un error. El endpoint responde 201, el movimiento
 * queda guardado, el `StockMovement` tambien --con su cantidad y su concepto `Insumo de
 * produccion`--, y lo unico que no pasa es el descuento. Sin excepcion, sin 422 y sin log.
 * Medido el 8/9/2026 sobre la fabrica de sillas de Quino: un movimiento sin deposito no descontó
 * los 21,2 m de caño, y el siguiente --mismo lote, con el deposito elegido a mano-- descontó los
 * 20 electrodos bien.
 *
 * 🔴 POR QUE LOS INSUMOS DE ESTOS TESTS SI TIENEN DEPOSITO.
 *
 * Es la unica forma de que el arreglo sea observable. Un articulo SIN filas en `address_article`
 * se descuenta contra `articles.stock` por `CheckGlobalStock` tenga o no deposito el movimiento,
 * asi que con el fixture habitual del TestCase (punto 3 de su encabezado) el antes y el despues
 * dan igual y el test no probaria nada.
 */
class Deposito_del_movimiento_Test extends ProduccionV2TestCase
{
    /**
     * El movimiento llega con address_id = 0 y el descuento igual sale del deposito de la ruta.
     *
     * @group produccion_v2
     * @test
     */
    public function el_movimiento_sin_deposito_descuenta_del_deposito_de_la_ruta()
    {
        $deposito = Address::create([
            'street'    => 'Deposito movimiento test',
            'user_id'   => $this->comercio()->id,
        ]);

        $corte = $this->crear_estado('Corte deposito mov test', 1);

        /*
         * La ruta declara su estado final, asi que este test no depende de cual es el estado de
         * mayor position de la cuenta. Sin esto, en una base que ya usa el modulo el movimiento
         * podria caer justo en el estado final global y dar de alta producto terminado, que no es
         * lo que se esta midiendo.
         */
        $fin = $this->crear_estado('Fin deposito mov test', 2);

        $silla = $this->crear_articulo('Silla deposito mov test', 0);

        $cano = $this->crear_articulo('Cano deposito mov test', 100);
        $cano->addresses()->attach($deposito->id, ['amount' => 100]);

        $receta = $this->crear_receta($silla);

        $ruta = $this->crear_ruta($receta, [
            ['article' => $cano, 'amount' => 2, 'order_production_status_id' => $corte->id],
        ], [
            'from_address_id'                   => $deposito->id,
            'end_order_production_status_id'    => $fin->id,
        ]);

        $lote = $this->crear_lote($silla, $receta, $ruta, 10);

        $tipo = $this->crear_tipo_de_movimiento('Inicio deposito mov test', 'start_deposito_mov_test');

        /* El 0 es literalmente lo que manda la SPA cuando el usuario no toca los selects. */
        $respuesta = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $corte->id,
            'address_id'                        => 0,
            'to_address_id'                     => 0,
            'amount'                            => 5,
        ]);

        $respuesta->assertStatus(201);

        /* 5 unidades x 2 por unidad = 10 salieron del deposito de la ruta. */
        $this->assertDatabaseHas('address_article', [
            'article_id'    => $cano->id,
            'address_id'    => $deposito->id,
            'amount'        => 90,
        ]);

        /* El renglon del insumo quedo con el deposito resuelto, no con el 0 que vino del request. */
        $this->assertDatabaseHas('production_batch_movement_inputs', [
            'production_batch_movement_id'  => $respuesta->json('model.id'),
            'article_id'                    => $cano->id,
            'address_id'                    => $deposito->id,
        ]);

        /*
         * Y el movimiento tambien lo guarda resuelto en su propia columna. No es redundante: el
         * borrado revierte leyendo esta columna, y el test de abajo es el que lo aprovecha.
         */
        $this->assertDatabaseHas('production_batch_movements', [
            'id'            => $respuesta->json('model.id'),
            'address_id'    => $deposito->id,
        ]);
    }

    /**
     * 🔴 EL BORRADO DEVUELVE AL DEPOSITO QUE SE TOCO, NO AL QUE LA RUTA DIGA AHORA.
     *
     * Entre el alta y el borrado pueden pasar semanas, y en el medio el usuario puede cambiarle el
     * "Deposito insumos" a la ruta. Si el borrado resolviera la cascada de nuevo, devolveria las
     * unidades a un deposito del que nunca salieron: le sobra stock a uno y le falta al otro, sin
     * error y sin log. Por eso el deposito se registra en el movimiento cuando el hecho ocurre.
     *
     * Es la misma familia que `output_stock_applied` y la misma que APRENDER_NO_PARCHEAR llama
     * "el fallback que interpreta datos viejos con la logica nueva".
     *
     * @group produccion_v2
     * @test
     */
    public function borrar_el_movimiento_devuelve_al_deposito_del_que_salio_aunque_la_ruta_haya_cambiado()
    {
        $viejo = Address::create([
            'street'    => 'Viejo deposito borrado test',
            'user_id'   => $this->comercio()->id,
        ]);

        $nuevo = Address::create([
            'street'    => 'Nuevo deposito borrado test',
            'user_id'   => $this->comercio()->id,
        ]);

        $corte = $this->crear_estado('Corte deposito borrado test', 1);
        $fin   = $this->crear_estado('Fin deposito borrado test', 2);

        $silla = $this->crear_articulo('Silla deposito borrado test', 0);

        $cano = $this->crear_articulo('Cano deposito borrado test', 150);
        $cano->addresses()->attach($viejo->id, ['amount' => 100]);
        $cano->addresses()->attach($nuevo->id, ['amount' => 50]);

        $receta = $this->crear_receta($silla);

        $ruta = $this->crear_ruta($receta, [
            ['article' => $cano, 'amount' => 2, 'order_production_status_id' => $corte->id],
        ], [
            'from_address_id'                   => $viejo->id,
            'end_order_production_status_id'    => $fin->id,
        ]);

        $lote = $this->crear_lote($silla, $receta, $ruta, 10);

        $tipo = $this->crear_tipo_de_movimiento('Inicio deposito borrado test', 'start_deposito_borrado_test');

        $respuesta = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $corte->id,
            'address_id'                        => 0,
            'amount'                            => 5,
        ]);

        $respuesta->assertStatus(201);

        /* Salieron 10 del viejo. */
        $this->assertDatabaseHas('address_article', [
            'article_id'    => $cano->id,
            'address_id'    => $viejo->id,
            'amount'        => 90,
        ]);

        /* El usuario reconfigura la ruta: de ahora en mas los insumos salen del otro deposito. */
        $ruta_recargada = RecipeRoute::find($ruta->id);
        $ruta_recargada->from_address_id = $nuevo->id;
        $ruta_recargada->save();

        $this->delete('api/production-batch-movement/'.$respuesta->json('model.id'))->assertStatus(204);

        /* Las 10 vuelven al viejo, que es de donde salieron. */
        $this->assertDatabaseHas('address_article', [
            'article_id'    => $cano->id,
            'address_id'    => $viejo->id,
            'amount'        => 100,
        ]);

        /* Y el nuevo queda intacto: nunca se toco. */
        $this->assertDatabaseHas('address_article', [
            'article_id'    => $cano->id,
            'address_id'    => $nuevo->id,
            'amount'        => 50,
        ]);
    }
}
