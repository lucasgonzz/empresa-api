<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AvisosDelDuenoIaHelper;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\DeliveryZone;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Misión asistente-diario-20261128, P1 (CA1, CA2, CA3) — TESTS DE ACEPTACIÓN: el aviso de pedido nuevo de la
 * tienda trae el detalle completo que necesita el dueño para despachar sin abrir el sistema.
 *
 * EL CASO REAL (Truvari, 27/11/2026): el aviso decía sólo «N° 78 de <comprador>, por $T» y los primeros 3 artículos
 * sin precio; el dueño tenía que preguntar teléfono, dirección, fecha de entrega y observaciones.
 *
 * Qué fija este archivo:
 *   1. CA1: el texto exacto del ítem `pedido_tienda` (comprador con teléfono y mail, entrega, dirección, fecha,
 *      un renglón por artículo con precio unitario y subtotal, total, pago y observaciones).
 *   2. CA2: hasta 15 renglones («… y K artículos más»), observación cortada a 300 caracteres y texto total que
 *      nunca pasa de 3.500 caracteres (WhatsApp corta a 4.096).
 *   3. CA3: sin datos opcionales se omiten las líneas; el `resumen` y la `plantilla` (ventana cerrada) no cambian.
 *
 * 🔴 Estado esperado en el commit de tests: los tres casos con formato nuevo están en ROJO (hoy el texto es el
 * viejo: una sola línea de comprador con total, «1 artículo: …» y «Respondeme este mensaje…»). El último caso
 * (`sin_datos_omite_las_lineas_y_la_plantilla_no_cambia`) mezcla un rojo (el texto nuevo) con una guarda que ya
 * pasa hoy (la plantilla y el resumen no cambian).
 *
 * Reloj fijo (RecordatoriosTestCase): viernes 16/10/2026 10:30. Sin red.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Aviso_de_pedido_con_detalle_completo_Test extends RecordatoriosTestCase
{
    /** @var Buyer El comprador «Gastón Buteler» de los pedidos. */
    protected $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin la fila de configuración de la tienda el aviso queda apagado: se crea para que el ítem se ofrezca.
        OnlineConfiguration::create(['user_id' => $this->comercio->id]);

        $this->comprador = Buyer::create([
            'name'    => 'Gastón',
            'surname' => 'Buteler',
            'phone'   => '3516762999',
            'email'   => 'gaston@testing.local',
            'user_id' => $this->comercio->id,
        ]);

        // El cursor al día: lo que se cree después es un pedido nuevo.
        DB::table('users')->where('id', $this->comercio->id)->update([
            'agente_aviso_pedidos_cursor' => AvisosDelDuenoIaHelper::ultimo_id('orders'),
            'agente_aviso_pedidos_desde'  => '2026-10-16 09:00:00',
        ]);
    }

    /* ─────────────────────────────── escena ─────────────────────────────── */

    /**
     * Un pedido de la tienda con sus renglones.
     *
     * @param  int  $num  Número de pedido.
     * @param  array  $renglones  Lista de [nombre, precio, cantidad].
     * @param  array  $columnas  Columnas de `orders` a pisar.
     * @param  \App\Models\Buyer|null  $comprador  El comprador (por defecto Gastón Buteler).
     * @return \App\Models\Order
     */
    protected function pedido($num, array $renglones, array $columnas = [], $comprador = null)
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:05:00'));

        $estado = OrderStatus::first();
        $comprador = is_null($comprador) ? $this->comprador : $comprador;

        $pedido = Order::create(array_merge([
            'num'             => $num,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $comprador->id,
            'order_status_id' => is_null($estado) ? 1 : $estado->id,
            'user_id'         => $this->comercio->id,
            'total'           => 0,
        ], $columnas));

        foreach ($renglones as $renglon) {

            $articulo = Article::create(['name' => $renglon[0], 'user_id' => $this->comercio->id, 'status' => 'active']);
            $pedido->articles()->attach($articulo->id, ['price' => $renglon[1], 'amount' => $renglon[2]]);
        }

        Carbon::setTestNow(Carbon::parse(self::AHORA));

        return $pedido->fresh();
    }

    /**
     * El `texto` del ítem de pedido que ofrece `recordatorios-pendientes` para ese pedido.
     *
     * @param  \App\Models\Order  $pedido
     * @return array  El ítem completo (texto, resumen, plantilla...).
     */
    protected function item_del_pedido(Order $pedido)
    {
        $respuesta = $this->pendientes();
        $respuesta->assertStatus(200);

        foreach ($respuesta->json('recordatorios') as $item) {

            if (strpos($item['id'], AvisosDelDuenoIaHelper::PREFIJO_PEDIDO . $pedido->id . '-') === 0) {
                return $item;
            }
        }

        $this->fail('recordatorios-pendientes no ofrece el pedido N° ' . $pedido->num . '.');
    }

    /**
     * El pedido N° 83 de los datos comunes de P1/P2: envío propio a «Envío Truvari», dirección con piso y
     * referencia, fecha de entrega, pago en efectivo y observaciones del comprador.
     *
     * @return \App\Models\Order
     */
    protected function pedido_83()
    {
        $zona = DeliveryZone::create(['name' => 'Envío Truvari', 'price' => 0, 'user_id' => $this->comercio->id]);
        $metodo = PaymentMethod::create(['name' => 'Efectivo', 'user_id' => $this->comercio->id]);

        return $this->pedido(83, [['Malbec Reserva', 94000, 2], ['Torrontés', 12500.50, 3]], [
            'deliver'           => 1,
            'delivery_zone_id'  => $zona->id,
            'payment_method_id' => $metodo->id,
            'fecha_entrega'     => '2026-11-27 00:00:00',
            'description'       => 'Tocar timbre',
            'envio_direccion'   => [
                'formatted_address' => 'Ramón Cárcano 251, Córdoba',
                'apartment'         => 'Torre B9 1B',
                'reference'         => 'Country, tiene guardia',
            ],
        ]);
    }

    /* ─────────────────────────────── CA1 ─────────────────────────────── */

    /**
     * 🔴 CA1 — El texto del aviso es exactamente el del plan: comprador con teléfono y mail, entrega, dirección,
     * fecha, un renglón por artículo con precio unitario y subtotal, total, pago y observaciones.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_aviso_trae_comprador_entrega_renglones_total_pago_y_observaciones()
    {
        $pedido = $this->pedido_83();

        $esperado = "🛒 Entró un pedido nuevo en la tienda: *N° 83*\n"
            . "Comprador: Gastón Buteler · Tel. 3516762999 · gaston@testing.local\n"
            . "Entrega: Envío propio: Envío Truvari\n"
            . "Dirección: Ramón Cárcano 251, Córdoba, Torre B9 1B (Country, tiene guardia)\n"
            . "Fecha de entrega: 27/11/2026\n"
            . "Artículos:\n"
            . "• Malbec Reserva x2 · \$94.000 c/u = \$188.000\n"
            . "• Torrontés x3 · \$12.500,50 c/u = \$37.501,50\n"
            . "Total: *\$225.501,50*\n"
            . "Pago: Efectivo\n"
            . "Observaciones del comprador: «Tocar timbre»";

        $this->assertSame($esperado, $this->item_del_pedido($pedido)['texto']);
    }

    /* ─────────────────────────────── CA2 ─────────────────────────────── */

    /**
     * 🔴 CA2 — Con 18 renglones salen 15 y la línea que dice cuántos faltan; con una observación de 2.000
     * caracteres se corta a 300 con «…» y el texto no pasa de 3.500.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_mas_de_15_renglones_corta_y_dice_cuantos_faltan()
    {
        $renglones = [];

        for ($i = 1; $i <= 18; $i++) {
            $renglones[] = ['Art ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 100, 1];
        }

        $texto = $this->item_del_pedido($this->pedido(84, $renglones))['texto'];

        $this->assertStringContainsString('• Art 15 x1 · $100 c/u = $100', $texto);
        $this->assertStringNotContainsString('Art 16', $texto, 'Sólo van los primeros 15 renglones.');
        $this->assertStringContainsString('… y 3 artículos más (respondeme y te paso el resto).', $texto);
        $this->assertStringContainsString('Total: *$1.800*', $texto, 'El total es el de todo el pedido, no el de lo que se ve.');

        // 15 renglones de 60 caracteres de nombre y una observación de 2.000 caracteres.
        $largos = [];

        for ($i = 1; $i <= 15; $i++) {
            $largos[] = [str_pad('Producto ' . $i . ' ', 60, 'x'), 100, 1];
        }

        $observacion = str_repeat('Observación muy larga. ', 90); // más de 2.000 caracteres

        $texto = $this->item_del_pedido($this->pedido(85, $largos, ['description' => $observacion]))['texto'];

        $this->assertLessThanOrEqual(3500, mb_strlen($texto));

        $this->assertSame(1, preg_match('/Observaciones del comprador: «(.*)»$/su', $texto, $m), 'Tiene que traer la línea de observaciones al final.');
        $this->assertLessThanOrEqual(300, mb_strlen($m[1]), 'La observación entra con 300 caracteres como máximo.');
        $this->assertSame('…', mb_substr($m[1], -1), 'Una observación cortada termina en «…».');
    }

    /**
     * 🔴 CA2 — Aunque el pedido traiga datos enormes (una dirección de 2.000 caracteres), el texto no pasa de
     * 3.500 caracteres: se sacan renglones del final y se suman a «y K artículos más». Entre los renglones que
     * quedan y los que faltan siempre están los 15.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_texto_no_pasa_el_largo_maximo()
    {
        $renglones = [];

        for ($i = 1; $i <= 15; $i++) {
            $renglones[] = [str_pad('Producto ' . $i . ' ', 60, 'x'), 100, 1];
        }

        $pedido = $this->pedido(86, $renglones, [
            'deliver'         => 1,
            'description'     => str_repeat('Observación. ', 100),
            'envio_direccion' => ['formatted_address' => str_repeat('Calle larguísima 123, ', 90)],
        ]);

        $texto = $this->item_del_pedido($pedido)['texto'];

        $this->assertLessThanOrEqual(3500, mb_strlen($texto), 'WhatsApp corta a 4.096: el aviso se guarda con margen.');

        // Los renglones que quedan más los que dice «y K artículos más» suman los 15 del pedido.
        $visibles = preg_match_all('/^• /mu', $texto);
        $faltan = preg_match('/… y (\d+) artículos? más/u', $texto, $m) ? (int) $m[1] : 0;

        $this->assertSame(15, $visibles + $faltan, 'Ningún renglón se pierde sin avisar.');
        $this->assertGreaterThan(0, $faltan, 'Con tanto dato, algún renglón tuvo que quedar afuera.');
        $this->assertStringContainsString('Total: *$1.500*', $texto);
    }

    /* ─────────────────────────────── CA3 ─────────────────────────────── */

    /**
     * 🔴 CA3 — Retiro por local, comprador sin teléfono ni email, sin fecha, sin observaciones y sin método de
     * pago: las líneas sin dato se omiten. El `resumen` y la `plantilla` de la ventana cerrada son los de hoy
     * (esa parte ya pasa en develop: es la guarda de que el cambio no los toca).
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_datos_omite_las_lineas_y_la_plantilla_no_cambia()
    {
        $sin_contacto = Buyer::create(['name' => 'Laura', 'surname' => 'Pérez', 'user_id' => $this->comercio->id]);

        $item = $this->item_del_pedido($this->pedido(87, [['Malbec Reserva', 94000, 2]], [], $sin_contacto));
        $texto = $item['texto'];

        $this->assertStringContainsString('Comprador: Laura Pérez · Tel. sin cargar' . "\n", $texto);
        $this->assertStringContainsString("\nEntrega: Retiro por local\n", $texto);
        $this->assertStringContainsString("\nPago: no indicado", $texto);
        $this->assertStringContainsString('• Malbec Reserva x2 · $94.000 c/u = $188.000', $texto);
        $this->assertStringNotContainsString('Dirección:', $texto);
        $this->assertStringNotContainsString('Fecha de entrega:', $texto);
        $this->assertStringNotContainsString('Observaciones', $texto);
        $this->assertStringNotContainsString('@', $texto, 'Sin email no hay nada después del teléfono.');

        // Guarda de regresión (verde hoy): la ventana cerrada sigue usando la plantilla con el mismo resumen.
        $this->assertSame('tarea_del_asistente', $item['plantilla']);
        $this->assertSame('el aviso del pedido N° 87 de la tienda: Laura Pérez, $188.000, retiro por local, pago no indicado, 1 artículo', $item['resumen']);
    }
}
