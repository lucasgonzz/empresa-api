<?php

namespace Tests\Feature\ChatIa;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Migración de DATOS que renombra los títulos viejos de las conversaciones nacidas de una
 * sugerencia: "Sugerencia de stock #47" -> "Sugerencia de stock 19/08/2026"
 * (pedido de Lucas, 19/8/2026).
 *
 * 🔴 Este archivo existe porque es la pieza de MAYOR ALCANCE del cambio y era la única sin
 * red: corre en la base de cada negocio en producción y reescribe filas que ya existen.
 * Lo señaló el revisor de merge. Y la corrida real en la base del slot no la ejercitó,
 * porque `ai_conversations` estaba vacía — o sea que "la migración corrió sin errores" no
 * probaba absolutamente nada sobre lo que hace.
 *
 * Lo que se blinda no es el camino feliz (ése es el fácil): son los tres casos que NO
 * tiene que tocar. Una migración de datos que pisa de más no se puede deshacer con un
 * `down()` si ya se perdió el texto original.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class Renombrado_de_titulos_viejos_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var int */
    protected $stock_suggestion_id;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio renombrado',
            'company_name' => 'Ferreteria renombrado',
            'email'        => 'renombrado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        /*
         * La sugerencia se crea con fecha de AYER a propósito. Si se usara la de hoy, el
         * test pasaría igual con una migración que clavara now() — que es justamente el
         * bug que no queremos.
         */
        $this->stock_suggestion_id = DB::table('stock_suggestions')->insertGetId([
            'user_id'       => $this->comercio->id,
            'status'        => 'terminado',
            'modo'          => 'sucursales',
            'origen'        => 'manual',
            'limite_origen' => 'ninguno',
            'created_at'    => now()->subDay()->setTime(9, 15),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Instancia la migración. Se hace con require porque los archivos de migración no
     * están en el autoload de composer (no tienen namespace y viven fuera de app/).
     *
     * @return \Illuminate\Database\Migrations\Migration
     */
    private function migracion()
    {
        require_once database_path('migrations/2026_08_19_130000_renombrar_titulos_de_conversaciones_de_sugerencia.php');

        return new \RenombrarTitulosDeConversacionesDeSugerencia();
    }

    /**
     * @param string $origen
     * @param string $titulo
     * @param int|null $referencia_id
     * @return int
     */
    private function crear_conversacion($origen, $titulo, $referencia_id)
    {
        return DB::table('ai_conversations')->insertGetId([
            'user_id'       => $this->comercio->id,
            'auth_user_id'  => $this->comercio->id,
            'origen'        => $origen,
            'titulo'        => $titulo,
            'referencia_id' => $referencia_id,
            'created_at'    => now()->subDay()->setTime(9, 16),
            'updated_at'    => now(),
        ]);
    }

    /**
     * @param int $id
     * @return string|null
     */
    private function titulo_de($id)
    {
        return DB::table('ai_conversations')->where('id', $id)->value('titulo');
    }

    /**
     * El caso central: la fecha sale del `created_at` de la SUGERENCIA, no de la de hoy.
     *
     * @group chat-ia
     * @test
     */
    public function el_titulo_viejo_pasa_a_llevar_la_fecha_de_la_sugerencia()
    {
        $id = $this->crear_conversacion(
            'sugerencia_stock',
            'Sugerencia de stock #' . $this->stock_suggestion_id,
            $this->stock_suggestion_id
        );

        $this->migracion()->up();

        $esperado = 'Sugerencia de stock ' . now()->subDay()->format('d/m/Y');

        $this->assertEquals(
            $esperado,
            $this->titulo_de($id),
            'La fecha tiene que ser la de la sugerencia. Si dice la de hoy, la migración esta clavando now().'
        );
    }

    /**
     * 🔴 Los tres que NO se tocan. Es el corazón de este archivo: una migración de datos
     * que pisa de más destruye texto que ningún down() puede recuperar.
     *
     * @group chat-ia
     * @test
     */
    public function no_toca_los_titulos_que_no_son_del_formato_viejo()
    {
        // 1. Título que infirió la IA para una conversación de sugerencia.
        $inferido = $this->crear_conversacion(
            'sugerencia_stock',
            'Como viene el stock de tornillos',
            $this->stock_suggestion_id
        );

        // 2. Conversación que abrió la persona: nunca tuvo el formato con #id.
        $del_usuario = $this->crear_conversacion(
            'usuario',
            'Sugerencia de stock #' . $this->stock_suggestion_id,
            $this->stock_suggestion_id
        );

        // 3. Otra familia: el prefijo de compras no puede pisar una de stock ni al revés.
        $otra_familia = $this->crear_conversacion(
            'sugerencia_compra',
            'Sugerencia de stock #' . $this->stock_suggestion_id,
            $this->stock_suggestion_id
        );

        $this->migracion()->up();

        $this->assertEquals('Como viene el stock de tornillos', $this->titulo_de($inferido));
        $this->assertEquals('Sugerencia de stock #' . $this->stock_suggestion_id, $this->titulo_de($del_usuario));
        $this->assertEquals('Sugerencia de stock #' . $this->stock_suggestion_id, $this->titulo_de($otra_familia));
    }

    /**
     * Si la sugerencia ya no está (se borró y la conversación quedó), cae al `created_at`
     * de la conversación en vez de dejar el "#id" colgado.
     *
     * @group chat-ia
     * @test
     */
    public function con_la_sugerencia_borrada_usa_la_fecha_de_la_conversacion()
    {
        $id = $this->crear_conversacion('sugerencia_stock', 'Sugerencia de stock #99999999', 99999999);

        $this->migracion()->up();

        $this->assertEquals(
            'Sugerencia de stock ' . now()->subDay()->format('d/m/Y'),
            $this->titulo_de($id)
        );
    }

    /**
     * Re-ejecutable: después de la primera corrida el título ya no calza con el patrón
     * viejo, así que una segunda pasada no lo vuelve a tocar. Importa porque una
     * migración puede reintentarse en un despliegue que se cortó a la mitad.
     *
     * @group chat-ia
     * @test
     */
    public function correrla_dos_veces_deja_el_mismo_titulo()
    {
        $id = $this->crear_conversacion(
            'sugerencia_stock',
            'Sugerencia de stock #' . $this->stock_suggestion_id,
            $this->stock_suggestion_id
        );

        $migracion = $this->migracion();
        $migracion->up();
        $primera = $this->titulo_de($id);

        $migracion->up();

        $this->assertEquals($primera, $this->titulo_de($id));
    }

    /**
     * El down() reconstruye el formato viejo desde `referencia_id`, que sigue en la fila.
     *
     * @group chat-ia
     * @test
     */
    public function el_down_devuelve_el_titulo_con_el_id()
    {
        $id = $this->crear_conversacion(
            'sugerencia_stock',
            'Sugerencia de stock #' . $this->stock_suggestion_id,
            $this->stock_suggestion_id
        );

        $migracion = $this->migracion();
        $migracion->up();
        $migracion->down();

        $this->assertEquals('Sugerencia de stock #' . $this->stock_suggestion_id, $this->titulo_de($id));
    }

    /**
     * Las tres familias, con su prefijo propio. Sin esto, agregar una familia nueva al
     * mapa y equivocarle el prefijo pasaría sin que nada lo denuncie.
     *
     * @group chat-ia
     * @test
     */
    public function renombra_las_tres_familias_con_su_propio_prefijo()
    {
        $fecha = now()->subDay()->format('d/m/Y');

        $compra_id = DB::table('purchase_suggestions')->insertGetId([
            'user_id'    => $this->comercio->id,
            'status'     => 'terminado',
            'created_at' => now()->subDay()->setTime(9, 15),
            'updated_at' => now(),
        ]);

        $stock   = $this->crear_conversacion('sugerencia_stock', 'Sugerencia de stock #' . $this->stock_suggestion_id, $this->stock_suggestion_id);
        $compra  = $this->crear_conversacion('sugerencia_compra', 'Sugerencia de compra #' . $compra_id, $compra_id);
        // Ofertas sin fila de respaldo: prueba de paso el fallback a la fecha de la conversación.
        $oferta  = $this->crear_conversacion('sugerencia_oferta', 'Ofertas sugeridas #77777777', 77777777);

        $this->migracion()->up();

        $this->assertEquals('Sugerencia de stock ' . $fecha, $this->titulo_de($stock));
        $this->assertEquals('Sugerencia de compra ' . $fecha, $this->titulo_de($compra));
        $this->assertEquals('Ofertas sugeridas ' . $fecha, $this->titulo_de($oferta));
    }
}
