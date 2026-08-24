<?php

namespace Tests\Feature\Compras;

use App\Models\Address;
use App\Models\ProviderDiscount;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * Clase base de los tests de compras (modulo Provider Order), Grupo 184 - Prompt 613.
 *
 * Se queda solo con lo que es especifico de compras (neutralizar bonificaciones de Buenos Aires,
 * setear condicion de IVA, armar el payload de `POST api/provider-order`). Los guards de entorno
 * (base de testing, motor InnoDB, fixture sembrado) y los helpers genericos de fixture
 * (`articulo`, `proveedor`, `snapshot_articulo`, `restaurar_articulo`) se movieron a
 * `Tests\EmpresaTestCase` (Grupo 242, Prompt 01), reusable por las suites nuevas de tesoreria,
 * reportes e IVA por condicion sin copiar y pegar nada.
 *
 * Usa `DatabaseTransactions` (heredado de `EmpresaTestCase`, NO `RefreshDatabase`): cada test
 * corre dentro de una transaccion que en teoria se revierte al terminar, para que una compra
 * confirmada en un test no contamine el siguiente, sin pagar el costo de reconstruir 600+
 * migraciones por test.
 *
 * ⚠️ DEUDA TECNICA ALTA (hallazgo real, Prompt 615): en este entorno `DatabaseTransactions` NO
 * revierte nada de verdad si las tablas de `empresa_testing` son motor MyISAM (no soporta
 * transacciones — `BEGIN`/`ROLLBACK` se ejecutan sin error pero son no-ops). El guard
 * `verificar_motor_innodb()` de `EmpresaTestCase` (Prompt 01, Grupo 242) ahora aborta la suite
 * temprano si detecta ese estado, en vez de dejar que los tests fallen en silencio por motivos
 * falsos. Los tests de costeo (614/615) sobrevivian a esto porque siempre pisan los mismos
 * valores absolutos (cost, total) en cada corrida — el resultado es idempotente aunque no haya
 * rollback real. Pero cualquier test que dependa de un valor RELATIVO al estado previo (stock
 * acumulado, saldo de cuenta corriente, o un campo que se edita a un valor DISTINTO del que trae
 * el seeder, como `iva_id`) puede corromper el fixture para las corridas siguientes en silencio.
 * Si un test necesita mutar un campo del fixture a un valor distinto del original, tiene que
 * restaurarlo el mismo (ver `try/finally` en
 * `Costeo_MT_Test::deriva_por_alicuota_editada_es_comportamiento_documentado_no_bug`, Prompt 615).
 *
 * Los tests concretos (prompts 614/615/616) extienden esta clase y usan sus helpers protegidos
 * (`articulo`, `proveedor`, `set_condicion_iva`, `payload_compra`, `item`) para resolver todo por
 * nombre desde el fixture de `TestingFerreteriaSeeder` — nunca por ID hardcodeado.
 */
abstract class ComprasTestCase extends EmpresaTestCase
{
    /**
     * Copia (arrays, no instancias) de las bonificaciones de Buenos Aires que
     * `quitar_bonificaciones_de_buenos_aires()` haya borrado en el test en curso, para que
     * `tearDown()` las recree y no corrompa el fixture para el resto de la suite (Prompt 616).
     * `null` cuando el test no llamo a ese helper.
     *
     * @var array<int,array<string,mixed>>|null
     */
    protected $bonificaciones_bsas_borradas = null;

    /**
     * setUp de cada test: delega en `EmpresaTestCase::setUp()` (guards de entorno + autenticacion
     * del usuario de prueba). No agrega nada propio: compras no necesita ningun setup extra antes
     * del test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Neutraliza, solo para el test en curso, las bonificaciones de catalogo del proveedor Buenos
     * Aires (10% y 5%, ver `TestingFerreteriaSeeder::seed_bonificaciones`).
     *
     * Movido acá (Grupo 184, Prompt 615) desde `Costeo_RRII_Test` (Prompt 614), porque los tests
     * de MT/multi-alicuota (este mismo prompt) tambien arman compras "limpias" (sin descuentos de
     * catalogo en juego) contra Buenos Aires y necesitan el mismo seguro. `ProviderOrderController::
     * store()` llama a `precargar_bonificaciones_proveedor()`, que copia AUTOMATICAMENTE las
     * bonificaciones del proveedor como `provider_order_discounts` de la orden apenas se crea (si
     * la orden no trae descuentos propios) — y varios articulos de este fixture (Pinza, Alicate,
     * Cuchilla, Martillo acero, Cuchara) son de Buenos Aires, el proveedor que el fixture del
     * Prompt 613 dejo con bonificaciones a proposito (para los prompts de descuentos, 616/617).
     * Sin este helper, los totales de estos tests quedarian contaminados por un descuento que la
     * especificacion no pidio. Se borra la fila maestra de `provider_discounts` (no la orden ni
     * sus `provider_order_discounts`, que en el momento de llamar a este helper todavia no
     * existen): al no haber nada que copiar, `precargar_bonificaciones_proveedor()` no crea nada.
     *
     * CORRECCION (Grupo 184, Prompt 616): el comentario original decia que `DatabaseTransactions`
     * revierte el borrado al terminar el test — FALSO, y es justo la deuda de MyISAM documentada
     * en el docblock de esta clase (ninguna de las ~360 tablas soporta transacciones de verdad).
     * Sin restaurar, esto dejaba a Buenos Aires SIN bonificaciones para siempre en la base de
     * testing apenas corria un test que llamara a este helper — bug real detectado al escribir
     * los tests de descuentos (616), que SI necesitan que las bonificaciones esten presentes y
     * fallaban en rojo por este motivo al correr la suite completa. Ahora se guarda una copia de
     * los datos originales (no las instancias) antes de borrar, y `tearDown()` los recrea
     * siempre, sin importar como termine el test.
     *
     * @return void
     */
    protected function quitar_bonificaciones_de_buenos_aires()
    {
        $proveedor_bsas = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);

        $this->bonificaciones_bsas_borradas = ProviderDiscount::where('provider_id', $proveedor_bsas->id)
                                                                ->get()
                                                                ->map(function ($discount) {
                                                                    return $discount->toArray();
                                                                })
                                                                ->all();

        ProviderDiscount::where('provider_id', $proveedor_bsas->id)->delete();
    }

    /**
     * tearDown de cada test: si `quitar_bonificaciones_de_buenos_aires()` borro bonificaciones
     * durante este test, las recrea acá (mismos datos, sin el `id` original) para dejar el
     * fixture intacto de cara al resto de la suite — ver la correccion documentada arriba.
     * Llama a `parent::tearDown()` para no perder ningun tearDown que agregue `EmpresaTestCase`
     * (hoy no tiene, pero mantiene la cadena correcta hacia `Tests\TestCase`).
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (!is_null($this->bonificaciones_bsas_borradas)) {

            foreach ($this->bonificaciones_bsas_borradas as $datos_bonificacion) {

                unset($datos_bonificacion['id']);

                ProviderDiscount::create($datos_bonificacion);
            }

            $this->bonificaciones_bsas_borradas = null;
        }

        parent::tearDown();
    }

    /**
     * Setea `condicion_iva_precios` ('RRII' o 'MT') directo sobre el usuario de prueba.
     *
     * Grupo 231, prompt 01: la condicion fiscal se movio de `user_configurations` a
     * `users.condicion_iva_precios`. Se escribe directo sobre el usuario de prueba (sin pasar por
     * `->save()` de una relacion) porque el request de `POST api/provider-order` levanta el
     * usuario fresco desde la base en cada corrida, asi que no hace falta recargar ninguna
     * relacion en memoria.
     *
     * El guard de `Schema::hasColumn` queda como red por si la migracion no corrio todavia en el
     * entorno de test: si se pide 'MT' sin la columna, se aborta el test con un mensaje explicito
     * en vez de fallar con un error de SQL criptico. Pedir 'RRII' sin la columna sigue siendo un
     * no-op seguro, porque 'RRII' sigue siendo el fallback por defecto del sistema.
     *
     * @param string $valor 'RRII' o 'MT'.
     * @return void
     */
    protected function set_condicion_iva($valor)
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        if (!Schema::hasColumn('users', 'condicion_iva_precios')) {

            if ($valor == 'MT') {
                $this->markTestSkipped(
                    'Falta la columna users.condicion_iva_precios (grupo 231, prompt 01, no '.
                    'corrio todavia): no se puede forzar la condicion MT. RRII sigue funcionando '.
                    'porque es el fallback seguro de NewProviderOrderHelper::get_condicion_iva_precios().'
                );
            }

            // 'RRII' sin la columna: no-op, ya es el fallback por defecto del sistema.
            return;
        }

        $user->condicion_iva_precios = $valor;
        $user->save();
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
