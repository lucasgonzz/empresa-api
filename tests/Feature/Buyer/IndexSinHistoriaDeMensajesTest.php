<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer;
use App\Models\Client;
use App\Models\Message;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión api-buyer-sin-historia-de-mensajes (9/9/2026): `GET api/buyer` deja de traer la
 * historia de mensajes de cada comprador.
 *
 * Hasta acá `BuyerController::index()` hacía `withAll()->get()`, y `Buyer::scopeWithAll` carga
 * `messages` con `article.images`. En Fenix (1.710 compradores, 87.928 mensajes) una sola
 * llamada hidrataba ~280 MB por worker y pasaba los 10 s adentro de `json_encode`: fue lo que
 * tumbó el VPS ese día. El listado pasa a devolver, por comprador, cuatro agregados y `messages`
 * SIEMPRE como array vacío; la conversación se carga aparte con `GET message/{buyer_id}`.
 *
 * 🔴 El contrato con la SPA son estos cinco nombres, exactos: `messages_count`,
 * `unread_messages_count`, `last_message_at`, `last_message` y `messages`. Una clave mal escrita
 * no explota: la lista de chats queda vacía en silencio.
 *
 * 🔴 La aserción que protege de verdad contra un "lo simplifico de vuelta a withAll()" es la de
 * `messages === []` con un comprador que SÍ tiene 30 mensajes. Los tests de cantidad de consultas
 * protegen la otra clase de error (una consulta por comprador o por mensaje); un eager load
 * completo es una cantidad fija de consultas y no los haría fallar.
 *
 * Todas las aserciones filtran por el dueño que crea este test: la base del slot arrastra
 * compradores de otras suites.
 *
 * PHP 7.4 (nada de `?->`, `match` ni `str_contains`).
 */
class IndexSinHistoriaDeMensajesTest extends EmpresaTestCase
{
    /** La ruta bajo prueba (el index del resource `buyer`). */
    const RUTA = 'api/buyer';

    /** Mensajes con los que arranca el comprador A. */
    const MENSAJES_DE_A = 30;

    /** @var \App\Models\User Dueño de la empresa, y el único cuyos compradores se miran. */
    protected $owner;

    /** @var \App\Models\Client Cliente del ERP atado al comprador A (para ver que la relación se sigue cargando). */
    protected $cliente;

    /** @var \App\Models\Buyer Comprador con historia de mensajes. */
    protected $comprador_a;

    /** @var \App\Models\Buyer Comprador sin ningún mensaje. */
    protected $comprador_b;

    /**
     * Cuántos mensajes de A entran como "no leídos" (`from_buyer = 1` y `read = 0`), contados a
     * medida que se siembran para no duplicar la regla del endpoint en el test.
     *
     * @var int
     */
    protected $no_leidos_de_a = 0;

    /** @var \App\Models\Message|null El último mensaje sembrado para A (el de id más alto). */
    protected $ultimo_mensaje_de_a = null;

    protected function setUp(): void
    {
        parent::setUp();

        $sufijo = uniqid();

        $this->owner = User::create([
            'name'         => 'Dueño compradores sin historia',
            'company_name' => 'Ferreteria compradores sin historia',
            'email'        => 'buyer-sin-historia-'.$sufijo.'@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->cliente = Client::create([
            'name'    => 'Cliente del comprador A',
            'user_id' => $this->owner->id,
        ]);

        $this->comprador_a = $this->comprador('A', $this->cliente->id);
        $this->comprador_b = $this->comprador('B');

        $this->sembrar_mensajes($this->comprador_a, self::MENSAJES_DE_A);

        $this->actuar_como($this->owner);
    }

    /**
     * Crea un comprador del dueño del test.
     *
     * @param string   $letra     Para distinguirlos en los mensajes de error.
     * @param int|null $client_id Cliente del ERP al que se ata (`comercio_city_client_id`).
     * @return \App\Models\Buyer
     */
    protected function comprador($letra, $client_id = null)
    {
        return Buyer::create([
            'name'                    => 'Comprador '.$letra.' sin historia',
            'email'                   => 'comprador-'.strtolower($letra).'-'.uniqid().'@test.local',
            'user_id'                 => $this->owner->id,
            'comercio_city_client_id' => $client_id,
        ]);
    }

    /**
     * Siembra `$cantidad` mensajes para un comprador, numerados desde `$desde`, con mezcla de
     * `from_buyer` (los pares) y `read` (los múltiplos de 3). Mantiene `no_leidos_de_a` y
     * `ultimo_mensaje_de_a` al día.
     *
     * Los `created_at` van creciendo con el número, así el último por id es también el último
     * por fecha y ninguna aserción depende de cuál de los dos criterios use el endpoint.
     *
     * @param \App\Models\Buyer $comprador
     * @param int               $cantidad
     * @param int               $desde
     * @return void
     */
    protected function sembrar_mensajes($comprador, $cantidad, $desde = 1)
    {
        for ($numero = $desde; $numero < $desde + $cantidad; $numero++) {

            $from_buyer = ($numero % 2 === 0) ? 1 : 0;
            $read       = ($numero % 3 === 0) ? 1 : 0;

            // Sin microsegundos: la columna los descarta y la comparación de fechas sería falsa.
            $creado_at = Carbon::now()->subMinutes(10000 - $numero)->startOfSecond();

            $mensaje = Message::create([
                'text'       => 'Mensaje '.$numero,
                'buyer_id'   => $comprador->id,
                'user_id'    => $from_buyer ? null : $this->owner->id,
                'from_buyer' => $from_buyer,
                'read'       => $read,
                'created_at' => $creado_at,
                'updated_at' => $creado_at,
            ]);

            if ($comprador->id === $this->comprador_a->id) {

                if ($from_buyer && !$read) {
                    $this->no_leidos_de_a++;
                }

                $this->ultimo_mensaje_de_a = $mensaje;
            }
        }
    }

    /**
     * Cambia el usuario autenticado. El `Auth::forgetGuards()` es el mismo que necesitan los
     * tests de Alertas: la ruta está bajo `auth:sanctum`, cuyo guard cachea el usuario que
     * resolvió la primera vez, y `EmpresaTestCase::setUp()` ya autenticó al usuario del fixture.
     *
     * @param \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Pega al endpoint y devuelve los compradores DEL DUEÑO DEL TEST indexados por id.
     *
     * De paso afirma que el listado trae exactamente los dos del dueño: el `where` por
     * `user_id` tiene que seguir filtrando después del join con los agregados.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function compradores_del_listado()
    {
        $respuesta = $this->getJson(self::RUTA);

        $respuesta->assertStatus(200);

        $json = $respuesta->json();

        $this->assertArrayHasKey('models', $json, 'La respuesta perdió la clave "models".');

        $mios = [];

        foreach ($json['models'] as $fila) {

            if ((int) $fila['user_id'] !== (int) $this->owner->id) {
                continue;
            }

            $mios[(int) $fila['id']] = $fila;
        }

        $this->assertCount(2, $mios, 'El listado tiene que traer exactamente los dos compradores del dueño.');
        $this->assertCount(count($mios), $json['models'], 'El listado trajo compradores de OTRO dueño.');

        return $mios;
    }

    /**
     * Pega al endpoint con el log de consultas prendido y devuelve cuántas consultas costó.
     *
     * De paso afirma que ninguna de esas consultas sea la del eager load de `messages`
     * (`select * from messages where buyer_id in (...)`): es la firma exacta de un `withAll()`
     * en el listado, y es lo que hidrataba los 87.928 mensajes de Fenix.
     *
     * @return int
     */
    protected function consultas_del_listado()
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson(self::RUTA)->assertStatus(200);

        $consultas = DB::getQueryLog();

        DB::disableQueryLog();

        foreach ($consultas as $consulta) {
            $this->assertSame(
                0,
                preg_match('/^select \* from `messages`/i', $consulta['query']),
                'El listado volvió a cargar la historia de mensajes: '.$consulta['query']
            );
        }

        return count($consultas);
    }

    /**
     * TEST 1 — el contrato, campo por campo.
     *
     * A (30 mensajes): los agregados con sus valores, `last_message` es el último por id y sin
     * `article`, `last_message_at` coincide con su `created_at`, y `messages` vacío AUNQUE tenga
     * historia. B (sin mensajes): 0 / 0 / null / null / []. Las relaciones chicas (`addresses`,
     * `comercio_city_client`) se siguen cargando.
     *
     * Los `assertSame` sobre los contadores son a propósito: la SPA hace
     * `buyer.unread_messages_count || 0`, y un `"0"` de string es verdadero en JS.
     *
     * @test
     * @return void
     */
    public function el_listado_trae_los_agregados_y_no_la_historia()
    {
        // Sanidad del fixture: que la mezcla sea mezcla de verdad.
        $this->assertGreaterThan(0, $this->no_leidos_de_a, 'El fixture no dejó ningún mensaje sin leer.');
        $this->assertLessThan(self::MENSAJES_DE_A, $this->no_leidos_de_a, 'El fixture dejó TODOS los mensajes sin leer.');

        $compradores = $this->compradores_del_listado();

        $a = $compradores[$this->comprador_a->id];
        $b = $compradores[$this->comprador_b->id];

        // --- A: con historia ---------------------------------------------------------------

        foreach (['messages_count', 'unread_messages_count', 'last_message_at', 'last_message', 'messages'] as $campo) {
            $this->assertArrayHasKey($campo, $a, 'Al comprador A le falta la clave "'.$campo.'".');
        }

        $this->assertSame(self::MENSAJES_DE_A, $a['messages_count']);
        $this->assertSame($this->no_leidos_de_a, $a['unread_messages_count']);

        $this->assertSame([], $a['messages'], 'El listado no tiene que traer la historia de mensajes, ni siquiera la de un comprador que la tiene.');

        $this->assertIsArray($a['last_message'], 'El comprador A tiene mensajes: last_message no puede ser null.');
        $this->assertSame($this->ultimo_mensaje_de_a->id, $a['last_message']['id'], 'last_message tiene que ser el último por id.');
        $this->assertSame('Mensaje '.self::MENSAJES_DE_A, $a['last_message']['text']);

        foreach (['id', 'text', 'from_buyer', 'read', 'created_at', 'article_id'] as $campo) {
            $this->assertArrayHasKey($campo, $a['last_message'], 'A last_message le falta la clave "'.$campo.'".');
        }

        $this->assertArrayNotHasKey('article', $a['last_message'], 'last_message va sin article.images.');

        $this->assertNotNull($a['last_message_at']);
        $this->assertSame(
            $a['last_message']['created_at'],
            $a['last_message_at'],
            'last_message_at tiene que ser el created_at del mismo mensaje que last_message, serializado igual.'
        );
        $this->assertSame(
            $this->ultimo_mensaje_de_a->fresh()->created_at->timestamp,
            Carbon::parse($a['last_message_at'])->timestamp,
            'last_message_at no coincide con el created_at guardado del último mensaje.'
        );

        // Las relaciones chicas siguen viniendo.
        $this->assertArrayHasKey('addresses', $a);
        $this->assertIsArray($a['addresses']);
        $this->assertArrayHasKey('comercio_city_client', $a);
        $this->assertSame($this->cliente->id, $a['comercio_city_client']['id']);

        // --- B: sin historia ---------------------------------------------------------------

        $this->assertSame(0, $b['messages_count']);
        $this->assertSame(0, $b['unread_messages_count']);
        $this->assertNull($b['last_message_at']);
        $this->assertNull($b['last_message']);
        $this->assertSame([], $b['messages']);
        $this->assertArrayHasKey('addresses', $b);
        $this->assertArrayHasKey('comercio_city_client', $b);
        $this->assertNull($b['comercio_city_client']);
    }

    /**
     * TEST 2 — la cantidad de consultas no depende de cuántos mensajes haya.
     *
     * Con A a 30 mensajes y con A a 300 el endpoint tiene que costar exactamente las mismas
     * consultas: los agregados se resuelven por comprador, nunca por mensaje. Y de paso, que
     * el contador siga el volumen.
     *
     * @test
     * @return void
     */
    public function la_cantidad_de_consultas_no_crece_con_los_mensajes()
    {
        $consultas_con_30 = $this->consultas_del_listado();

        $this->sembrar_mensajes($this->comprador_a, 300 - self::MENSAJES_DE_A, self::MENSAJES_DE_A + 1);

        $consultas_con_300 = $this->consultas_del_listado();

        $this->assertSame(
            $consultas_con_30,
            $consultas_con_300,
            'El listado costó '.$consultas_con_30.' consultas con 30 mensajes y '.$consultas_con_300.' con 300: escala con el volumen de mensajes.'
        );

        $a = $this->compradores_del_listado()[$this->comprador_a->id];

        $this->assertSame(300, $a['messages_count']);
        $this->assertSame($this->no_leidos_de_a, $a['unread_messages_count']);
        $this->assertSame($this->ultimo_mensaje_de_a->id, $a['last_message']['id']);
        $this->assertSame([], $a['messages']);
    }

    /**
     * TEST 3 — la cantidad de consultas tampoco depende de cuántos compradores haya.
     *
     * Es la clase de error más fácil de meter al "arreglar" esto: resolver `last_message` o los
     * contadores comprador por comprador (una consulta por cada uno). Con 2 compradores y con
     * 12 (cada uno de los nuevos con su propia historia) el costo tiene que ser el mismo.
     *
     * @test
     * @return void
     */
    public function la_cantidad_de_consultas_no_crece_con_los_compradores()
    {
        $consultas_con_2 = $this->consultas_del_listado();

        for ($i = 1; $i <= 10; $i++) {
            $this->sembrar_mensajes($this->comprador('extra-'.$i), 3);
        }

        $consultas_con_12 = $this->consultas_del_listado();

        $this->assertSame(
            $consultas_con_2,
            $consultas_con_12,
            'El listado costó '.$consultas_con_2.' consultas con 2 compradores y '.$consultas_con_12.' con 12: hay una consulta por comprador.'
        );
    }
}
