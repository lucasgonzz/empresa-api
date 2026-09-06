<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Models\DepositMovementStatus;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de stock (5/9/2026) — el registro dice la verdad y cada camino deja un solo rastro.
 *
 *  - `stock_resultante` es el stock real del artículo después del movimiento, no una cadena
 *    arrastrada desde el movimiento anterior.
 *  - Un movimiento entre depósitos ya Recibido no se vuelve a aplicar al reguardarlo.
 *  - La actualización masiva del listado cambia el stock con un movimiento (y no multiplica por
 *    unidades_individuales), y revertirla también.
 *  - El modal viejo de crear depósitos manda `concepto` a secas y tiene que quedar como
 *    "Creacion de deposito", no como "Ingreso manual".
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Auditoria_stock_resultante_depositos_y_masiva_Test extends AuditoriaStockTestCase
{
    /**
     * @group stock
     * @test
     */
    public function el_stock_resultante_es_el_stock_real_del_articulo()
    {
        $articulo = $this->crear_articulo('zz Auditoria stock resultante');

        $this->postJson('api/stock-movement', [
            'model_id' => $articulo->id,
            'amount'   => 10,
        ])->assertStatus(201);

        $this->assertEquals(10.0, $this->stock($articulo));
        $this->assertEquals(10.0, (float) $this->movimientos($articulo)->last()->stock_resultante);

        /*
         * Un desvío histórico (un stock corregido directo en la base, un dato anterior a los
         * movimientos): la cadena vieja habría dicho 10 + 5 = 15; lo que el usuario ve es 55.
         */
        DB::table('articles')->where('id', $articulo->id)->update(['stock' => 50]);

        $this->postJson('api/stock-movement', [
            'model_id' => $articulo->id,
            'amount'   => 5,
        ])->assertStatus(201);

        $this->assertEquals(55.0, $this->stock($articulo));
        $this->assertEquals(55.0, (float) $this->movimientos($articulo)->last()->stock_resultante, 'El stock_resultante tiene que coincidir con el stock real, no con la cadena del movimiento anterior.');
    }

    /**
     * @group stock
     * @test
     */
    public function un_movimiento_entre_depositos_recibido_no_se_aplica_dos_veces()
    {
        $articulo = $this->crear_articulo('zz Auditoria traslado recibido');
        $origen = $this->sucursal();
        $destino = $this->segunda_sucursal();

        $this->postJson('api/stock-movement', [
            'model_id'                     => $articulo->id,
            'amount'                       => 10,
            'to_address_id'                => $origen->id,
            'concepto_stock_movement_name' => 'Creacion de deposito',
        ])->assertStatus(201);

        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $origen->id));

        $recibido = DepositMovementStatus::firstOrCreate(['name' => 'Recibido']);

        $payload = [
            'from_address_id'            => $origen->id,
            'to_address_id'              => $destino->id,
            'deposit_movement_status_id' => $recibido->id,
            'employee_id'                => null,
            'recibido_at'                => null,
            'notes'                      => 'traslado de prueba',
            'articles'                   => [
                ['id' => $articulo->id, 'pivot' => ['amount' => 4, 'article_variant_id' => null]],
            ],
        ];

        $response = $this->postJson('api/deposit-movement', $payload);
        $response->assertStatus(201);

        $deposit_movement_id = json_decode($response->getContent(), true)['model']['id'];

        $this->assertEquals(6.0, $this->stock_en_deposito($articulo, $origen->id), 'Recibir el traslado tenía que sacar 4 del origen.');
        $this->assertEquals(4.0, $this->stock_en_deposito($articulo, $destino->id), 'Recibir el traslado tenía que poner 4 en el destino.');
        $this->assertEquals(10.0, $this->stock($articulo), 'Un traslado no cambia el stock global.');

        /* Reguardar el movimiento ya Recibido (una nota, un reintento): nada se mueve de nuevo. */
        $payload['notes'] = 'traslado de prueba, editado';
        $payload['recibido_at'] = DB::table('deposit_movements')->where('id', $deposit_movement_id)->value('recibido_at');

        $this->putJson('api/deposit-movement/'.$deposit_movement_id, $payload)->assertStatus(200);

        $this->assertEquals(6.0, $this->stock_en_deposito($articulo, $origen->id), 'Reguardar un traslado Recibido no puede volver a restar el origen.');
        $this->assertEquals(4.0, $this->stock_en_deposito($articulo, $destino->id), 'Reguardar un traslado Recibido no puede volver a sumar el destino.');
        $this->assertEquals(1, $this->movimientos($articulo, 'Mov entre depositos')->count(), 'El traslado deja un solo movimiento por artículo.');
    }

    /**
     * @group stock
     * @test
     */
    public function la_actualizacion_masiva_de_stock_deja_movimiento_y_no_multiplica()
    {
        $user = $this->usuario();

        $articulo = $this->crear_articulo('zz Auditoria masiva de stock', ['stock' => 10, 'unidades_individuales' => 6]);

        $criteria = [
            'from_filter'        => false,
            'used_filters'       => [],
            'update_form'        => [
                ['type' => 'number', 'key' => 'set_stock', 'value' => 25],
            ],
            'models_id'          => [$articulo->id],
            'resolved_models_id' => [$articulo->id],
            'filter_form'        => [],
        ];

        $masiva = MasiveUpdateHelper::create_pending_update($user->id, $user->id, 'article', false, $criteria);

        MasiveUpdateHelper::process_update($masiva);

        $this->assertEquals(25.0, $this->stock($articulo), 'La masiva tenía que dejar el stock en 25.');

        $movimiento = $this->movimientos($articulo, 'Ingreso manual')->last();
        $this->assertNotNull($movimiento, 'La masiva tiene que dejar un movimiento de stock, no escribir la columna.');
        $this->assertEquals(15.0, (float) $movimiento->amount, 'El movimiento es la diferencia (25 − 10), sin multiplicar por unidades_individuales.');
        $this->assertEquals(25.0, (float) $movimiento->stock_resultante);

        /* Revertir la masiva también va por movimiento. */
        $reversion = MasiveUpdateHelper::create_pending_revert($masiva->fresh(), $user->id);

        MasiveUpdateHelper::process_revert($reversion, $masiva->fresh());

        $this->assertEquals(10.0, $this->stock($articulo), 'Revertir la masiva tenía que devolver el stock a 10.');

        $reverso = $this->movimientos($articulo, 'Ingreso manual')->last();
        $this->assertEquals(-15.0, (float) $reverso->amount);
    }

    /**
     * @group stock
     * @test
     */
    public function el_modal_viejo_de_crear_depositos_deja_el_concepto_correcto()
    {
        $articulo = $this->crear_articulo('zz Auditoria modal viejo depositos', ['stock' => 10, 'unidades_individuales' => 6]);
        $sucursal = $this->sucursal();

        /* Payload literal de create-article-addresses/Index.vue: `concepto`, sin `_name`. */
        $this->postJson('api/stock-movement', [
            'model_id'                      => $articulo->id,
            'amount'                        => 10,
            'concepto'                      => 'Creacion de deposito',
            'from_create_article_addresses' => true,
            'to_address_id'                 => $sucursal->id,
        ])->assertStatus(201);

        $movimiento = $this->movimientos($articulo)->last();

        $this->assertEquals($this->concepto_id('Creacion de deposito'), (int) $movimiento->concepto_stock_movement_id, 'El concepto tiene que ser "Creacion de deposito", no el default "Ingreso manual".');
        $this->assertEquals(10.0, (float) $movimiento->amount, 'Un reparto no se multiplica por unidades_individuales.');
        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $sucursal->id));
        $this->assertEquals(10.0, $this->stock($articulo));
    }
}
