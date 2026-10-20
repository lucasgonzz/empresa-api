<?php

namespace Tests\Feature\Envios;

use App\Models\DeliveryZone;
use Tests\Concerns\EscondeColumnas;
use Tests\EmpresaTestCase;

/**
 * Misión formas-envio-direcciones-maps (19/10/2026): las formas de envío propias de la tienda
 * (`delivery_zones`) pueden tener el costo A CONVENIR (`costo_a_convenir`), por ejemplo "Acordar
 * con el vendedor": la tienda no cobra nada online y el vendedor coordina la entrega.
 *
 * Lo que fija este archivo (`POST`/`PUT delivery-zone`):
 *
 * 1. Con `costo_a_convenir` el precio se guarda en 0 aunque el request mande otro (o ninguno):
 *    una tienda vieja que no conoce la bandera cobraría el precio guardado.
 * 2. Sin la clave (pantalla vieja) un alta nace con costo fijo, como siempre.
 * 3. Editar: tildar pone el precio en 0; destildar vuelve al precio del request; un PUT sin la
 *    clave conserva lo guardado (y con eso el 0 de una forma a convenir).
 * 4. Sin la columna (la ventana del deploy antes de migrar) el alta y la edición andan igual.
 *
 * @group envios
 */
class FormasDeEnvioPropiasTest extends EmpresaTestCase
{
    use EscondeColumnas;

    /** Tabla y columna de la bandera. */
    const TABLA = 'delivery_zones';
    const COLUMNA = 'costo_a_convenir';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_columna_si_quedo_escondida(self::TABLA, self::COLUMNA);
    }

    /**
     * Id del usuario autenticado por `EmpresaTestCase` (el dueño del fixture).
     *
     * @return int
     */
    protected function user_id()
    {
        return $this->app['auth']->user()->id;
    }

    /**
     * Payload del formulario de la forma de envío.
     *
     * @param  array<string, mixed>  $cambios
     * @param  array<int, string>  $sin  Claves que el payload no trae.
     * @return array<string, mixed>
     */
    protected function payload(array $cambios = [], array $sin = [])
    {
        $payload = array_merge([
            'name'             => 'Acordar con el vendedor',
            'description'      => 'Coordinamos la entrega por WhatsApp',
            'price'            => 500,
            'costo_a_convenir' => 0,
        ], $cambios);

        foreach ($sin as $clave) {
            unset($payload[$clave]);
        }

        return $payload;
    }

    /**
     * Una forma de envío del comercio, directo en la base.
     *
     * @param  array<string, mixed>  $atributos
     * @return DeliveryZone
     */
    protected function forma(array $atributos = [])
    {
        return DeliveryZone::create(array_merge([
            'user_id'          => $this->user_id(),
            'name'             => 'Moto en el día',
            'description'      => null,
            'price'            => 1200,
            'costo_a_convenir' => 0,
        ], $atributos));
    }

    /**
     * 1. Alta a convenir: precio 0 aunque el request mande 500, y también si lo manda vacío
     *    (el SPA oculta el precio al tildar).
     *
     * @return void
     */
    public function test_alta_a_convenir_guarda_el_precio_en_cero()
    {
        $respuesta = $this->postJson('api/delivery-zone', $this->payload(['costo_a_convenir' => 1, 'price' => 500]));

        $respuesta->assertStatus(201);

        $forma = DeliveryZone::find($respuesta->json('model.id'));

        $this->assertSame(1, (int) $forma->costo_a_convenir);
        $this->assertSame(0.0, (float) $forma->price, 'Una forma a convenir no puede guardar precio: una tienda vieja lo cobraría.');
        $this->assertSame(1, (int) $respuesta->json('model.costo_a_convenir'));
        $this->assertSame($this->user_id(), (int) $forma->user_id);

        $sin_precio = $this->postJson('api/delivery-zone', $this->payload(['costo_a_convenir' => true, 'price' => null]));

        $sin_precio->assertStatus(201);
        $this->assertSame(0.0, (float) DeliveryZone::find($sin_precio->json('model.id'))->price);
    }

    /**
     * 2. Alta con costo fijo, y alta desde una pantalla vieja (sin la clave): precio del request
     *    y bandera en 0.
     *
     * @return void
     */
    public function test_alta_con_costo_fijo_o_sin_la_clave()
    {
        $fija = $this->postJson('api/delivery-zone', $this->payload(['name' => 'Moto', 'price' => 800]));

        $fija->assertStatus(201);
        $this->assertSame(0, (int) DeliveryZone::find($fija->json('model.id'))->costo_a_convenir);
        $this->assertSame(800.0, (float) DeliveryZone::find($fija->json('model.id'))->price);

        $vieja = $this->postJson('api/delivery-zone', $this->payload(['name' => 'Moto', 'price' => 900], ['costo_a_convenir']));

        $vieja->assertStatus(201);
        $this->assertSame(0, (int) DeliveryZone::find($vieja->json('model.id'))->costo_a_convenir);
        $this->assertSame(900.0, (float) DeliveryZone::find($vieja->json('model.id'))->price);
    }

    /**
     * 3. Editar: tildar pone 0; destildar vuelve al precio del request; un PUT sin la clave (o en
     *    null) conserva la bandera guardada, y con ella el precio en 0.
     *
     * @return void
     */
    public function test_editar_tildar_destildar_y_pantalla_vieja()
    {
        $forma = $this->forma(['price' => 1200, 'costo_a_convenir' => 0]);

        $this->putJson('api/delivery-zone/' . $forma->id, $this->payload(['costo_a_convenir' => 1, 'price' => 1200]))->assertStatus(200);

        $this->assertSame(1, (int) $forma->fresh()->costo_a_convenir);
        $this->assertSame(0.0, (float) $forma->fresh()->price);

        $this->putJson('api/delivery-zone/' . $forma->id, $this->payload(['costo_a_convenir' => 0, 'price' => 700]))->assertStatus(200);

        $this->assertSame(0, (int) $forma->fresh()->costo_a_convenir);
        $this->assertSame(700.0, (float) $forma->fresh()->price);

        // Una pantalla vieja sobre una forma a convenir: manda un precio y no manda la bandera.
        $a_convenir = $this->forma(['price' => 0, 'costo_a_convenir' => 1]);

        $this->putJson('api/delivery-zone/' . $a_convenir->id, $this->payload(['price' => 300], ['costo_a_convenir']))->assertStatus(200);

        $this->assertSame(1, (int) $a_convenir->fresh()->costo_a_convenir, 'Un PUT sin la clave apagó el costo a convenir.');
        $this->assertSame(0.0, (float) $a_convenir->fresh()->price, 'Un PUT sin la clave le puso precio a una forma a convenir.');

        $this->putJson('api/delivery-zone/' . $a_convenir->id, $this->payload(['price' => 300, 'costo_a_convenir' => null]))->assertStatus(200);

        $this->assertSame(1, (int) $a_convenir->fresh()->costo_a_convenir, 'Un PUT con la clave en null apagó el costo a convenir.');
        $this->assertSame(0.0, (float) $a_convenir->fresh()->price);

        // Y sobre una forma con costo fijo, la pantalla vieja edita el precio como siempre.
        $fija = $this->forma(['price' => 1200, 'costo_a_convenir' => 0]);

        $this->putJson('api/delivery-zone/' . $fija->id, $this->payload(['price' => 1500], ['costo_a_convenir']))->assertStatus(200);

        $this->assertSame(0, (int) $fija->fresh()->costo_a_convenir);
        $this->assertSame(1500.0, (float) $fija->fresh()->price);
    }

    /**
     * 4. Sin la columna (código nuevo, migración sin correr) el alta y la edición andan; a
     *    convenir, el precio queda igual en 0.
     *
     * @return void
     */
    public function test_sin_la_columna_el_alta_y_la_edicion_andan()
    {
        $self = $this;

        $this->sin_la_columna(self::TABLA, self::COLUMNA, function () use ($self) {

            $alta = $self->postJson('api/delivery-zone', $self->payload(['costo_a_convenir' => 1, 'price' => 500]));

            $alta->assertStatus(201);
            $self->assertSame(0.0, (float) DeliveryZone::find($alta->json('model.id'))->price);

            $id = $alta->json('model.id');

            $self->putJson('api/delivery-zone/' . $id, $self->payload(['costo_a_convenir' => 0, 'price' => 650]))->assertStatus(200);
            $self->assertSame(650.0, (float) DeliveryZone::find($id)->price);
        });
    }
}
