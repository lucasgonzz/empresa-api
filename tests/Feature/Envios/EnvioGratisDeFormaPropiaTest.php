<?php

namespace Tests\Feature\Envios;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfDocument\OrderPdfDocument;
use App\Models\DeliveryZone;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\EscondeColumnas;
use Tests\EmpresaTestCase;

/**
 * Misión truvari-carrito-entrega-pago (26/11/2026), lado empresa-api: envío gratis de una forma de
 * envío propia (`delivery_zones`), por monto de compra o por combo.
 *
 * Qué fija este archivo (criterios CA1, CA2 y CA12 del plan):
 *
 * - CA1: la migración `2026_11_26_130000_add_envio_gratis_to_delivery_zones_and_orders` agrega las
 *   cuatro columnas del contrato con sus tipos y defaults, se puede correr dos veces sin fallar y
 *   `down()` las saca.
 * - CA2: `POST`/`PUT api/delivery-zone` guardan `envio_gratis_desde` y `envio_gratis_con_combo`
 *   (normalizados), un PUT sin las claves conserva lo guardado, `costo_a_convenir` los limpia, y con
 *   las columnas escondidas (la ventana del deploy antes de migrar) el alta y la edición andan.
 * - CA12: el PDF del pedido imprime lo que se COBRÓ (la foto `orders.envio_zona_precio`), no el
 *   precio vivo de la forma de envío.
 *
 * Los tests de ABM y de PDF dependen de las columnas; si la migración todavía no está aplicada en la
 * base de testing fallan con un mensaje que lo dice, no con un error de SQL.
 *
 * @group envios
 */
class EnvioGratisDeFormaPropiaTest extends EmpresaTestCase
{
    use EscondeColumnas;

    /** Nombre (sin .php) de la migración del contrato. */
    const MIGRACION = '2026_11_26_130000_add_envio_gratis_to_delivery_zones_and_orders';

    /** Tabla de las formas de envío propias. */
    const TABLA = 'delivery_zones';

    /** Columnas nuevas de `delivery_zones`. */
    const COLUMNA_DESDE = 'envio_gratis_desde';
    const COLUMNA_CON_COMBO = 'envio_gratis_con_combo';

    /**
     * Devuelve las columnas que una corrida anterior cortada pudo dejar escondidas.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_columna_si_quedo_escondida(self::TABLA, self::COLUMNA_DESDE);
        $this->restaurar_columna_si_quedo_escondida(self::TABLA, self::COLUMNA_CON_COMBO);
    }

    /**
     * Id del dueño autenticado por `EmpresaTestCase`.
     *
     * @return int
     */
    protected function user_id()
    {
        return $this->app['auth']->user()->id;
    }

    /**
     * Frena el test con un mensaje claro si la migración todavía no se aplicó a la base de testing.
     *
     * @return void
     */
    protected function exigir_columnas_de_delivery_zones()
    {
        $this->assertTrue(
            Schema::hasColumn(self::TABLA, self::COLUMNA_DESDE) && Schema::hasColumn(self::TABLA, self::COLUMNA_CON_COMBO),
            'delivery_zones no tiene envio_gratis_desde / envio_gratis_con_combo: falta la migración ' . self::MIGRACION . '.'
        );
    }

    /**
     * Payload del formulario de la forma de envío.
     *
     * @param  array<string, mixed>  $cambios
     * @param  array<int, string>  $sin  Claves que el payload no trae (pantalla vieja).
     * @return array<string, mixed>
     */
    protected function payload(array $cambios = [], array $sin = [])
    {
        $payload = array_merge([
            'name'                   => 'Envio Truvari',
            'description'            => 'Córdoba capital',
            'price'                  => 7000,
            'costo_a_convenir'       => 0,
            'envio_gratis_desde'     => '80000',
            'envio_gratis_con_combo' => 1,
        ], $cambios);

        foreach ($sin as $clave) {
            unset($payload[$clave]);
        }

        return $payload;
    }

    /**
     * Una forma de envío del comercio, directo en la base (sin las columnas nuevas: se las pone
     * cada test con `update` cuando las necesita).
     *
     * @param  array<string, mixed>  $atributos
     * @return DeliveryZone
     */
    protected function forma(array $atributos = [])
    {
        return DeliveryZone::create(array_merge([
            'user_id'          => $this->user_id(),
            'name'             => 'Envio Truvari',
            'description'      => null,
            'price'            => 7000,
            'costo_a_convenir' => 0,
        ], $atributos));
    }

    // ── CA1: migración ──────────────────────────────────────────────────────────────────────

    /**
     * Definición de una columna según information_schema, normalizada para comparar entre MySQL
     * (default null) y MariaDB (default 'NULL' como texto).
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @return array<string, mixed>|null  null si la columna no existe.
     */
    protected function definicion_de_columna($tabla, $columna)
    {
        $fila = DB::selectOne(
            'SELECT COLUMN_TYPE AS tipo, IS_NULLABLE AS nulo, COLUMN_DEFAULT AS por_defecto
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabla, $columna]
        );

        if (is_null($fila)) {
            return null;
        }

        $por_defecto = $fila->por_defecto;
        if ($por_defecto === 'NULL') {
            $por_defecto = null;
        }

        return ['tipo' => strtolower($fila->tipo), 'nulo' => $fila->nulo, 'por_defecto' => $por_defecto];
    }

    /**
     * Las cuatro columnas del contrato (§6.1 del plan), tabla => columnas.
     *
     * @return array<string, array<int, string>>
     */
    protected function columnas_del_contrato()
    {
        return [
            'delivery_zones' => ['envio_gratis_desde', 'envio_gratis_con_combo'],
            'orders'         => ['envio_zona_precio', 'envio_zona_gratis_motivo'],
        ];
    }

    /**
     * Saca las columnas del contrato que existan (deja la base como antes de migrar).
     *
     * @return void
     */
    protected function sacar_columnas_del_contrato()
    {
        foreach ($this->columnas_del_contrato() as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    Schema::table($tabla, function ($table) use ($columna) {
                        $table->dropColumn($columna);
                    });
                }
            }
        }
    }

    /**
     * Instancia la migración del contrato desde su archivo (clase con nombre o anónima).
     *
     * @return \Illuminate\Database\Migrations\Migration
     */
    protected function instanciar_migracion()
    {
        $ruta = database_path('migrations/' . self::MIGRACION . '.php');

        $this->assertFileExists($ruta, 'Falta el archivo de la migración ' . self::MIGRACION . '.php.');

        $migrador = $this->app['migrator'];
        $devuelto = require_once $ruta;

        if (is_object($devuelto)) {
            return $devuelto;
        }

        return $migrador->resolve(self::MIGRACION);
    }

    /**
     * Nombres de índice (SHOW INDEX) que incluyen alguna de las columnas del contrato: el contrato
     * dice "sin índices ni FK".
     *
     * @return array<int, string>
     */
    protected function indices_sobre_las_columnas_nuevas()
    {
        $indices = [];
        foreach ($this->columnas_del_contrato() as $tabla => $columnas) {
            foreach (DB::select('SHOW INDEX FROM `' . $tabla . '`') as $indice) {
                if (in_array($indice->Column_name, $columnas, true)) {
                    $indices[] = $tabla . '.' . $indice->Key_name;
                }
            }
        }

        return $indices;
    }

    /**
     * CA1: `up()` agrega las 4 columnas con tipo y default del contrato, correrlo dos veces no
     * falla ni cambia nada, y `down()` las saca. El DDL de MySQL confirma la transacción del test,
     * así que el estado inicial de las columnas se devuelve a mano al final.
     *
     * @return void
     */
    public function test_la_migracion_agrega_las_columnas_y_es_idempotente()
    {
        $estaban = [];
        foreach ($this->columnas_del_contrato() as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $estaban[$tabla . '.' . $columna] = Schema::hasColumn($tabla, $columna);
            }
        }

        try {
            $migracion = $this->instanciar_migracion();

            // Parte de una base sin las columnas, que es la de producción antes de actualizar.
            $this->sacar_columnas_del_contrato();

            $migracion->up();

            $this->assertSame(
                ['tipo' => 'decimal(20,2)', 'nulo' => 'YES', 'por_defecto' => null],
                $this->definicion_de_columna('delivery_zones', 'envio_gratis_desde'),
                'delivery_zones.envio_gratis_desde: decimal(20,2) nullable, default null.'
            );
            $con_combo = $this->definicion_de_columna('delivery_zones', 'envio_gratis_con_combo');
            $this->assertSame('tinyint(1)', $con_combo['tipo'], 'delivery_zones.envio_gratis_con_combo: boolean.');
            $this->assertSame('NO', $con_combo['nulo']);
            $this->assertSame('0', (string) $con_combo['por_defecto'], 'envio_gratis_con_combo nace en 0.');
            $this->assertSame(
                ['tipo' => 'decimal(20,2)', 'nulo' => 'YES', 'por_defecto' => null],
                $this->definicion_de_columna('orders', 'envio_zona_precio'),
                'orders.envio_zona_precio: decimal(20,2) nullable, default null.'
            );
            $this->assertSame(
                ['tipo' => 'varchar(20)', 'nulo' => 'YES', 'por_defecto' => null],
                $this->definicion_de_columna('orders', 'envio_zona_gratis_motivo'),
                'orders.envio_zona_gratis_motivo: string(20) nullable, default null.'
            );
            $this->assertSame([], $this->indices_sobre_las_columnas_nuevas(), 'Las columnas nuevas van sin índices.');

            // Una fila que ya existía conserva su costo y no pasa a ser gratis de golpe.
            $forma = DeliveryZone::create(['user_id' => $this->user_id(), 'name' => 'Vieja', 'price' => 500, 'costo_a_convenir' => 0]);
            $this->assertNull(DeliveryZone::find($forma->id)->envio_gratis_desde);
            $this->assertSame(0, (int) DeliveryZone::find($forma->id)->envio_gratis_con_combo);
            DeliveryZone::where('id', $forma->id)->delete();

            // Segunda vuelta: la base la comparten dos frentes, el migrador puede reintentar.
            $migracion->up();
            $this->assertNotNull($this->definicion_de_columna('delivery_zones', 'envio_gratis_desde'));
            $this->assertNotNull($this->definicion_de_columna('orders', 'envio_zona_gratis_motivo'));

            $migracion->down();
            foreach ($this->columnas_del_contrato() as $tabla => $columnas) {
                foreach ($columnas as $columna) {
                    $this->assertFalse(Schema::hasColumn($tabla, $columna), 'down() tendría que sacar ' . $tabla . '.' . $columna);
                }
            }

            // down() sobre una base que ya no las tiene tampoco falla.
            $migracion->down();
        } finally {
            // Devuelve la base de testing al estado con el que llegó este test.
            $this->sacar_columnas_del_contrato();
            if (in_array(true, $estaban, true) && is_file(database_path('migrations/' . self::MIGRACION . '.php'))) {
                $this->instanciar_migracion()->up();
            }
            // El DDL confirmó la transacción del test, pero Laravel sigue contando su nivel: se lo
            // reinicia a 0 (sin savepoints colgados) y recién ahí se abre la que cierra el tearDown.
            DB::connection()->rollBack(0);
            DB::beginTransaction();
        }
    }

    // ── CA2: ABM de la forma de envío ───────────────────────────────────────────────────────

    /**
     * CA2: el alta guarda `80000.00` y `1`; el desde vacío, 0, negativo o no numérico se guarda
     * null; la casilla acepta booleanos y 0/1.
     *
     * @return void
     */
    public function test_alta_y_edicion_guardan_el_envio_gratis()
    {
        $this->exigir_columnas_de_delivery_zones();

        $alta = $this->postJson('api/delivery-zone', $this->payload());

        $alta->assertStatus(201);

        $forma = DeliveryZone::find($alta->json('model.id'));
        $this->assertSame('80000.00', (string) $forma->envio_gratis_desde);
        $this->assertSame(1, (int) $forma->envio_gratis_con_combo);
        $this->assertSame(7000.0, (float) $forma->price);
        $this->assertSame('80000.00', (string) $alta->json('model.envio_gratis_desde'), 'La respuesta trae el modelo completo.');
        $this->assertSame(1, (int) $alta->json('model.envio_gratis_con_combo'));

        // Desde inválido o sin sentido: nunca gratis por monto.
        foreach (['', 0, '0', -5, '-5', 'abc', null] as $invalido) {
            $respuesta = $this->postJson('api/delivery-zone', $this->payload(['envio_gratis_desde' => $invalido, 'envio_gratis_con_combo' => 0]));

            $respuesta->assertStatus(201);
            $guardada = DeliveryZone::find($respuesta->json('model.id'));
            $this->assertNull($guardada->envio_gratis_desde, 'envio_gratis_desde ' . var_export($invalido, true) . ' tendría que guardarse null.');
            $this->assertSame(0, (int) $guardada->envio_gratis_con_combo);
        }

        // Montos con decimales se redondean a 2.
        $decimales = $this->postJson('api/delivery-zone', $this->payload(['envio_gratis_desde' => '1234.5678', 'envio_gratis_con_combo' => true]));
        $decimales->assertStatus(201);
        $this->assertSame('1234.57', (string) DeliveryZone::find($decimales->json('model.id'))->envio_gratis_desde);
        $this->assertSame(1, (int) DeliveryZone::find($decimales->json('model.id'))->envio_gratis_con_combo);

        // Edición: cambia el monto y apaga la casilla.
        $this->putJson('api/delivery-zone/' . $forma->id, $this->payload(['envio_gratis_desde' => 95000, 'envio_gratis_con_combo' => 0]))->assertStatus(200);
        $this->assertSame('95000.00', (string) $forma->fresh()->envio_gratis_desde);
        $this->assertSame(0, (int) $forma->fresh()->envio_gratis_con_combo);

        // Edición: vaciar el monto lo deja en null.
        $this->putJson('api/delivery-zone/' . $forma->id, $this->payload(['envio_gratis_desde' => '', 'envio_gratis_con_combo' => 1]))->assertStatus(200);
        $this->assertNull($forma->fresh()->envio_gratis_desde);
        $this->assertSame(1, (int) $forma->fresh()->envio_gratis_con_combo);
    }

    /**
     * CA2: un PUT sin las dos claves (pantalla abierta desde antes del deploy) conserva lo
     * guardado; y con `costo_a_convenir` las dos quedan en null y 0, en alta y en edición.
     *
     * @return void
     */
    public function test_pantalla_vieja_conserva_y_a_convenir_limpia()
    {
        $this->exigir_columnas_de_delivery_zones();

        $forma = $this->forma();
        $forma->update(['envio_gratis_desde' => 80000, 'envio_gratis_con_combo' => 1]);

        // Pantalla vieja: no manda ni el monto ni la casilla.
        $this->putJson(
            'api/delivery-zone/' . $forma->id,
            $this->payload(['price' => 7500], ['envio_gratis_desde', 'envio_gratis_con_combo'])
        )->assertStatus(200);

        $this->assertSame('80000.00', (string) $forma->fresh()->envio_gratis_desde, 'Un PUT sin la clave borró el mínimo de envío gratis.');
        $this->assertSame(1, (int) $forma->fresh()->envio_gratis_con_combo, 'Un PUT sin la clave apagó la casilla de combos.');
        $this->assertSame(7500.0, (float) $forma->fresh()->price);

        // Un alta sin las claves nace sin envío gratis.
        $alta_vieja = $this->postJson('api/delivery-zone', $this->payload([], ['envio_gratis_desde', 'envio_gratis_con_combo']));
        $alta_vieja->assertStatus(201);
        $this->assertNull(DeliveryZone::find($alta_vieja->json('model.id'))->envio_gratis_desde);
        $this->assertSame(0, (int) DeliveryZone::find($alta_vieja->json('model.id'))->envio_gratis_con_combo);

        // A convenir en una edición: el costo es del vendedor, no hay "gratis" que ofrecer.
        $this->putJson('api/delivery-zone/' . $forma->id, $this->payload(['costo_a_convenir' => 1]))->assertStatus(200);

        $this->assertSame(1, (int) $forma->fresh()->costo_a_convenir);
        $this->assertSame(0.0, (float) $forma->fresh()->price);
        $this->assertNull($forma->fresh()->envio_gratis_desde, 'A convenir tiene que limpiar el mínimo de envío gratis.');
        $this->assertSame(0, (int) $forma->fresh()->envio_gratis_con_combo, 'A convenir tiene que apagar la casilla de combos.');

        // A convenir en un alta.
        $a_convenir = $this->postJson('api/delivery-zone', $this->payload(['name' => 'Acordar', 'costo_a_convenir' => 1]));
        $a_convenir->assertStatus(201);
        $this->assertNull(DeliveryZone::find($a_convenir->json('model.id'))->envio_gratis_desde);
        $this->assertSame(0, (int) DeliveryZone::find($a_convenir->json('model.id'))->envio_gratis_con_combo);

        // Una forma a convenir editada por una pantalla vieja (sin la clave a convenir) sigue limpia.
        $this->putJson('api/delivery-zone/' . $forma->id, $this->payload(['envio_gratis_desde' => 50000], ['costo_a_convenir']))->assertStatus(200);
        $this->assertNull($forma->fresh()->envio_gratis_desde, 'Una forma a convenir no puede quedar con mínimo de envío gratis.');
    }

    /**
     * CA2: con las dos columnas escondidas (código nuevo, migración sin correr) el alta (201) y la
     * edición (200) andan igual y guardan el resto de los datos.
     *
     * @return void
     */
    public function test_sin_las_columnas_el_abm_anda()
    {
        $this->exigir_columnas_de_delivery_zones();

        $self = $this;

        $this->sin_la_columna(self::TABLA, self::COLUMNA_DESDE, function () use ($self) {
            $self->sin_la_columna(self::TABLA, self::COLUMNA_CON_COMBO, function () use ($self) {

                $alta = $self->postJson('api/delivery-zone', $self->payload());

                $alta->assertStatus(201);
                $id = $alta->json('model.id');
                $self->assertSame(7000.0, (float) DeliveryZone::find($id)->price);

                $self->putJson('api/delivery-zone/' . $id, $self->payload(['price' => 7600]))->assertStatus(200);
                $self->assertSame(7600.0, (float) DeliveryZone::find($id)->price);

                $self->putJson('api/delivery-zone/' . $id, $self->payload(['costo_a_convenir' => 1]))->assertStatus(200);
                $self->assertSame(0.0, (float) DeliveryZone::find($id)->price);
            });
        });
    }

    // ── CA12: PDF del pedido ────────────────────────────────────────────────────────────────

    /**
     * Pedido en memoria con una forma de envío propia y la foto del cobro. No toca la base: el
     * renglón "Envío" del PDF sólo lee la relación y los atributos del pedido.
     *
     * @param  array<string, mixed>  $zona      Atributos de la forma (con `price`).
     * @param  array<string, mixed>  $pedido    Atributos del pedido (la foto, si la hay).
     * @return OrderPdfDocument
     */
    protected function documento_del_pedido(array $zona, array $pedido)
    {
        $forma = new DeliveryZone(array_merge(['name' => 'Envio Truvari', 'price' => 7000, 'costo_a_convenir' => 0], $zona));

        $orden = new Order();
        foreach ($pedido as $clave => $valor) {
            $orden->setAttribute($clave, $valor);
        }
        $orden->setRelation('delivery_zone', $forma);

        return new OrderPdfDocument($orden);
    }

    /**
     * CA12: el PDF imprime lo que se cobró. Snapshot 0 -> "Gratis" aunque la forma valga $7.000;
     * snapshot 7000 -> el importe, aunque la forma hoy valga otra cosa; sin snapshot -> el precio
     * vivo como hasta ahora; a convenir -> sin cambios.
     *
     * @return void
     */
    public function test_el_pdf_del_pedido_usa_lo_que_se_cobro()
    {
        // Foto en 0: se cobró gratis (monto o combo).
        $this->assertSame(
            'Gratis',
            $this->documento_del_pedido(['price' => 7000], ['envio_zona_precio' => '0.00', 'envio_zona_gratis_motivo' => 'monto'])->texto_envio(),
            'Con la foto en 0 el PDF tiene que decir Gratis, no el precio vivo de la forma.'
        );
        $this->assertSame(
            'Gratis',
            $this->documento_del_pedido(['price' => 7000], ['envio_zona_precio' => 0, 'envio_zona_gratis_motivo' => 'combo'])->texto_envio()
        );

        // Foto en 7000: el importe cobrado, aunque la forma cambió de precio después de la compra.
        $this->assertSame(
            '$' . Numbers::price(7000),
            $this->documento_del_pedido(['price' => 7000], ['envio_zona_precio' => '7000.00'])->texto_envio()
        );
        $this->assertSame(
            '$' . Numbers::price(7000),
            $this->documento_del_pedido(['price' => 9500], ['envio_zona_precio' => '7000.00'])->texto_envio(),
            'La foto manda sobre el precio actual de la forma.'
        );

        // Sin foto (pedido viejo o tienda vieja): el precio vivo, como hoy.
        $this->assertSame(
            '$' . Numbers::price(7000),
            $this->documento_del_pedido(['price' => 7000], [])->texto_envio()
        );
        $this->assertSame(
            '$' . Numbers::price(7000),
            $this->documento_del_pedido(['price' => 7000], ['envio_zona_precio' => null])->texto_envio()
        );

        // A convenir: sin cambios, aunque venga una foto.
        $this->assertSame(
            'Envio Truvari - A convenir',
            $this->documento_del_pedido(['price' => 0, 'costo_a_convenir' => 1], [])->texto_envio()
        );
        $this->assertSame(
            'Envio Truvari - A convenir',
            $this->documento_del_pedido(['price' => 0, 'costo_a_convenir' => 1], ['envio_zona_precio' => '0.00'])->texto_envio()
        );
    }
}
