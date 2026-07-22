<?php

namespace Tests\Feature\Compras;

use App\Models\Address;
use App\Models\Article;
use App\Models\Provider;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Clase base de los tests de compras (modulo Provider Order), Grupo 184 - Prompt 613.
 *
 * Usa `DatabaseTransactions` (NO `RefreshDatabase`): cada test corre dentro de una transaccion que
 * se revierte al terminar, para que una compra confirmada en un test no contamine el siguiente, sin
 * pagar el costo de reconstruir 600+ migraciones por test.
 *
 * Los tests concretos (prompts 614/615/616) extienden esta clase y usan sus helpers protegidos
 * (`articulo`, `proveedor`, `set_condicion_iva`, `payload_compra`, `item`) para resolver todo por
 * nombre desde el fixture de `TestingFerreteriaSeeder` — nunca por ID hardcodeado.
 */
abstract class ComprasTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * setUp de cada test: corre los dos seguros de entorno (base de testing real, fixture
     * sembrado) y autentica al usuario de prueba antes de que arranque el test concreto.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->verificar_base_de_testing();

        $this->verificar_fixture_sembrado();

        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->actingAs($user, 'web');
    }

    /**
     * Seguro (tarea 1): aborta la suite si la conexion activa no apunta a una base cuyo nombre
     * contenga "testing". Es la proteccion contra correr esta suite apuntando a la base de
     * desarrollo por un `.env.testing` mal configurado (o inexistente, cayendo al `.env` normal).
     *
     * @return void
     */
    protected function verificar_base_de_testing()
    {
        /** Nombre de la base realmente conectada (no lo que dice el .env, sino la conexion activa). */
        $database_name = (string) DB::connection()->getDatabaseName();

        if (strpos($database_name, 'testing') === false) {
            $this->fail(
                'SEGURO: la conexion activa apunta a la base "'.$database_name.'", que no contiene '.
                '"testing" en su nombre. Se aborta la suite de compras para evitar correr tests sobre '.
                'una base que podria ser la de desarrollo. Revisa que exista .env.testing (copia de '.
                '.env.testing.example) con DB_DATABASE conteniendo "testing" (ej. empresa_testing).'
            );
        }
    }

    /**
     * Seguro (tarea 3): aborta si el fixture de `TestingFerreteriaSeeder` no fue sembrado en la
     * base de testing conectada, con un mensaje que indica el comando exacto para sembrarlo.
     *
     * @return void
     */
    protected function verificar_fixture_sembrado()
    {
        if (is_null($this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA))) {
            $this->fail(
                'Falta el fixture de testing: no se encontro el articulo "'.
                TestingFerreteriaSeeder::ARTICULO_CENTINELA.'". Sembra la base de testing con: '.
                'php artisan migrate:fresh && php artisan db:seed --class="Database\\Seeders\\testing\\TestingFerreteriaSeeder"'
            );
        }
    }

    /**
     * Devuelve el Article del fixture por nombre (nunca resolver por id hardcodeado en un test).
     *
     * @param string $nombre
     * @return \App\Models\Article|null
     */
    protected function articulo($nombre)
    {
        return Article::where('name', $nombre)->first();
    }

    /**
     * Devuelve el Provider del fixture por nombre.
     *
     * @param string $nombre
     * @return \App\Models\Provider|null
     */
    protected function proveedor($nombre)
    {
        return Provider::where('name', $nombre)->first();
    }

    /**
     * Setea `condicion_iva_precios` ('RRII' o 'MT') en la configuracion del usuario de prueba y
     * recarga la relacion, para que los helpers que la leen en el mismo request
     * (`NewProviderOrderHelper::get_condicion_iva_precios()`) vean el valor actualizado.
     *
     * @param string $valor 'RRII' o 'MT'.
     * @return void
     */
    protected function set_condicion_iva($valor)
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $user->configuration->condicion_iva_precios = $valor;
        $user->configuration->save();

        $user->load('configuration');
    }

    /**
     * Arma el request de `POST api/provider-order` con los defaults del escenario de testing
     * (modo de facturacion automatico, `update_prices`/`update_stock` en 1, deposito Principal,
     * proveedor Buenos Aires) y permite pisar cualquier clave via $overrides.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected function payload_compra($overrides = [])
    {
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $deposito  = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        $defaults = [
            'provider_id'                             => $proveedor->id,
            'address_id'                               => $deposito->id,
            'modo_facturacion'                         => 'automatico',
            'update_prices'                             => 1,
            'update_stock'                              => 1,
            'total_with_iva'                            => 1,
            'precios_incluyen_iva'                      => 0,
            'moneda_id'                                 => 2,
            'generate_current_acount'                   => 1,
            'total_from_provider_order_afip_tickets'    => 0,
            'provider_order_status_id'                  => 1,
            'days_to_advise'                             => null,
            'numero_comprobante'                        => null,
            'articles'                                   => [],
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Arma una entrada del array `articles` del request de provider-order, con su `pivot`,
     * respetando las claves reales que lee `attach_articles()` en
     * `NewProviderOrderHelper` (`cost`, `amount`, `received`, `price`, `discount`, `iva_id`,
     * `cost_in_dollars`, `update_provider`).
     *
     * @param string $nombre Nombre del articulo del fixture (ver TestingFerreteriaSeeder::catalogo).
     * @param float $cost Costo bruto cargado en esta linea de la compra.
     * @param float $amount Cantidad pedida.
     * @param array<string,mixed> $extra Overrides puntuales del pivot (ej. 'discount', 'received').
     * @return array<string,mixed>
     */
    protected function item($nombre, $cost, $amount, $extra = [])
    {
        $articulo = $this->articulo($nombre);

        $pivot = array_merge([
            'cost'            => $cost,
            'amount'          => $amount,
            'received'        => $amount,
            'price'           => null,
            'discount'        => null,
            'iva_id'          => $articulo->iva_id,
            'cost_in_dollars' => 0,
            'update_provider' => 1,
        ], $extra);

        return [
            'id'            => $articulo->id,
            'bar_code'      => $articulo->bar_code,
            'provider_code' => $articulo->provider_code,
            'pivot'         => $pivot,
        ];
    }
}
