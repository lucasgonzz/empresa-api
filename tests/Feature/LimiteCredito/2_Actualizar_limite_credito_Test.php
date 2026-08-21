<?php

namespace Tests\Feature\LimiteCredito;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * `CreditAccountController::update_limite_credito()` (misión 160) — el endpoint que la pantalla
 * de cliente usa para fijar o borrar el límite de UNA cuenta corriente (entidad × moneda). No
 * tenía ningún test hasta el chequeo independiente de esta misión.
 *
 * Cubre: el scoping por `user_id` (el `where` que evita que un POST directo edite el límite de
 * un cliente de otro comercio), la validación de `model_name`/`moneda_id`, y la normalización del
 * valor de `limite_credito` — incluido el bug de redondeo encontrado en el chequeo independiente
 * (un input como "0.001" tiene que quedar en null, no en 0.00).
 */
class Actualizar_limite_credito_Test extends EmpresaTestCase
{
    /**
     * `user_id` de un segundo comercio ya presente en la base de testing (lo usan los tests de
     * `tests/Import/`), para probar el scoping sin tener que dar de alta un usuario nuevo.
     */
    const USER_ID_OTRO_COMERCIO = 900;

    /**
     * `model_id` que no corresponde a ningún cliente real del fixture: alcanza para probar el
     * scoping, porque el endpoint nunca valida que el cliente "exista de verdad" para ese
     * `user_id`, sólo que la credit_account de ese model_id + moneda le pertenezca.
     */
    const MODEL_ID_AJENO = 999999999;

    /**
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * @param string $nombre
     * @return \App\Models\Client
     */
    protected function cliente($nombre)
    {
        $cliente = Client::where('name', $nombre)
            ->where('user_id', $this->usuario_de_testing()->id)
            ->first();

        if (is_null($cliente)) {
            $this->fail('No existe el cliente "'.$nombre.'" en el fixture de testing.');
        }

        return $cliente;
    }

    /**
     * @param array<string,mixed> $payload
     * @return \Illuminate\Testing\TestResponse
     */
    protected function actualizar_limite($payload)
    {
        return $this->postJson('api/credit-account/limite-credito', $payload);
    }

    /**
     * El `where('user_id', $this->userId())` del controller es lo único que evita que un POST
     * directo con un `model_id` ajeno edite el límite de un cliente de otro comercio. Se pre-siembra
     * la credit_account "ajena" (mismo model_name/model_id/moneda_id, `user_id` de OTRO comercio)
     * ANTES de pegarle al endpoint: así `CreditAccountHelper::crear_credit_accounts()` la encuentra
     * por su chequeo de existencia (model_name+model_id+moneda_id, sin filtrar por user_id) y no
     * crea una segunda fila — que es justo lo que deja en pie el escenario a probar.
     *
     * @group limite_credito
     * @test
     */
    public function no_se_puede_fijar_el_limite_de_una_credit_account_de_otro_comercio()
    {
        $cuenta_ajena = CreditAccount::firstOrCreate(
            [
                'model_name' => 'client',
                'model_id'   => self::MODEL_ID_AJENO,
                'moneda_id'  => 1,
            ],
            [
                'user_id' => self::USER_ID_OTRO_COMERCIO,
                'saldo'   => 0,
            ]
        );

        $respuesta = $this->actualizar_limite([
            'model_name'     => 'client',
            'model_id'       => self::MODEL_ID_AJENO,
            'moneda_id'      => 1,
            'limite_credito' => 50000,
        ]);

        $respuesta->assertStatus(404);

        $this->assertNull(
            CreditAccount::find($cuenta_ajena->id)->limite_credito,
            'El POST tiene que haber sido rechazado ANTES de tocar la fila: no le pertenece a este user_id.'
        );
    }

    /**
     * @group limite_credito
     * @test
     */
    public function model_name_invalido_devuelve_422()
    {
        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $respuesta = $this->actualizar_limite([
            'model_name'     => 'articulo',
            'model_id'       => $client->id,
            'moneda_id'      => 1,
            'limite_credito' => 1000,
        ]);

        $respuesta->assertStatus(422);
    }

    /**
     * @group limite_credito
     * @test
     */
    public function moneda_id_fuera_de_rango_devuelve_422()
    {
        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $respuesta = $this->actualizar_limite([
            'model_name'     => 'client',
            'model_id'       => $client->id,
            'moneda_id'      => 3,
            'limite_credito' => 1000,
        ]);

        $respuesta->assertStatus(422);
    }

    /**
     * @group limite_credito
     * @test
     */
    public function limite_negativo_se_guarda_como_null()
    {
        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $respuesta = $this->actualizar_limite([
            'model_name'     => 'client',
            'model_id'       => $client->id,
            'moneda_id'      => 1,
            'limite_credito' => -500,
        ]);

        $respuesta->assertStatus(200);

        $cuenta = CreditAccount::where('model_name', 'client')
            ->where('model_id', $client->id)
            ->where('moneda_id', 1)
            ->first();

        $this->assertNull($cuenta->limite_credito);
    }

    /**
     * Comportamiento aceptado, no un exploit: un valor no numérico se interpreta como 0 (float)
     * y, como cualquier valor <= 0, se guarda como null. Se deja asentado con un test para que un
     * cambio futuro que lo altere sea una decisión explícita, no un efecto secundario.
     *
     * @group limite_credito
     * @test
     */
    public function limite_no_numerico_se_guarda_como_null()
    {
        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $respuesta = $this->actualizar_limite([
            'model_name'     => 'client',
            'model_id'       => $client->id,
            'moneda_id'      => 1,
            'limite_credito' => 'abc',
        ]);

        $respuesta->assertStatus(200);

        $cuenta = CreditAccount::where('model_name', 'client')
            ->where('model_id', $client->id)
            ->where('moneda_id', 1)
            ->first();

        $this->assertNull($cuenta->limite_credito);
    }

    /**
     * 🔴 Regresión del bug encontrado en el chequeo independiente: el chequeo "<= 0" corría ANTES
     * de redondear, así que "0.001" no era <= 0 y se guardaba round(0.001, 2) = 0.00 — el mismo
     * "límite en 0 sin querer" que el propio comentario del código dice que hay que evitar (0
     * frenaría TODAS las ventas del cliente). Ahora redondea primero y compara después, así que
     * tiene que quedar en null, igual que cualquier otro valor que redondea a 0 o menos.
     *
     * @group limite_credito
     * @test
     */
    public function limite_muy_chico_no_queda_en_cero_por_redondeo()
    {
        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $respuesta = $this->actualizar_limite([
            'model_name'     => 'client',
            'model_id'       => $client->id,
            'moneda_id'      => 1,
            'limite_credito' => '0.001',
        ]);

        $respuesta->assertStatus(200);

        $cuenta = CreditAccount::where('model_name', 'client')
            ->where('model_id', $client->id)
            ->where('moneda_id', 1)
            ->first();

        $this->assertNull(
            $cuenta->limite_credito,
            'Un límite de 0.001 tiene que quedar en null (sin límite), no en 0.00 (bloquea todo).'
        );
    }
}
