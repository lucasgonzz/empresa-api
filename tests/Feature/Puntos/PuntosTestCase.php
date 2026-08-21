<?php

namespace Tests\Feature\Puntos;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\puntos\PuntosConfigHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoPunto;
use App\Models\MovimientoPuntoConsumo;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\SistemaDePuntos;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Base comun de la suite del sistema de puntos para clientes (misión puntos-clientes-mvp).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  Por qué esta clase existe, y qué NO hay que volver a descubrir cada vez
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. `DatabaseTransactions`, NUNCA `RefreshDatabase` — lo hereda de EmpresaTestCase. La base
 *     del slot está sembrada de antes con TestingFerreteriaSeeder y un refresh la vaciaría,
 *     rompiendo todas las demás suites.
 *
 *  2. `extencion_empresas` está VACÍA en la base del slot (verificado el 21/8/2026). O sea que
 *     ningún test puede asumir que el catálogo esté sembrado: cada uno crea su propia fila y se
 *     la asigna al comercio, igual que hace tests/Feature/Extenciones.
 *
 *  3. 🔴 `ExtencionEmpresa` no declara `$guarded` ni `$fillable`, así que un `create()` común no
 *     asigna nada y `firstOrCreate()` tira MassAssignmentException. Se usa `forceCreate()`, que es
 *     lo mismo que hace el resto de las suites. El seeder anda solo por el camino de
 *     `artisan db:seed`, que envuelve todo en `Model::unguarded()`.
 *
 *  4. 🔴 `PuntosConfigHelper` memoiza extensión y programa activo en `static` por `user_id`, y esa
 *     memoria dura todo el proceso de PHPUnit — no el test. Un test que prende la extensión y otro
 *     que la apaga se pisan entre sí. Por eso se llama a `limpiar_memo()` en el setUp, en el
 *     tearDown y cada vez que se toca la configuración adentro de un test.
 *
 *  5. La relación del modelo `User` se llama `extencions()` (así, con esa ortografía), no
 *     `extencion_empresas()`.
 *
 * Todo lo que estos tests verifican se lee de la BASE después de pegarle al endpoint real: nunca
 * el valor de retorno de un helper.
 */
abstract class PuntosTestCase extends EmpresaTestCase
{
    /** El slug con el que la extensión gatea todo el módulo. */
    const SLUG = 'puntos_clientes';

    /** El nombre con el que la extensión se muestra al asignarla en el admin. */
    const NOMBRE_EXTENCION = 'Sistema de puntos para clientes';

    /** Tolerancia de un centavo, el mismo criterio que usan los helpers del módulo. */
    const DELTA = 0.01;

    /**
     * La fila del catálogo que creó este test, para poder desasignarla en el tearDown.
     *
     * @var \App\Models\ExtencionEmpresa|null
     */
    protected $extencion = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * La memoria del helper de configuración es estática y sobrevive de un test al otro
         * dentro del mismo proceso de PHPUnit. Sin esto, el primer test que corre decide la
         * respuesta de todos los demás.
         */
        PuntosConfigHelper::limpiar_memo();

        Carbon::setTestNow(null);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        PuntosConfigHelper::limpiar_memo();

        parent::tearDown();
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Extensión y programa
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Crea la fila del catálogo (si no está) y se la asigna al comercio de testing.
     *
     * @return \App\Models\ExtencionEmpresa
     */
    protected function dar_extencion()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($extencion)) {
            // forceCreate: el modelo no declara $fillable y afuera de `artisan db:seed` no rige
            // el Model::unguarded() que aplica el comando.
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => self::NOMBRE_EXTENCION,
            ]);
        }

        $user = $this->comercio();

        $user->extencions()->syncWithoutDetaching([$extencion->id]);
        $user->load('extencions');

        $this->extencion = $extencion;

        PuntosConfigHelper::limpiar_memo();

        return $extencion;
    }

    /**
     * Le da al comercio OTRA extensión del sistema, por slug.
     *
     * Hace falta para los tests que necesitan que un flujo ajeno al módulo se comporte de cierta
     * manera: `SaleHelper::get_terminada()`, por ejemplo, solo deja una venta sin terminar si el
     * comercio tiene `ventas_con_fecha_de_entrega`. Como `extencion_empresas` está vacía en la base
     * del slot, la fila del catálogo también hay que crearla.
     *
     * @param  string  $slug
     * @param  string  $nombre
     * @return \App\Models\ExtencionEmpresa
     */
    protected function dar_otra_extencion($slug, $nombre)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $nombre]);
        }

        $user = $this->comercio();

        $user->extencions()->syncWithoutDetaching([$extencion->id]);
        $user->load('extencions');

        PuntosConfigHelper::limpiar_memo();

        return $extencion;
    }

    /**
     * Le saca la extensión al comercio, dejando la fila del catálogo donde está.
     *
     * @return void
     */
    protected function quitar_extencion()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!is_null($extencion)) {
            $user = $this->comercio();
            $user->extencions()->detach($extencion->id);
            $user->load('extencions');
        }

        PuntosConfigHelper::limpiar_memo();
    }

    /**
     * Crea un programa de puntos activo para el comercio de testing.
     *
     * Los defaults son los de la migración, que son también los que muestra el ABM: 1 punto cada
     * $1.000 de base neta, el punto vale $10, vencen a los 12 meses, mínimo de canje 500 puntos y
     * tope del 20% de la compra.
     *
     * @param  array  $atributos     Lo que se quiera pisar de esos defaults.
     * @param  array  $price_type_ids  Las listas de precio habilitadas. Vacío = el programa no
     *                                 filtra por lista y aplica a todo.
     * @return \App\Models\SistemaDePuntos
     */
    protected function crear_programa($atributos = [], $price_type_ids = [])
    {
        $datos = array_merge([
            'user_id'           => $this->comercio()->id,
            'nombre'            => 'Programa de prueba',
            'activo'            => 1,
            'puntos_cada'       => 1000,
            'puntos_por_tramo'  => 1,
            'valor_punto'       => 10,
            'vencimiento_meses' => 12,
            'minimo_canje'      => 500,
            'tope_porcentaje'   => 20,
        ], $atributos);

        $sistema = SistemaDePuntos::create($datos);

        if (count($price_type_ids)) {
            $sistema->price_types()->sync($price_type_ids);
        }

        PuntosConfigHelper::limpiar_memo();

        return $sistema->fresh();
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Fixture
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El comercio de testing (el `USER_ID` del .env.testing, resuelto por email para no depender
     * de un id hardcodeado).
     *
     * @return \App\Models\User
     */
    protected function comercio()
    {
        $user = \App\Models\User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el comercio de prueba sembrado.');
        }

        return $user;
    }

    /**
     * @param  string  $nombre
     * @return \App\Models\Client
     */
    protected function cliente($nombre = TestingFerreteriaSeeder::CLIENTE_CC)
    {
        $cliente = Client::where('name', $nombre)
                            ->where('user_id', $this->comercio()->id)
                            ->first();

        if (is_null($cliente)) {
            $this->fail('No existe el cliente "'.$nombre.'" en el fixture de testing.');
        }

        return $cliente;
    }

    /**
     * @param  string  $nombre
     * @return \App\Models\Article
     */
    protected function articulo_de_puntos($nombre = TestingFerreteriaSeeder::ARTICULO_CENTINELA)
    {
        $articulo = Article::where('name', $nombre)
                            ->where('user_id', $this->comercio()->id)
                            ->first();

        if (is_null($articulo)) {
            $this->fail('No existe el articulo "'.$nombre.'" en el fixture de testing.');
        }

        return $articulo;
    }

    /**
     * Una lista de precio del comercio, por nombre.
     *
     * @param  string  $nombre
     * @return \App\Models\PriceType
     */
    protected function lista_de_precio($nombre)
    {
        $lista = PriceType::where('name', $nombre)
                            ->where('user_id', $this->comercio()->id)
                            ->first();

        if (is_null($lista)) {
            $this->fail('No existe la lista de precio "'.$nombre.'" en el fixture de testing.');
        }

        return $lista;
    }

    /**
     * La cuenta corriente en pesos del cliente, creándola si el fixture no la trae.
     *
     * @param  \App\Models\Client  $cliente
     * @param  int                 $moneda_id
     * @return \App\Models\CreditAccount
     */
    protected function credit_account($cliente, $moneda_id = 1)
    {
        $cuenta = CreditAccount::where('model_name', 'client')
                                ->where('model_id', $cliente->id)
                                ->where('moneda_id', $moneda_id)
                                ->first();

        if (is_null($cuenta)) {

            CreditAccountHelper::crear_credit_accounts('client', $cliente->id);

            $cuenta = CreditAccount::where('model_name', 'client')
                                    ->where('model_id', $cliente->id)
                                    ->where('moneda_id', $moneda_id)
                                    ->first();
        }

        if (is_null($cuenta)) {
            $this->fail('No se pudo resolver la cuenta corriente del cliente "'.$cliente->name.'".');
        }

        return $cuenta;
    }

    /**
     * Garantiza que el cliente tenga cuenta corriente ANTES de que se le guarde una venta de
     * cuenta corriente.
     *
     * ⚠️ No es un detalle de higiene: el fixture de testing no precarga `credit_accounts` para los
     * clientes (verificado el 21/8/2026: solo hay de proveedores), y sin la cuenta
     * `CurrentAcountFromSaleHelper::crear_current_acount()` revienta con
     * "Trying to get property 'id' of non-object" y el POST de la venta devuelve 500.
     *
     * @param  \App\Models\Client  $cliente
     * @return \App\Models\CreditAccount
     */
    protected function asegurar_cuenta_corriente($cliente)
    {
        return $this->credit_account($cliente);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Ventas
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Payload de POST api/sale con un solo renglón del artículo centinela.
     *
     * `price_vender * amount` es el total con IVA; el módulo de puntos trabaja sobre el neto, que
     * lo persiste `SaleHelper::attachArticle()` en `article_sale.price_sin_iva`.
     *
     * @param  int|null  $client_id
     * @param  float     $total       Total con IVA de la venta.
     * @param  array     $overrides
     * @return array
     */
    protected function payload_venta($client_id, $total, $overrides = [])
    {
        $articulo = $this->articulo_de_puntos();

        $payload = [
            'client_id'                  => $client_id,
            'address_id'                 => null,
            'save_current_acount'        => 1,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'employee_id'                => null,
            'sub_total'                  => $total,
            'total'                      => $total,
            'terminada'                  => 1,
            'seller_id'                  => null,
            'cantidad_cuotas'            => null,
            'cuota_descuento'            => 0,
            'cuota_recargo'              => 0,
            'caja_id'                    => null,
            'afip_tipo_comprobante_id'   => null,
            'descuento'                  => null,
            'moneda_id'                  => 1,
            'discounts'                  => [],
            'surchages'                  => [],
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => $total,
                    'amount'       => 1,
                ],
            ],
        ];

        return array_merge($payload, $overrides);
    }

    /**
     * @param  array  $payload
     * @return \Illuminate\Testing\TestResponse
     */
    protected function postear_venta($payload)
    {
        return $this->postJson('api/sale', $payload);
    }

    /**
     * Crea una venta por el endpoint real y devuelve el modelo.
     *
     * @param  array  $payload
     * @return \App\Models\Sale
     */
    protected function crear_venta($payload)
    {
        $response = $this->postear_venta($payload);

        $response->assertStatus(201);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('model', $cuerpo, 'POST api/sale no devolvió la venta creada.');

        return Sale::find($cuerpo['model']['id']);
    }

    /**
     * Payload mínimo de PUT api/sale/{id}.
     *
     * to_check / checked / confirmed / discounts_in_services / surchages_in_services son NOT NULL
     * en la tabla y `update()` los asigna a secas desde el request, así que faltar cualquiera
     * revienta el update con una violación de integridad. Calcado del molde que ya usan
     * tests/Feature/Sales/9 y tests/Feature/LimiteCredito/1.
     *
     * 🔴 Los `items` van SIEMPRE, y no vacíos como en el molde de LimiteCredito.
     * `SaleController@update` hace `SaleHelper::detachItems()` y después vuelve a enganchar lo que
     * venga en `items`: un update con la lista vacía deja la venta SIN renglones, y una venta sin
     * renglones tiene monto base 0, o sea que el módulo de puntos le revierte todo. Para los tests
     * de allá eso daba igual; acá sería medir otra cosa.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float             $total
     * @param  array             $overrides
     * @return array
     */
    protected function payload_actualizar($sale, $total, $overrides = [])
    {
        $payload = [
            'client_id'                  => $sale->client_id,
            'save_current_acount'        => $sale->save_current_acount,
            'omitir_en_cuenta_corriente' => $sale->omitir_en_cuenta_corriente,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'sub_total'                  => $total,
            'total'                      => $total,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => $total,
                    'amount'       => 1,
                ],
            ],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ];

        return array_merge($payload, $overrides);
    }

    /**
     * @param  \App\Models\Sale  $sale
     * @param  array             $payload
     * @return \Illuminate\Testing\TestResponse
     */
    protected function actualizar_venta($sale, $payload)
    {
        return $this->putJson('api/sale/'.$sale->id, $payload);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Cuenta corriente
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Registra un pago de cuenta corriente por el endpoint real.
     *
     * ⚠️ `current_date` decide qué camino toma `CurrentAcountController@pago`: con `1` (que es lo
     * que manda la SPA por default) solo se imputa el pago nuevo; con `0` se recalcula la cuenta
     * corriente entera. Los tests de esta suite lo pasan explícito para que quede a la vista cuál
     * de los dos caminos se está probando.
     *
     * Sin `caja_id`: el pago queda igual de válido (attachPaymentMethods solo loguea un warning) y
     * así los tests no dependen de que haya una caja con apertura en el fixture.
     *
     * @param  \App\Models\Client  $cliente
     * @param  float               $monto
     * @param  int                 $current_date
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pagar($cliente, $monto, $current_date = 1)
    {
        $cuenta = $this->credit_account($cliente);

        $metodo = \App\Models\CurrentAcountPaymentMethod::where('name', TestingFerreteriaSeeder::PAGO_EFECTIVO)->first();

        if (is_null($metodo)) {
            $this->fail('No existe el método de pago "'.TestingFerreteriaSeeder::PAGO_EFECTIVO.'" en el fixture.');
        }

        return $this->postJson('api/current-acount/pago', [
            'description'                    => 'Cobro de la suite de puntos',
            'credit_account_id'              => $cuenta->id,
            'is_provisorio'                  => 0,
            'model_name'                     => 'client',
            'model_id'                       => $cliente->id,
            'haber'                          => $monto,
            'current_date'                   => $current_date,
            'current_acount_payment_methods' => [
                [
                    'current_acount_payment_method_id' => $metodo->id,
                    'amount'                           => $monto,
                ],
            ],
        ]);
    }

    /**
     * El débito de cuenta corriente de una venta (el "debe", no un pago).
     *
     * @param  \App\Models\Sale  $sale
     * @return \App\Models\CurrentAcount|null
     */
    protected function debito_de_la_venta($sale)
    {
        return CurrentAcount::where('sale_id', $sale->id)
                            ->whereNull('haber')
                            ->first();
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Lecturas del libro de puntos
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Los movimientos de una venta, opcionalmente de un solo tipo, ordenados por id.
     *
     * @param  \App\Models\Sale|int  $sale
     * @param  string|null           $tipo
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function movimientos_de_la_venta($sale, $tipo = null)
    {
        $sale_id = is_object($sale) ? $sale->id : $sale;

        $query = MovimientoPunto::where('sale_id', $sale_id);

        if (!is_null($tipo)) {
            $query->where('tipo', $tipo);
        }

        return $query->orderBy('id', 'ASC')->get();
    }

    /**
     * Los movimientos de un cliente, opcionalmente de un solo tipo.
     *
     * @param  \App\Models\Client|int  $cliente
     * @param  string|null             $tipo
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function movimientos_del_cliente($cliente, $tipo = null)
    {
        $client_id = is_object($cliente) ? $cliente->id : $cliente;

        $query = MovimientoPunto::where('user_id', $this->comercio()->id)
                                ->where('client_id', $client_id);

        if (!is_null($tipo)) {
            $query->where('tipo', $tipo);
        }

        return $query->orderBy('id', 'ASC')->get();
    }

    /**
     * El saldo de puntos del cliente, leído de la base con la misma frase que usa el sistema:
     * `SUM(movimiento_puntos.puntos)`, sin ninguna condición extra.
     *
     * @param  \App\Models\Client|int  $cliente
     * @return float
     */
    protected function saldo_de_puntos($cliente)
    {
        $client_id = is_object($cliente) ? $cliente->id : $cliente;

        return round((float) MovimientoPunto::where('user_id', $this->comercio()->id)
                                            ->where('client_id', $client_id)
                                            ->sum('puntos'), 2);
    }

    /**
     * Siembra un lote de puntos a mano, para los tests que necesitan un saldo de partida sin pasar
     * por una venta (el vencimiento y el FIFO del canje).
     *
     * @param  \App\Models\Client            $cliente
     * @param  \App\Models\SistemaDePuntos   $sistema
     * @param  float                         $puntos
     * @param  \Carbon\Carbon|null           $vence_at
     * @param  \Carbon\Carbon|null           $created_at  Para poder ordenar el FIFO a mano.
     * @return \App\Models\MovimientoPunto
     */
    protected function sembrar_lote($cliente, $sistema, $puntos, $vence_at = null, $created_at = null)
    {
        $lote = MovimientoPunto::create([
            'user_id'              => $this->comercio()->id,
            'client_id'            => $cliente->id,
            'sistema_de_puntos_id' => $sistema->id,
            'tipo'                 => 'ganados',
            'puntos'               => $puntos,
            'sale_id'              => null,
            'price_type_id'        => 0,
            'monto_base'           => null,
            'detalle'              => 'Lote sembrado por el test',
            'vence_at'             => $vence_at,
            'consumido'            => 0,
            'employee_id'          => null,
        ]);

        if (!is_null($created_at)) {
            // Se escribe con el query builder para saltear el manejo automático de timestamps.
            MovimientoPunto::where('id', $lote->id)->update(['created_at' => $created_at]);
            $lote->refresh();
        }

        return $lote;
    }

    /**
     * Las filas de consumo de un movimiento (canje o vencimiento), en orden de creación.
     *
     * @param  \App\Models\MovimientoPunto|int  $movimiento
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function consumos_de($movimiento)
    {
        $id = is_object($movimiento) ? $movimiento->id : $movimiento;

        return MovimientoPuntoConsumo::where('movimiento_consumo_id', $id)
                                        ->orderBy('id', 'ASC')
                                        ->get();
    }

    /**
     * El neto sin IVA de un precio, con la misma cuenta que persiste
     * `SaleHelper::get_price_sin_iva()`.
     *
     * @param  float             $precio_con_iva
     * @param  float|string      $iva_percentage
     * @return float
     */
    protected function neto_sin_iva($precio_con_iva, $iva_percentage = 21)
    {
        if ($iva_percentage === 'Exento' || $iva_percentage === 'No Gravado' || (float) $iva_percentage == 0) {
            return round((float) $precio_con_iva, 2);
        }

        return round((float) $precio_con_iva / (((float) $iva_percentage / 100) + 1), 2);
    }
}
