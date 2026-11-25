<?php

namespace Tests\Feature\Tienda;

use App\Models\Buyer;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Order;
use App\Models\OnlineConfiguration;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use Database\Seeders\PaymentMethodTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * Misión "tienda-cobro-tarjeta" (24/11/2026), parte de `empresa-api` (criterios CA16, CA17 y CA18
 * del PLAN): lo que el ERP tiene que ofrecerle a la tienda para cobrar con tarjeta dentro de la
 * página.
 *
 * Qué fija este archivo:
 *
 * - CA16: la casilla `online_configurations.mostrar_leyenda_de_descuento` (boolean, default 0) existe,
 *   se guarda cuando viene en el PUT y NO se pisa cuando el PUT no la trae (una SPA vieja no la conoce).
 * - CA17: el tipo de método de pago `Tarjeta Mercado Pago` se siembra por seeder (el general y uno
 *   suelto para producción, los dos idempotentes) y NUNCA por migración: una migración lo crearía en
 *   TODAS las bases al actualizar el ERP, antes de que la tienda nueva esté desplegada, y la tienda
 *   vieja trataría esa fila como un pago offline.
 * - CA18: un método de pago de ese tipo guarda el vínculo con cuenta corriente (de donde salen las
 *   cuotas) y el pedido trae el nombre del método elegido (la ficha del pedido lo muestra).
 *
 * ⚠️ NO hay DDL en este archivo (un ALTER hace commit implícito y rompe `DatabaseTransactions`): la
 * columna se verifica con `Schema::hasColumn` y la ausencia de la migración que inserta el tipo se
 * verifica leyendo los archivos de `database/migrations`.
 *
 * @group tienda
 * @group tienda_cobro_tarjeta
 */
class CobroConTarjetaEnLaTiendaTest extends EmpresaTestCase
{
    /** Nombre exacto del tipo nuevo: tienda-api lo compara tal cual (`TarjetaMercadoPagoHelper::es_tarjeta`). */
    const TIPO_TARJETA = 'Tarjeta Mercado Pago';

    /** Nombre de la columna de la leyenda de descuento por medio de pago. */
    const COLUMNA_LEYENDA = 'mostrar_leyenda_de_descuento';

    /** Clase del seeder suelto que se corre, una base por vez, en producción. */
    const SEEDER_SUELTO = 'Database\\Seeders\\PaymentMethodTypeTarjetaMercadoPagoSeeder';

    // ---------------------------------------------------------------------------------------------
    // CA16 — la casilla de la leyenda
    // ---------------------------------------------------------------------------------------------

    /**
     * La columna existe, es boolean y las filas previas quedan en 0 (apagada): ningún comercio ve la
     * leyenda hasta que el dueño la prenda.
     *
     * @return void
     */
    public function test_la_columna_de_la_leyenda_existe_con_default_0()
    {
        $this->assertTrue(
            Schema::hasColumn('online_configurations', self::COLUMNA_LEYENDA),
            'Falta la columna online_configurations.'.self::COLUMNA_LEYENDA.' (migración 2026_11_24_120000_...).'
        );

        // Metadatos de la columna tal cual están en la base.
        $columna = DB::selectOne(
            'SELECT DATA_TYPE AS tipo, COLUMN_TYPE AS tipo_completo, COLUMN_DEFAULT AS por_defecto, IS_NULLABLE AS acepta_null '.
            'FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['online_configurations', self::COLUMNA_LEYENDA]
        );

        // boolean en MySQL/MariaDB es tinyint(1).
        $this->assertSame('tinyint(1)', strtolower($columna->tipo_completo), 'La columna tiene que ser boolean (tinyint(1)).');
        $this->assertSame('0', (string) $columna->por_defecto, 'El default tiene que ser 0: apagada hasta que el dueño la prenda.');

        // Una configuración creada sin mencionar la columna nace en 0.
        $configuracion = OnlineConfiguration::create(['user_id' => $this->user_id()]);

        $this->assertSame(
            0,
            (int) DB::table('online_configurations')->where('id', $configuracion->id)->value(self::COLUMNA_LEYENDA),
            'Una configuración nueva tiene que nacer con la leyenda apagada.'
        );
    }

    /**
     * El PUT de la configuración guarda la casilla cuando viene (1 y 0) y NO la pisa cuando el PUT no
     * trae la clave (empresa-spa vieja, o cualquier cliente de la API que no la conoce).
     *
     * @return void
     */
    public function test_la_configuracion_guarda_la_leyenda_solo_si_viene()
    {
        $this->assertTrue(
            Schema::hasColumn('online_configurations', self::COLUMNA_LEYENDA),
            'Falta la columna online_configurations.'.self::COLUMNA_LEYENDA.': no hay dónde guardar la casilla.'
        );

        $configuracion = OnlineConfiguration::create(['user_id' => $this->user_id()]);

        // 1) Viene en 1: se guarda.
        $this->putJson('api/online-configuration/'.$configuracion->id, $this->payload_de_configuracion($configuracion, [
            self::COLUMNA_LEYENDA => 1,
        ]))->assertStatus(200);

        $this->assertSame(1, $this->leyenda_guardada($configuracion->id), 'El PUT con la casilla en 1 no la guardó.');

        // 2) El PUT NO la trae: no la pisa (sigue en 1).
        $this->putJson('api/online-configuration/'.$configuracion->id, $this->payload_de_configuracion($configuracion, [
            'instagram' => 'otro_usuario',
        ]))->assertStatus(200);

        $this->assertSame(
            1,
            $this->leyenda_guardada($configuracion->id),
            'Un PUT sin la clave pisó la casilla: una pantalla vieja apagaría la leyenda sola.'
        );

        // 3) Viene en 0 a propósito: se apaga.
        $this->putJson('api/online-configuration/'.$configuracion->id, $this->payload_de_configuracion($configuracion, [
            self::COLUMNA_LEYENDA => 0,
        ]))->assertStatus(200);

        $this->assertSame(0, $this->leyenda_guardada($configuracion->id), 'El PUT con la casilla en 0 no la apagó.');
    }

    // ---------------------------------------------------------------------------------------------
    // CA17 — el tipo «Tarjeta Mercado Pago» se siembra, no se migra
    // ---------------------------------------------------------------------------------------------

    /**
     * `PaymentMethodTypeSeeder` corrido dos veces deja exactamente una fila `MercadoPago` y una
     * `Tarjeta Mercado Pago` (instalaciones nuevas y demo).
     *
     * @return void
     */
    public function test_el_seeder_general_es_idempotente_y_crea_el_tipo()
    {
        // Parte de una base sin los dos tipos (la transacción del test revierte todo al terminar).
        PaymentMethodType::whereIn('name', ['MercadoPago', self::TIPO_TARJETA])->delete();

        (new PaymentMethodTypeSeeder())->run();
        (new PaymentMethodTypeSeeder())->run();

        $this->assertSame(
            1,
            PaymentMethodType::where('name', 'MercadoPago')->count(),
            'Después de correr el seeder dos veces tiene que haber exactamente 1 tipo MercadoPago.'
        );
        $this->assertSame(
            1,
            PaymentMethodType::where('name', self::TIPO_TARJETA)->count(),
            'Después de correr el seeder dos veces tiene que haber exactamente 1 tipo "'.self::TIPO_TARJETA.'".'
        );
    }

    /**
     * El seeder suelto (el que se corre en producción, base por base, después de actualizar la tienda)
     * existe, crea el tipo y es idempotente: no duplica ni toca los otros tipos.
     *
     * @return void
     */
    public function test_el_seeder_suelto_es_idempotente()
    {
        $this->assertTrue(
            class_exists(self::SEEDER_SUELTO),
            'Falta el seeder suelto '.self::SEEDER_SUELTO.' para activar el tipo en las bases de producción.'
        );

        PaymentMethodType::where('name', self::TIPO_TARJETA)->delete();

        // Un tipo que ya existe: el seeder suelto no lo tiene que tocar ni duplicar.
        $mercado_pago = PaymentMethodType::firstOrCreate(['name' => 'MercadoPago']);
        $total_de_tipos_otros = PaymentMethodType::where('name', '!=', self::TIPO_TARJETA)->count();

        $clase = self::SEEDER_SUELTO;
        (new $clase())->run();
        (new $clase())->run();

        $this->assertSame(
            1,
            PaymentMethodType::where('name', self::TIPO_TARJETA)->count(),
            'El seeder suelto tiene que dejar exactamente 1 tipo "'.self::TIPO_TARJETA.'" aunque se corra dos veces.'
        );
        $this->assertSame(
            $total_de_tipos_otros,
            PaymentMethodType::where('name', '!=', self::TIPO_TARJETA)->count(),
            'El seeder suelto no tiene que crear ni borrar otros tipos.'
        );
        $this->assertNotNull(PaymentMethodType::find($mercado_pago->id), 'El seeder suelto borró el tipo MercadoPago.');
    }

    /**
     * 🔴 Ninguna migración inserta el tipo: lo siembra un seeder y se activa base por base DESPUÉS de
     * actualizar la tienda. Si una migración lo creara, el `migrate` del upgrade del ERP lo pondría en
     * todas las bases antes de que la tienda nueva exista, y la tienda vieja lo mostraría como un
     * método de pago offline (pedido sin cobro).
     *
     * @return void
     */
    public function test_ninguna_migracion_inserta_el_tipo_de_tarjeta()
    {
        $archivos = glob(database_path('migrations/*.php'));

        $this->assertNotEmpty($archivos, 'No se encontraron migraciones para revisar.');

        // Variantes del nombre (con espacios, guiones bajos o sin separadores, en cualquier caso).
        $patron = '/tarjeta[\s_\-]*mercado[\s_\-]*pago/i';

        $ofensoras = [];
        foreach ($archivos as $archivo) {
            if (preg_match($patron, (string) file_get_contents($archivo))) {
                $ofensoras[] = basename($archivo);
            }
        }

        $this->assertSame(
            [],
            $ofensoras,
            'Estas migraciones mencionan el tipo "'.self::TIPO_TARJETA.'": el tipo se siembra por seeder, no por migración.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // CA18 — el ABM del método y el pedido
    // ---------------------------------------------------------------------------------------------

    /**
     * Un método de pago de tipo «Tarjeta Mercado Pago» se da de alta con el vínculo a un método de
     * cuenta corriente (de ahí salen las cuotas) y la respuesta lo devuelve; el tipo viaja con su nombre.
     *
     * @return void
     */
    public function test_un_metodo_de_tarjeta_guarda_el_vinculo()
    {
        $tipo = PaymentMethodType::firstOrCreate(['name' => self::TIPO_TARJETA]);
        $cuenta_corriente = CurrentAcountPaymentMethod::create(['name' => 'Tarjeta '.uniqid()]);

        $respuesta = $this->postJson('/api/payment-method', [
            'name'                            => 'Tarjeta de crédito o débito',
            'description'                     => 'Pagás en la página',
            'discount'                        => null,
            'payment_method_type_id'          => $tipo->id,
            'current_acount_payment_method_id' => $cuenta_corriente->id,
        ]);

        $respuesta->assertStatus(201);

        $this->assertSame(
            $cuenta_corriente->id,
            (int) $respuesta->json('model.current_acount_payment_method_id'),
            'El fullModel del alta no devuelve el vínculo con la cuenta corriente.'
        );
        $this->assertSame(
            self::TIPO_TARJETA,
            $respuesta->json('model.payment_method_type.name'),
            'El fullModel del método no trae el nombre del tipo (la SPA lo usa para decidir qué mostrar).'
        );
        $this->assertSame(
            $cuenta_corriente->id,
            (int) DB::table('payment_methods')->where('id', $respuesta->json('model.id'))->value('current_acount_payment_method_id'),
            'El vínculo no quedó guardado en la base.'
        );
    }

    /**
     * El pedido que lee la ficha del ERP trae el nombre del método de pago elegido (para mostrar
     * «Medio de pago elegido»), también cuando no es de tarjeta.
     *
     * @return void
     */
    public function test_el_pedido_trae_el_nombre_del_metodo()
    {
        $tipo = PaymentMethodType::firstOrCreate(['name' => self::TIPO_TARJETA]);

        $metodo = PaymentMethod::create([
            'name'                   => 'Tarjeta de crédito o débito',
            'description'            => 'Pagás en la página',
            'payment_method_type_id' => $tipo->id,
            'user_id'                => $this->user_id(),
        ]);

        $estado = OrderStatus::where('name', 'Sin confirmar')->first();
        if (is_null($estado)) {
            $estado = OrderStatus::create(['name' => 'Sin confirmar']);
        }

        // Comprador del pedido (la columna buyer_id es obligatoria).
        $comprador = Buyer::create([
            'name'    => 'Juan',
            'surname' => 'Perez',
            'email'   => 'comprador-'.uniqid().'@test.local',
            'user_id' => $this->user_id(),
        ]);

        $pedido = Order::create([
            'status'            => 'unconfirmed',
            'deliver'           => 0,
            'buyer_id'          => $comprador->id,
            'order_status_id'   => $estado->id,
            'user_id'           => $this->user_id(),
            'payment_method_id' => $metodo->id,
            'payment_id'        => 9001,
            'total'             => 105000,
        ]);

        $respuesta = $this->getJson('/api/order/'.$pedido->id);

        $respuesta->assertStatus(200);

        $this->assertSame(
            'Tarjeta de crédito o débito',
            $respuesta->json('model.payment_method.name'),
            'El pedido no trae payment_method.name.'
        );
        $this->assertSame(
            self::TIPO_TARJETA,
            $respuesta->json('model.payment_method.payment_method_type.name'),
            'El pedido no trae el tipo del método de pago (la fila «N° de pago» depende de él).'
        );
        $this->assertSame(9001, (int) $respuesta->json('model.payment_id'));
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers de fixture
    // ---------------------------------------------------------------------------------------------

    /**
     * Id del usuario dueño de la base de testing (el que autentica `EmpresaTestCase`).
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) auth()->user()->id;
    }

    /**
     * El valor crudo de la casilla en la base.
     *
     * @param int $id
     * @return int
     */
    protected function leyenda_guardada($id)
    {
        return (int) DB::table('online_configurations')->where('id', $id)->value(self::COLUMNA_LEYENDA);
    }

    /**
     * Payload de un PUT de configuración: el modelo actual entero (así lo manda siempre el formulario
     * de la SPA) con lo que se le pase por encima. Se saca la casilla del modelo base para que un test
     * de "no viene la clave" no la mande por arrastre.
     *
     * @param OnlineConfiguration  $configuracion
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function payload_de_configuracion(OnlineConfiguration $configuracion, array $extra = [])
    {
        $base = $configuracion->fresh()->toArray();
        unset($base[self::COLUMNA_LEYENDA]);

        return array_merge($base, $extra);
    }
}
