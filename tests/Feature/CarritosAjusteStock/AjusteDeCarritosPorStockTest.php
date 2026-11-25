<?php

namespace Tests\Feature\CarritosAjusteStock;

use App\Models\Buyer;
use App\Models\Cart;
use App\Models\Message;
use App\Models\OnlineConfiguration;
use App\Models\ArticleVariant;
use App\Models\Address;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Stock\AuditoriaStockTestCase;

/**
 * Misión `carritos-ajuste-stock` (25/11/2026): cuando el stock de un artículo baja (venta del sistema,
 * pedido de otro comprador confirmado) los carritos ACTIVOS de la tienda que lo tienen pasan a la cantidad
 * máxima que se puede comprar en ese momento, el comprador recibe un mail, y cuando el negocio repone el
 * stock el renglón vuelve a su cantidad original (si la casilla `completar_al_reponer` sigue marcada).
 *
 * Todo corre por el camino real: `POST api/stock-movement` -> SetArticleStock -> CheckCartAmount ->
 * CartArticleAmountInsificienteHelper -> CartStockAdjustmentHelper. Nada llama al helper nuevo directo.
 *
 * CONTRATO DEL JOB (lo fijan estos tests; el constructor y las propiedades públicas son parte del contrato):
 *   new \App\Jobs\ProcessSendCartAdjustedMail(
 *       $owner_id, $buyer_email, $buyer_name, $article_id, $variant_id, $type, $requested_amount, $new_amount
 *   )
 * con una propiedad pública homónima por argumento. `$type` es 'recortado' o 'restaurado'; `$requested_amount`
 * es X (lo que el comprador agregó: amount + amount_insuficiente) y `$new_amount` es Y (lo que quedó en el carrito).
 * Se despacha con `ProcessSendCartAdjustedMail::dispatch(...)` (los tests usan Bus::fake sólo de ese job).
 *
 * Cada test dice en su docblock por qué falla hoy (antes de la construcción). Los tests que dependen de las
 * columnas nuevas las escriben sólo si existen, para que el rojo sea FUNCIONAL (renglón sin recortar, job no
 * despachado, Message creado) y no un "Unknown column"; al final de esos tests una aserción de esquema impide que
 * un test pase en vacío si la columna no está.
 *
 * El caso Únicas con `ignorar_stock` (CA1 del plan) ya lo cubre `Tests\Feature\Tienda\CarritosSinControlDeStockTest`
 * (misión unicas-carritos-sin-control-stock, ya en la base): no se duplica acá. El matiz "Mov entre depositos no
 * ajusta" tampoco tiene test propio: ese movimiento no cambia el stock total, así que ningún test lo distinguiría
 * de "no hubo recorte" (sería un test vacío).
 *
 * IMPORTANTE: PHP 7.4 estricto (ver RESTRICCIONES.md de la misión): nada de sintaxis de PHP 8.
 *
 * @group tienda
 */
class AjusteDeCarritosPorStockTest extends AuditoriaStockTestCase
{
    /** Clase del job de mail (como string: todavía no existe antes de la construcción). */
    const JOB_DE_MAIL = 'App\Jobs\ProcessSendCartAdjustedMail';

    /**
     * Intercepta sólo el job de mail del ajuste: cualquier otro job de la venta corre normal.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([self::JOB_DE_MAIL]);
    }

    /**
     * Deja la configuración online del dueño con los flags indicados (la crea de cero). Los flags de las
     * columnas nuevas sólo se escriben si la columna existe (si no, el test falla por comportamiento).
     *
     * @param array $flags Claves de online_configurations a pisar.
     * @return \App\Models\OnlineConfiguration
     */
    protected function configurar_tienda($flags = [])
    {
        /** Dueño del fixture. */
        $user = $this->usuario();

        /** Columnas que todavía pueden no existir en la base. */
        $columnas_nuevas = ['ajustar_carritos_por_stock', 'avisar_ajuste_carrito_por_mail', 'avisar_puede_agotarse'];

        $valores = array_merge([
            'ignorar_stock'              => 0,
            'permitir_pedidos_sin_stock' => 0,
            'stock_null_equal_0'         => 0,
        ], $flags);

        foreach ($columnas_nuevas as $columna) {
            if (isset($valores[$columna]) && !Schema::hasColumn('online_configurations', $columna)) {
                unset($valores[$columna]);
            }
        }

        OnlineConfiguration::where('user_id', $user->id)->delete();

        return OnlineConfiguration::create(array_merge(['user_id' => $user->id], $valores));
    }

    /**
     * Crea un comprador de la tienda del dueño con un mail válido y único (o el que se pase).
     *
     * @param string|null $email Mail a usar; null = uno válido generado.
     * @return \App\Models\Buyer
     */
    protected function crear_comprador($email = null)
    {
        return Buyer::create([
            'name'    => 'Comprador ' . uniqid(),
            'phone'   => '2216000000',
            'email'   => is_null($email) ? 'comprador-' . uniqid() . '@ejemplo.test' : $email,
            'user_id' => $this->usuario()->id,
        ]);
    }

    /**
     * Arma un carrito con UN renglón del artículo.
     *
     * Opciones: faltante (amount_insuficiente), variant_id, casilla (completar_al_reponer 0|1; null = no se
     * escribe y vale el default), buyer (Buyer, o false para un carrito de invitado), cart_id (reusar carrito),
     * order_id (carrito ya enviado como pedido), cart_dias_sin_tocar (antigüedad de carts.updated_at),
     * renglon_updated_at (Carbon de article_cart.updated_at).
     *
     * @param \App\Models\Article $articulo
     * @param float $cantidad
     * @param array $opciones
     * @return array ['cart_id' => int, 'line_id' => int, 'buyer' => \App\Models\Buyer|null]
     */
    protected function armar_renglon($articulo, $cantidad, $opciones = [])
    {
        $buyer = array_key_exists('buyer', $opciones) ? $opciones['buyer'] : $this->crear_comprador();

        if (isset($opciones['cart_id'])) {
            $cart_id = $opciones['cart_id'];
        } else {
            $cart = Cart::create([
                'buyer_id' => $buyer ? $buyer->id : null,
                'user_id'  => $this->usuario()->id,
                'order_id' => isset($opciones['order_id']) ? $opciones['order_id'] : null,
            ]);
            $cart_id = $cart->id;

            if (isset($opciones['cart_dias_sin_tocar'])) {
                DB::table('carts')->where('id', $cart_id)->update(['updated_at' => now()->subDays($opciones['cart_dias_sin_tocar'])]);
            }
        }

        $fila = [
            'cart_id'             => $cart_id,
            'article_id'          => $articulo->id,
            'variant_id'          => isset($opciones['variant_id']) ? $opciones['variant_id'] : null,
            'amount'              => $cantidad,
            'amount_insuficiente' => isset($opciones['faltante']) ? $opciones['faltante'] : null,
            'price'               => 100,
            'cost'                => 0,
            'created_at'          => now(),
            'updated_at'          => isset($opciones['renglon_updated_at']) ? $opciones['renglon_updated_at'] : now(),
        ];

        if (isset($opciones['casilla']) && Schema::hasColumn('article_cart', 'completar_al_reponer')) {
            $fila['completar_al_reponer'] = $opciones['casilla'];
        }

        return [
            'cart_id' => $cart_id,
            'line_id' => DB::table('article_cart')->insertGetId($fila),
            'buyer'   => $buyer ? $buyer : null,
        ];
    }

    /**
     * Mueve el stock del artículo por el endpoint real.
     *
     * @param \App\Models\Article $articulo
     * @param float $cantidad Positiva ingresa, negativa egresa.
     * @param string $concepto Nombre del concepto de stock.
     * @param array $extra Claves extra del request (article_variant_id, from_address_id...).
     * @return void
     */
    protected function mover_stock($articulo, $cantidad, $concepto = 'Venta', $extra = [])
    {
        $this->postJson('api/stock-movement', array_merge([
            'model_id'                     => $articulo->id,
            'amount'                       => $cantidad,
            'concepto_stock_movement_name' => $concepto,
        ], $extra))->assertStatus(201);
    }

    /**
     * Renglón del carrito leído de la base.
     *
     * @param int $line_id
     * @return object
     */
    protected function linea($line_id)
    {
        return DB::table('article_cart')->where('id', $line_id)->first();
    }

    /**
     * Trabajos de mail despachados, opcionalmente de un solo tipo.
     *
     * @param string|null $tipo 'recortado' | 'restaurado' | null (todos).
     * @return \Illuminate\Support\Collection
     */
    protected function trabajos($tipo = null)
    {
        return Bus::dispatched(self::JOB_DE_MAIL)->filter(function ($trabajo) use ($tipo) {
            return is_null($tipo) || $trabajo->type === $tipo;
        })->values();
    }

    /**
     * Cantidad de Messages `cart_amount_updated` de toda la base (el ajuste nuevo no debe crear ninguno).
     *
     * @return int
     */
    protected function mensajes_de_carrito()
    {
        return Message::where('type', 'cart_amount_updated')->count();
    }

    /**
     * Aserción común: el renglón quedó con `$cantidad` y faltante `$faltante` (null cuenta como 0).
     *
     * @param int $line_id
     * @param float $cantidad
     * @param float $faltante
     * @param string $detalle Nombre del renglón para el mensaje.
     * @return void
     */
    protected function assert_renglon($line_id, $cantidad, $faltante, $detalle)
    {
        $renglon = $this->linea($line_id);

        $this->assertEquals($cantidad, (float) $renglon->amount, $detalle . ': cantidad del carrito.');
        $this->assertEquals($faltante, (float) $renglon->amount_insuficiente, $detalle . ': unidades que faltan.');
    }

    /**
     * CA2 — el ejemplo de Lucas: stock 10, dos compradores con 5; se venden 8 y los dos quedan en 2 con aviso;
     * el negocio repone 10 y los dos vuelven a 5.
     *
     * Falla hoy porque se crean 2 Messages `cart_amount_updated` y no se despacha ningún job de mail
     * (aserciones de Messages y de jobs).
     *
     * @test
     */
    public function test_ejemplo_de_lucas_recorta_y_restaura_cada_carrito()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos ejemplo Lucas', ['stock' => 10]);

        $a = $this->armar_renglon($articulo, 5);
        $b = $this->armar_renglon($articulo, 5);

        $mensajes_antes = $this->mensajes_de_carrito();

        $this->mover_stock($articulo, -8, 'Venta');

        $this->assertEquals(2.0, $this->stock($articulo), 'La venta debía dejar el stock en 2.');

        $this->assert_renglon($a['line_id'], 2.0, 3.0, 'Carrito A recortado');
        $this->assert_renglon($b['line_id'], 2.0, 3.0, 'Carrito B recortado');

        $this->assertEquals($mensajes_antes, $this->mensajes_de_carrito(), 'El ajuste no crea Messages `cart_amount_updated`.');
        $this->assertCount(2, $this->trabajos('recortado'), 'Un mail "recortado" por comprador.');

        foreach ($this->trabajos('recortado') as $trabajo) {
            $this->assertEquals($articulo->id, (int) $trabajo->article_id);
            $this->assertEquals(5.0, (float) $trabajo->requested_amount, 'X = lo que había agregado.');
            $this->assertEquals(2.0, (float) $trabajo->new_amount, 'Y = lo que quedó en el carrito.');
            $this->assertEquals($this->usuario()->id, (int) $trabajo->owner_id);
        }

        $emails = $this->trabajos('recortado')->pluck('buyer_email')->sort()->values()->all();
        $esperados = [$a['buyer']->email, $b['buyer']->email];
        sort($esperados);
        $this->assertEquals($esperados, $emails, 'Cada mail va a la casilla de su comprador.');

        $this->assertTrue(Schema::hasColumn('article_cart', 'completar_al_reponer'), 'Falta la columna de la casilla.');
        $this->assertEquals(1, (int) $this->linea($a['line_id'])->completar_al_reponer, 'El faltante nuevo arranca con la casilla marcada.');

        // El negocio repone 10: los dos vuelven a 5 (cada uno contra el stock completo, sin repartir).
        $this->mover_stock($articulo, 10, 'Ingreso manual');

        $this->assertEquals(12.0, $this->stock($articulo));
        $this->assert_renglon($a['line_id'], 5.0, 0.0, 'Carrito A restaurado');
        $this->assert_renglon($b['line_id'], 5.0, 0.0, 'Carrito B restaurado');

        $this->assertCount(2, $this->trabajos('restaurado'), 'Un mail "restaurado" por comprador.');

        foreach ($this->trabajos('restaurado') as $trabajo) {
            $this->assertEquals(5.0, (float) $trabajo->requested_amount);
            $this->assertEquals(5.0, (float) $trabajo->new_amount);
        }

        $this->assertEquals($mensajes_antes, $this->mensajes_de_carrito(), 'Tampoco al restaurar se crea un Message.');
    }

    /**
     * CA2 (variante del ejemplo): con dos carritos en 2 de 3 y stock 2, un ingreso de 4 (stock 6) deja a los DOS
     * en 5: cada carrito se evalúa contra el stock completo, no se reparte.
     *
     * Falla hoy porque la restauración por Message sí vuelve a 5, pero crea 2 Messages y ningún job (aserciones de
     * Messages y de jobs).
     *
     * @test
     */
    public function test_restauracion_no_reparte_el_stock_entre_carritos()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos sin repartir', ['stock' => 2]);

        $a = $this->armar_renglon($articulo, 2, ['faltante' => 3]);
        $b = $this->armar_renglon($articulo, 2, ['faltante' => 3]);

        $mensajes_antes = $this->mensajes_de_carrito();

        $this->mover_stock($articulo, 4, 'Ingreso manual');

        $this->assertEquals(6.0, $this->stock($articulo));
        $this->assert_renglon($a['line_id'], 5.0, 0.0, 'Carrito A');
        $this->assert_renglon($b['line_id'], 5.0, 0.0, 'Carrito B');
        $this->assertEquals($mensajes_antes, $this->mensajes_de_carrito());
        $this->assertCount(2, $this->trabajos('restaurado'));
    }

    /**
     * CA3 — restauración parcial: carrito en 2 de 3 con stock 2; entran 2 (stock 4) y queda en 4 con 1 faltante,
     * con un mail "restaurado" (X=5, Y=4).
     *
     * Falla hoy porque crea un Message y no despacha job (aserciones de Messages y de jobs).
     *
     * @test
     */
    public function test_restauracion_parcial()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos parcial', ['stock' => 2]);

        $a = $this->armar_renglon($articulo, 2, ['faltante' => 3]);

        $mensajes_antes = $this->mensajes_de_carrito();

        $this->mover_stock($articulo, 2, 'Ingreso manual');

        $this->assertEquals(4.0, $this->stock($articulo));
        $this->assert_renglon($a['line_id'], 4.0, 1.0, 'Restauración parcial');
        $this->assertEquals($mensajes_antes, $this->mensajes_de_carrito());

        $this->assertCount(1, $this->trabajos('restaurado'));
        $this->assertEquals(5.0, (float) $this->trabajos('restaurado')->first()->requested_amount);
        $this->assertEquals(4.0, (float) $this->trabajos('restaurado')->first()->new_amount);
    }

    /**
     * CA3 — un movimiento que no cambia lo que se puede comprar no escribe el renglón ni avisa. Un movimiento
     * de cantidad 0 deja el stock en 2: el renglón 2 de 3 sigue igual (su `updated_at` no se toca) y no hay
     * Message ni job. Segunda parte (control): un renglón sin faltante con stock de sobra tampoco se toca.
     *
     * Falla hoy porque el código viejo, con stock 2 >= amount 2 y < requerido 5, manda un Message en CADA
     * movimiento aunque la cantidad no cambie (aserción de Messages).
     *
     * @test
     */
    public function test_sin_cambio_no_escribe_ni_avisa()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos sin cambio', ['stock' => 2]);

        /** Marca vieja del renglón: si el ajuste lo reescribe, cambia. */
        $marca_vieja = now()->subDays(2)->startOfSecond();

        $a = $this->armar_renglon($articulo, 2, ['faltante' => 3, 'casilla' => 1, 'renglon_updated_at' => $marca_vieja]);

        $mensajes_antes = $this->mensajes_de_carrito();

        $this->mover_stock($articulo, 0, 'Ingreso manual');

        $this->assertEquals(2.0, $this->stock($articulo));
        $this->assertEquals(0, $this->mensajes_de_carrito() - $mensajes_antes, 'Sin cambio de estado no se avisa.');
        $this->assertCount(0, $this->trabajos(), 'Sin cambio de estado no se despacha mail.');
        $this->assert_renglon($a['line_id'], 2.0, 3.0, 'Renglón sin cambio');
        $this->assertEquals($marca_vieja->format('Y-m-d H:i:s'), (string) $this->linea($a['line_id'])->updated_at, 'No se reescribe un renglón sin cambio.');

        // Control: renglón sin faltante, stock 10, baja a 9 y sigue alcanzando para 2.
        $otro = $this->crear_articulo('zz Ajuste carritos sin cambio alcanza', ['stock' => 10]);
        $b = $this->armar_renglon($otro, 2, ['renglon_updated_at' => $marca_vieja]);

        $this->mover_stock($otro, -1, 'Venta');

        $this->assert_renglon($b['line_id'], 2.0, 0.0, 'Renglón que sigue alcanzando');
        $this->assertEquals($marca_vieja->format('Y-m-d H:i:s'), (string) $this->linea($b['line_id'])->updated_at);
        $this->assertCount(0, $this->trabajos());
    }

    /**
     * CA4 — con la casilla "Completar mi carrito cuando vuelva a haber stock" desmarcada, la restauración se
     * saltea (el faltante se conserva para que el cartel y la casilla sigan visibles) y no hay mail.
     *
     * Falla hoy porque el código viejo restaura igual a 5 de 5 (aserción del renglón).
     *
     * @test
     */
    public function test_casilla_desmarcada_no_restaura()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos casilla desmarcada', ['stock' => 2]);

        $a = $this->armar_renglon($articulo, 2, ['faltante' => 3, 'casilla' => 0]);

        $this->mover_stock($articulo, 10, 'Ingreso manual');

        $this->assertEquals(12.0, $this->stock($articulo));
        $this->assert_renglon($a['line_id'], 2.0, 3.0, 'Casilla desmarcada');
        $this->assertCount(0, $this->trabajos(), 'Sin restauración no hay mail.');

        $this->assertTrue(Schema::hasColumn('article_cart', 'completar_al_reponer'), 'Falta la columna de la casilla.');
        $this->assertEquals(0, (int) $this->linea($a['line_id'])->completar_al_reponer, 'La casilla del comprador se respeta.');
    }

    /**
     * CA4 — un faltante nuevo sobre un renglón que no tenía faltante deja la casilla marcada, aunque en la base
     * quedara en 0 de un faltante anterior (cada faltante nuevo arranca con la casilla marcada).
     *
     * Falla hoy porque no se despacha el job "recortado" (aserción de jobs).
     *
     * @test
     */
    public function test_faltante_nuevo_marca_la_casilla()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos faltante nuevo', ['stock' => 10]);

        // Renglón sano (sin faltante) que arrastra una casilla en 0 de una vez anterior.
        $a = $this->armar_renglon($articulo, 5, ['faltante' => 0, 'casilla' => 0]);

        $this->mover_stock($articulo, -8, 'Venta');

        $this->assert_renglon($a['line_id'], 2.0, 3.0, 'Faltante nuevo');
        $this->assertCount(1, $this->trabajos('recortado'));

        $this->assertTrue(Schema::hasColumn('article_cart', 'completar_al_reponer'), 'Falta la columna de la casilla.');
        $this->assertEquals(1, (int) $this->linea($a['line_id'])->completar_al_reponer, 'Un faltante nuevo vuelve a marcar la casilla.');
    }

    /**
     * CA5 — variantes: cada renglón se compara con el stock de SU variante, no con el total del artículo.
     * V1 (stock 4) y V2 (stock 10); carrito con V1 x3 y V2 x3; salen 2 de V1 -> V1 queda 2 de 3 (1 faltante) y V2
     * no se toca.
     *
     * Falla hoy porque el código viejo compara contra el stock total del artículo (12) y no recorta V1
     * (aserción del renglón V1).
     *
     * @test
     */
    public function test_variantes_se_ajustan_por_su_stock()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos variantes', ['stock' => 14]);

        $v1 = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Rojo', 'stock' => 4, 'oculta' => false]);
        $v2 = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Azul', 'stock' => 10, 'oculta' => false]);

        $a = $this->armar_renglon($articulo, 3, ['variant_id' => $v1->id]);
        $b = $this->armar_renglon($articulo, 3, ['variant_id' => $v2->id, 'buyer' => $a['buyer'], 'cart_id' => $a['cart_id']]);

        $this->mover_stock($articulo, -2, 'Venta', ['article_variant_id' => $v1->id]);

        $this->assertEquals(2, (int) DB::table('article_variants')->where('id', $v1->id)->value('stock'), 'La venta debía dejar la variante V1 en 2.');

        $this->assert_renglon($a['line_id'], 2.0, 1.0, 'Renglón V1');

        $intacto = $this->linea($b['line_id']);
        $this->assertEquals(3.0, (float) $intacto->amount, 'El renglón de V2 no se toca.');
        $this->assertNull($intacto->amount_insuficiente, 'El renglón de V2 no se toca.');

        $this->assertCount(1, $this->trabajos('recortado'), 'Sólo avisa el renglón de V1.');
        $trabajo = $this->trabajos('recortado')->first();
        $this->assertEquals($v1->id, (int) $trabajo->variant_id);
        $this->assertEquals(3.0, (float) $trabajo->requested_amount);
        $this->assertEquals(2.0, (float) $trabajo->new_amount);
    }

    /**
     * CA6 — con "Ajustar los carritos" apagado no se toca ningún carrito, no se avisa y no se crea Message.
     *
     * Falla hoy porque el código viejo recorta igual los dos carritos y crea 2 Messages (aserción de los
     * renglones). Al final, una aserción de esquema evita que el test pase en vacío si la columna no existe.
     *
     * @test
     */
    public function test_opcion_apagada_no_toca_carritos()
    {
        $this->configurar_tienda(['ajustar_carritos_por_stock' => 0]);

        $articulo = $this->crear_articulo('zz Ajuste carritos opcion apagada', ['stock' => 10]);

        $a = $this->armar_renglon($articulo, 5);
        $b = $this->armar_renglon($articulo, 5);

        $mensajes_antes = $this->mensajes_de_carrito();

        $this->mover_stock($articulo, -8, 'Venta');

        $this->assert_renglon($a['line_id'], 5.0, 0.0, 'Carrito A');
        $this->assert_renglon($b['line_id'], 5.0, 0.0, 'Carrito B');
        $this->assertCount(0, $this->trabajos());
        $this->assertEquals($mensajes_antes, $this->mensajes_de_carrito());

        $this->assertTrue(Schema::hasColumn('online_configurations', 'ajustar_carritos_por_stock'), 'Falta la columna de la opción.');
        $this->assertEquals(0, (int) OnlineConfiguration::where('user_id', $this->usuario()->id)->value('ajustar_carritos_por_stock'));
    }

    /**
     * CA8 — carritos que no se tocan: uno ya enviado como pedido (`order_id`) y uno sin actividad hace 16 días
     * quedan intactos; el carrito activo del mismo artículo sí se ajusta (control positivo).
     *
     * Falla hoy porque el código viejo recorta TODOS los carritos del artículo, incluso los enviados y los viejos
     * (aserciones de los renglones enviado y viejo).
     *
     * @test
     */
    public function test_carritos_enviados_y_viejos_no_se_tocan()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos enviados y viejos', ['stock' => 10]);

        $activo = $this->armar_renglon($articulo, 5);
        $enviado = $this->armar_renglon($articulo, 5, ['order_id' => 987654321]);
        $viejo = $this->armar_renglon($articulo, 5, ['cart_dias_sin_tocar' => 16]);

        $this->mover_stock($articulo, -8, 'Venta');

        $this->assert_renglon($enviado['line_id'], 5.0, 0.0, 'Carrito ya enviado como pedido');
        $this->assert_renglon($viejo['line_id'], 5.0, 0.0, 'Carrito sin actividad hace 16 días');
        $this->assert_renglon($activo['line_id'], 2.0, 3.0, 'Carrito activo (control)');
        $this->assertCount(1, $this->trabajos('recortado'), 'Sólo avisa el carrito activo.');
    }

    /**
     * CA8 — un renglón huérfano (su carrito se borró; no hay clave foránea) no puede romper el movimiento de
     * stock: la venta responde 201 y el resto de los carritos se ajusta igual.
     *
     * Falla hoy porque el código viejo hace `Cart::find(...)->buyer` sobre null y revienta dentro del guardado
     * (el POST no devuelve 201).
     *
     * @test
     */
    public function test_renglon_huerfano_no_rompe_el_movimiento()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos huerfano', ['stock' => 10]);

        DB::table('article_cart')->insert([
            'cart_id'    => 987654322,
            'article_id' => $articulo->id,
            'amount'     => 5,
            'price'      => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activo = $this->armar_renglon($articulo, 5);

        $this->mover_stock($articulo, -8, 'Venta');

        $this->assert_renglon($activo['line_id'], 2.0, 3.0, 'Carrito activo junto al huérfano');
    }

    /**
     * CA9 — consultas activas (permitir_pedidos_sin_stock) y stock exactamente 0: el renglón se va a pedir como
     * "Consultar", no se toca. (Guarda de regresión: ya lo cumple la misión de Únicas; acá se fija por el
     * endpoint de movimientos y sin job.)
     *
     * @test
     */
    public function test_consultas_con_stock_cero_no_recorta()
    {
        $this->configurar_tienda(['permitir_pedidos_sin_stock' => 1]);

        $articulo = $this->crear_articulo('zz Ajuste carritos consultas stock cero', ['stock' => 1]);

        $a = $this->armar_renglon($articulo, 5);

        $this->mover_stock($articulo, -1, 'Venta');

        $this->assertEquals(0.0, $this->stock($articulo));
        $this->assert_renglon($a['line_id'], 5.0, 0.0, 'Consultar con stock 0');
        $this->assertCount(0, $this->trabajos());
    }

    /**
     * CA9 — consultas activas pero con stock positivo: hay tope y el carrito se recorta (stock 5, carrito 5, se
     * venden 2 -> 3 de 5, 2 faltan, con mail).
     *
     * Falla hoy porque se crea un Message y no se despacha job (aserciones de Messages y de jobs).
     *
     * @test
     */
    public function test_consultas_con_stock_positivo_recorta()
    {
        $this->configurar_tienda(['permitir_pedidos_sin_stock' => 1]);

        $articulo = $this->crear_articulo('zz Ajuste carritos consultas con stock', ['stock' => 5]);

        $a = $this->armar_renglon($articulo, 5);

        $mensajes_antes = $this->mensajes_de_carrito();

        $this->mover_stock($articulo, -2, 'Venta');

        $this->assert_renglon($a['line_id'], 3.0, 2.0, 'Consultas con stock positivo');
        $this->assertEquals($mensajes_antes, $this->mensajes_de_carrito());
        $this->assertCount(1, $this->trabajos('recortado'));
    }

    /**
     * CA9 — reserva web activa: el tope es el disponible del depósito principal (físico - reservado por otros
     * pedidos). Depósito principal 10, 6 reservados por otro pedido, carrito de 5; sale 1 por una venta de ese
     * depósito -> físico 9, disponible 3 -> el carrito queda en 3 de 5 (2 faltan).
     *
     * Falla hoy porque el código viejo compara contra el stock físico (9 >= 5) y no recorta (aserción del
     * renglón).
     *
     * @test
     */
    public function test_reserva_web_recorta_al_disponible()
    {
        $this->configurar_tienda([
            'reservar_stock_pedidos_web'     => 1,
            'reserva_stock_erp_protocolo'    => 1,
            'reserva_stock_tienda_protocolo' => 1,
        ]);

        $articulo = $this->crear_articulo('zz Ajuste carritos reserva web', ['stock' => 10]);

        /** Depósito principal del comercio. */
        $principal = Address::principal_de($this->usuario()->id);
        $this->assertNotNull($principal, 'El fixture no tiene depósito principal.');

        DB::table('address_article')->insert(['article_id' => $articulo->id, 'address_id' => $principal->id, 'amount' => 10]);

        // 6 unidades comprometidas por OTRO pedido (sin movimiento de stock).
        DB::table('order_stock_reservations')->insert([
            'user_id'    => $this->usuario()->id,
            'order_id'   => 987654323,
            'article_id' => $articulo->id,
            'address_id' => $principal->id,
            'amount'     => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $a = $this->armar_renglon($articulo, 5);

        $this->mover_stock($articulo, -1, 'Venta', ['from_address_id' => $principal->id]);

        $this->assertEquals(9.0, $this->stock($articulo), 'El físico del artículo es 9.');
        $this->assert_renglon($a['line_id'], 3.0, 2.0, 'Disponible = 9 físico - 6 reservados');
        $this->assertCount(1, $this->trabajos('recortado'));
    }

    /**
     * CA13 — cantidades enteras: con stock fraccionario 2.5 un carrito de 5 (entero) queda en 2, con 3 que faltan.
     *
     * Falla hoy porque el código viejo deja el carrito en 2.5 con 2.5 que faltan (aserción del renglón).
     *
     * @test
     */
    public function test_stock_fraccionario_recorta_a_entero()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos fraccionario', ['stock' => 3.5]);

        $a = $this->armar_renglon($articulo, 5);

        $this->mover_stock($articulo, -1, 'Venta');

        $this->assertEquals(2.5, $this->stock($articulo));
        $this->assert_renglon($a['line_id'], 2.0, 3.0, 'Stock 2.5 con carrito entero');
        $this->assertCount(1, $this->trabajos('recortado'));
    }

    /**
     * Stock negativo sin "ignorar stock": hay tope 0. El renglón queda en 0 unidades con el faltante completo
     * ("esperando stock") y el comprador recibe el mail "recortado" con Y = 0.
     *
     * Falla hoy porque no se despacha el job (aserción de jobs); el renglón en 0 ya lo deja el código viejo.
     *
     * @test
     */
    public function test_stock_negativo_deja_el_renglon_esperando_stock()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos stock negativo', ['stock' => 1]);

        $a = $this->armar_renglon($articulo, 3);

        $this->mover_stock($articulo, -5, 'Venta');

        $this->assertEquals(-4.0, $this->stock($articulo));
        $this->assert_renglon($a['line_id'], 0.0, 3.0, 'Renglón esperando stock');
        $this->assertCount(1, $this->trabajos('recortado'));
        $this->assertEquals(0.0, (float) $this->trabajos('recortado')->first()->new_amount);
    }

    /**
     * CA10 — con "Avisar al comprador por mail" apagado el ajuste corre (el renglón se recorta) pero no se
     * despacha ningún job de mail.
     *
     * Falla hoy porque el código viejo crea un Message `cart_amount_updated` (aserción de Messages). Al final,
     * una aserción de esquema evita el vacío si la columna no existe.
     *
     * @test
     */
    public function test_con_el_aviso_por_mail_apagado_se_ajusta_sin_despachar_mail()
    {
        $this->configurar_tienda(['avisar_ajuste_carrito_por_mail' => 0]);

        $articulo = $this->crear_articulo('zz Ajuste carritos sin mail', ['stock' => 10]);

        $a = $this->armar_renglon($articulo, 5);

        $mensajes_antes = $this->mensajes_de_carrito();

        $this->mover_stock($articulo, -8, 'Venta');

        $this->assert_renglon($a['line_id'], 2.0, 3.0, 'El ajuste corre aunque no haya mail');
        $this->assertEquals($mensajes_antes, $this->mensajes_de_carrito(), 'Nunca se crea un Message.');
        $this->assertCount(0, $this->trabajos(), 'Con el aviso apagado no se despacha mail.');

        $this->assertTrue(Schema::hasColumn('online_configurations', 'avisar_ajuste_carrito_por_mail'), 'Falta la columna del aviso.');
        $this->assertEquals(0, (int) OnlineConfiguration::where('user_id', $this->usuario()->id)->value('avisar_ajuste_carrito_por_mail'));
    }

    /**
     * CA10 — un comprador sin mail, o con un mail inválido, se ajusta igual pero no recibe job de mail. Un tercer
     * comprador con mail válido del mismo carrito de prueba sí lo recibe (control positivo).
     *
     * Falla hoy porque se crean Messages y no se despacha el job del comprador con mail válido (aserciones de
     * Messages y de jobs).
     *
     * @test
     */
    public function test_comprador_sin_email_o_con_email_invalido_se_ajusta_sin_mail()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos email invalido', ['stock' => 10]);

        $sin_email = $this->armar_renglon($articulo, 5, ['buyer' => $this->crear_comprador('')]);
        $invalido = $this->armar_renglon($articulo, 5, ['buyer' => $this->crear_comprador('esto-no-es-un-mail')]);
        $valido = $this->armar_renglon($articulo, 5);

        $mensajes_antes = $this->mensajes_de_carrito();

        $this->mover_stock($articulo, -8, 'Venta');

        $this->assert_renglon($sin_email['line_id'], 2.0, 3.0, 'Comprador sin mail');
        $this->assert_renglon($invalido['line_id'], 2.0, 3.0, 'Comprador con mail inválido');
        $this->assert_renglon($valido['line_id'], 2.0, 3.0, 'Comprador con mail válido');
        $this->assertEquals($mensajes_antes, $this->mensajes_de_carrito());

        $this->assertCount(1, $this->trabajos('recortado'), 'Sólo el comprador con mail válido recibe el aviso.');
        $this->assertEquals($valido['buyer']->email, $this->trabajos('recortado')->first()->buyer_email);
    }

    /**
     * CA10 — un carrito de invitado (sin comprador) también se ajusta y ve el cartel, pero no hay a quién mandarle
     * mail: ningún job.
     *
     * Falla hoy porque el código viejo ignora los carritos sin comprador (`is_null($buyer)`) y no los ajusta
     * (aserción del renglón).
     *
     * @test
     */
    public function test_invitado_se_ajusta_sin_mail()
    {
        $this->configurar_tienda();

        $articulo = $this->crear_articulo('zz Ajuste carritos invitado', ['stock' => 10]);

        $invitado = $this->armar_renglon($articulo, 5, ['buyer' => false]);

        $this->mover_stock($articulo, -8, 'Venta');

        $this->assert_renglon($invitado['line_id'], 2.0, 3.0, 'Carrito de invitado');
        $this->assertCount(0, $this->trabajos(), 'Un invitado no tiene mail.');
    }
}
