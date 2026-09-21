<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión chat-ia-y-modulo-ia — P2: el helper de consultas compartido.
 *
 * ConsultasSistemaIaHelper es la única fuente de las queries que usan tanto
 * el endpoint AdminSync (canal "sistema:" de WhatsApp) como las tools del
 * asistente de IA. Acá se protege lo que ninguno de los dos consumidores
 * puede permitirse perder: el filtro por dueño (los datos de un comercio no
 * se cruzan con los de otro), el tope de 20 registros y las exclusiones
 * (artículos inactivos, ventas borradas).
 */
class Consultas_helper_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio chat-ia P2',
            'email'    => 'chat-ia-p2-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio chat-ia P2',
            'email'    => 'chat-ia-p2-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function stock_filtra_por_nombre_solo_del_dueno_y_respeta_el_tope_de_veinte()
    {
        // 25 tornillos: más que el tope, para verificar el corte en 20.
        for ($i = 1; $i <= 25; $i++) {
            Article::create([
                'name'    => 'Tornillo P2 numero ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'user_id' => $this->comercio->id,
                'price'   => 100,
                'stock'   => $i,
            ]);
        }

        // Ruido que NO tiene que aparecer: otro nombre, otro dueño, inactivo.
        Article::create([
            'name'    => 'Martillo P2',
            'user_id' => $this->comercio->id,
        ]);
        Article::create([
            'name'    => 'Tornillo P2 ajeno',
            'user_id' => $this->otro_comercio->id,
        ]);
        Article::create([
            'name'    => 'Tornillo P2 inactivo',
            'user_id' => $this->comercio->id,
            'status'  => 'inactive',
        ]);

        $resultado = ConsultasSistemaIaHelper::stock_de_articulos($this->comercio->id, 'Tornillo P2');

        $this->assertCount(20, $resultado, 'El tope de 20 registros tiene que cortar los 25 tornillos.');

        $nombres = [];
        foreach ($resultado as $fila) {
            $nombres[] = $fila['nombre'];
        }

        $this->assertNotContains('Martillo P2', $nombres, 'La búsqueda por nombre no puede traer otros artículos.');
        $this->assertNotContains('Tornillo P2 ajeno', $nombres, 'Un artículo de otro dueño jamás puede aparecer.');
        $this->assertNotContains('Tornillo P2 inactivo', $nombres, 'Los artículos inactivos quedan afuera.');

        // El shape que consumen el endpoint y las tools: claves y tipos exactos. Misión
        // asistente-omnisciente (21/9/2026): se suma `tiene_imagen` al final, a propósito — es lo
        // que le permite al asistente ofrecer la foto o decir que no hay. Una clave más al final no
        // le cambia nada al canal "sistema:" de admin-api, que lee por nombre.
        $primera = $resultado[0];
        $this->assertEquals(['id', 'nombre', 'codigo', 'precio', 'stock', 'tiene_imagen'], array_keys($primera));
        $this->assertIsInt($primera['id']);
        $this->assertIsFloat($primera['precio']);
        $this->assertIsBool($primera['tiene_imagen']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function clientes_trae_saldo_e_id_y_filtra_por_dueno()
    {
        /*
         * Misión asistente-ia-acciones (§3.9 del plan): la deuda se siembra donde vive de
         * verdad, en la cuenta corriente en pesos del cliente (credit_accounts), y no en
         * clients.saldo, que es una columna muerta. Cambia SOLO cómo se siembra: las aserciones
         * de abajo son las mismas. La cuenta en dólares de Gomez no tiene que sumar. (El caso
         * "moneda_id null cuenta como pesos" no se puede sembrar: credit_accounts.moneda_id es
         * NOT NULL en el esquema.)
         */
        $gomez = Client::create([
            'name'    => 'Cliente P2 Gomez',
            'user_id' => $this->comercio->id,
            'phone'   => '2664001122',
            'email'   => 'gomez-p2@test.local',
        ]);
        $this->cuenta_corriente($gomez, 1, 1500.50);
        $this->cuenta_corriente($gomez, 2, 10);

        $gomez_ajeno = Client::create([
            'name'    => 'Cliente P2 Gomez ajeno',
            'user_id' => $this->otro_comercio->id,
        ]);
        $this->cuenta_corriente($gomez_ajeno, 1, 99);

        $resultado = ConsultasSistemaIaHelper::clientes($this->comercio->id, 'Gomez');

        $this->assertCount(1, $resultado, 'El cliente homónimo de otro dueño no puede aparecer.');

        $fila = $resultado[0];
        $this->assertEquals('Cliente P2 Gomez', $fila['cliente']);
        $this->assertEquals(1500.50, $fila['saldo']);
        $this->assertEquals('2664001122', $fila['telefono']);
        $this->assertEquals('gomez-p2@test.local', $fila['email']);

        // La clave id existe: la tool de movimientos del chat la usa para encadenar.
        $this->assertArrayHasKey('id', $fila);
        $this->assertIsInt($fila['id']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function movimientos_filtran_por_dueno_y_cliente_y_ordenan_del_mas_nuevo_al_mas_viejo()
    {
        $cliente = Client::create([
            'name'    => 'Cliente P2 cuenta corriente',
            'user_id' => $this->comercio->id,
        ]);

        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $cliente->id,
            'detalle'    => 'Venta P2 vieja',
            'debe'       => 1000,
            'saldo'      => 1000,
            'status'     => 'sin_pagar',
            'created_at' => now()->subDays(2),
        ]);
        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $cliente->id,
            'detalle'    => 'Pago P2 nuevo',
            'haber'      => 400,
            'saldo'      => 600,
            'status'     => 'pago_from_client',
            'created_at' => now()->subDay(),
        ]);

        /*
         * Movimiento con el MISMO client_id pero user_id de otro dueño: es
         * exactamente el cruce que el filtro doble (user_id + client_id)
         * tiene que dejar afuera.
         */
        CurrentAcount::create([
            'user_id'   => $this->otro_comercio->id,
            'client_id' => $cliente->id,
            'detalle'   => 'Movimiento P2 ajeno',
            'debe'      => 77,
            'saldo'     => 77,
            'status'    => 'sin_pagar',
        ]);

        $resultado = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente(
            $this->comercio->id,
            $cliente->id
        );

        $this->assertCount(2, $resultado, 'El movimiento de otro dueño no puede pasar el filtro.');

        // Orden: del más nuevo al más viejo.
        $this->assertEquals('Pago P2 nuevo', $resultado[0]['detalle']);
        $this->assertEquals('Venta P2 vieja', $resultado[1]['detalle']);

        $this->assertEquals(['fecha', 'detalle', 'debe', 'haber', 'saldo'], array_keys($resultado[0]));
        $this->assertEquals(400.0, $resultado[0]['haber']);
        $this->assertEquals(600.0, $resultado[0]['saldo']);
        $this->assertEquals(1000.0, $resultado[1]['debe']);
        $this->assertNotEquals('', $resultado[0]['fecha'], 'La fecha tiene que viajar formateada, no vacía.');
    }

    /**
     * @group chat-ia
     * @test
     */
    public function mas_vendidos_suma_cantidades_de_la_ventana_y_excluye_ventas_borradas()
    {
        $articulo = Article::create([
            'name'    => 'Fernet P2 top ventas',
            'user_id' => $this->comercio->id,
        ]);

        // Dos ventas vivas dentro de la ventana: 3 + 4 = 7 unidades.
        $venta_a = Sale::create(['user_id' => $this->comercio->id]);
        ArticlePurchase::create([
            'sale_id'    => $venta_a->id,
            'article_id' => $articulo->id,
            'amount'     => 3,
            'created_at' => now()->subDays(5),
        ]);

        $venta_b = Sale::create(['user_id' => $this->comercio->id]);
        ArticlePurchase::create([
            'sale_id'    => $venta_b->id,
            'article_id' => $articulo->id,
            'amount'     => 4,
            'created_at' => now()->subDays(10),
        ]);

        // Venta BORRADA (soft delete): sus 10 unidades no pueden sumar.
        $venta_borrada = Sale::create(['user_id' => $this->comercio->id]);
        ArticlePurchase::create([
            'sale_id'    => $venta_borrada->id,
            'article_id' => $articulo->id,
            'amount'     => 10,
            'created_at' => now()->subDays(3),
        ]);
        $venta_borrada->delete();

        // Compra fuera de la ventana de 30 días: tampoco suma.
        $venta_vieja = Sale::create(['user_id' => $this->comercio->id]);
        ArticlePurchase::create([
            'sale_id'    => $venta_vieja->id,
            'article_id' => $articulo->id,
            'amount'     => 50,
            'created_at' => now()->subDays(40),
        ]);

        $resultado = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 30);

        $this->assertCount(1, $resultado);
        $this->assertEquals('Fernet P2 top ventas', $resultado[0]['nombre']);
        $this->assertEquals(
            7.0,
            $resultado[0]['total_vendido'],
            'Tienen que sumar SOLO las ventas vivas de los últimos 30 días: 3 + 4.'
        );

        // Con la ventana de 7 días, la venta de hace 10 días también queda afuera.
        $resultado_semana = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 7);

        $this->assertEquals(3.0, $resultado_semana[0]['total_vendido']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function clientes_con_saldo_pendiente_trae_solo_deudores_ordenados_por_deuda()
    {
        /*
         * Misión asistente-ia-acciones (§3.9 del plan): la deuda va en la cuenta corriente en
         * pesos de cada cliente y no en la columna muerta clients.saldo. Cambia SOLO cómo se
         * siembra; las aserciones de abajo son las mismas. La cuenta en dólares del deudor grande
         * no suma a la deuda en pesos.
         */
        $chico = Client::create([
            'name'    => 'Deudor P2 chico',
            'user_id' => $this->comercio->id,
        ]);
        $this->cuenta_corriente($chico, 1, 200);

        $grande = Client::create([
            'name'    => 'Deudor P2 grande',
            'user_id' => $this->comercio->id,
        ]);
        $this->cuenta_corriente($grande, 1, 5000);
        $this->cuenta_corriente($grande, 2, 70);

        $al_dia = Client::create([
            'name'    => 'Cliente P2 al dia',
            'user_id' => $this->comercio->id,
        ]);
        $this->cuenta_corriente($al_dia, 1, 0);

        $resultado = ConsultasSistemaIaHelper::clientes_con_saldo_pendiente($this->comercio->id);

        $this->assertCount(2, $resultado, 'El cliente sin deuda no puede aparecer.');
        $this->assertEquals('Deudor P2 grande', $resultado[0]['cliente'], 'El orden es por deuda descendente.');
        $this->assertEquals(5000.0, $resultado[0]['saldo_pendiente']);
        $this->assertEquals(['cliente', 'telefono', 'saldo_pendiente'], array_keys($resultado[0]));
    }

    /**
     * Misión asistente-ia-acciones (§3.9 del plan): clients.saldo es una columna muerta. Un cliente
     * con la columna vieja sembrada DISTINTA de su cuenta corriente tiene que devolver el saldo de
     * la cuenta, en las dos consultas. Con la columna vieja el asistente decía "no te debe nada" (o
     * una deuda que ya no existe) justo antes de cargarle un pago.
     *
     * @group chat-ia
     * @test
     */
    public function el_saldo_sale_de_la_cuenta_corriente_y_no_de_la_columna_muerta_de_clients()
    {
        $con_deuda = Client::create([
            'name'    => 'Cliente P2 columna muerta',
            'user_id' => $this->comercio->id,
            'saldo'   => 99999,
        ]);
        $this->cuenta_corriente($con_deuda, 1, 300);

        $al_dia = Client::create([
            'name'    => 'Cliente P2 columna muerta al dia',
            'user_id' => $this->comercio->id,
            'saldo'   => 5000,
        ]);
        $this->cuenta_corriente($al_dia, 1, 0);

        $resultado = ConsultasSistemaIaHelper::clientes($this->comercio->id, 'columna muerta');

        $this->assertCount(2, $resultado);
        $this->assertEquals('Cliente P2 columna muerta', $resultado[0]['cliente']);
        $this->assertEquals(300.0, $resultado[0]['saldo'], 'El saldo tiene que salir de credit_accounts y no de clients.saldo.');
        $this->assertEquals(0.0, $resultado[1]['saldo'], 'Con la cuenta en 0 el cliente está al día, diga lo que diga clients.saldo.');

        $pendientes = ConsultasSistemaIaHelper::clientes_con_saldo_pendiente($this->comercio->id);

        $this->assertCount(1, $pendientes, 'El cliente con 5000 en la columna muerta y 0 en la cuenta no debe nada.');
        $this->assertEquals('Cliente P2 columna muerta', $pendientes[0]['cliente']);
        $this->assertEquals(300.0, $pendientes[0]['saldo_pendiente']);
    }

    /**
     * Cuenta corriente de un cliente con su saldo: es donde el sistema guarda la deuda viva (§3.9).
     *
     * @param Client $cliente
     * @param int $moneda_id 1 pesos, 2 dólares
     * @param float $saldo
     * @return CreditAccount
     */
    protected function cuenta_corriente($cliente, $moneda_id, $saldo)
    {
        return CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $cliente->id,
            'moneda_id'  => $moneda_id,
            'saldo'      => $saldo,
            'user_id'    => $cliente->user_id,
        ]);
    }
}
