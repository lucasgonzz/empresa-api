<?php

namespace Tests\Feature\CarritosAjusteStock;

use App\Models\Address;
use App\Models\ArticleVariant;
use App\Models\OnlineConfiguration;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Stock\AuditoriaStockTestCase;

/**
 * Misión `carritos-ajuste-stock` — CA7: la matriz del tope de cantidad de la tienda en empresa-api.
 *
 * `App\Http\Controllers\Helpers\cart\StoreStockLimitHelper::limit_for_line($configuration, $article_id, $variant_id)`
 * devuelve la cantidad MÁXIMA que un comprador puede tener en el carrito para ese renglón, con el mismo criterio
 * que tienda-api (`ConsultaStockHelper::clasificar` y `ReservaStockWebHelper`):
 *   - `null` = la tienda no pone tope (no se toca el carrito);
 *   - un número = el tope (puede ser 0 o fraccionario: el redondeo a entero lo hace el ajuste, no este helper).
 * Es un método estático (como el resto de los helpers de `Helpers/`); `$configuration` es el
 * `OnlineConfiguration` del dueño o `null` si no tiene fila; `$variant_id` es `null` para un renglón sin variante.
 *
 * Los casos a) a m) son los del plan (§6 CA7); tienda-api lleva la misma tabla con los mismos nombres de caso
 * (`TopeDeLineaTest`), así los dos lados no pueden divergir sin que un test lo diga.
 *
 * Falla hoy porque la clase `StoreStockLimitHelper` todavía no existe (la crea la construcción): el rojo de los
 * 13 casos es "Class ... not found". Lo que cada caso va a verificar ya está fijado en los datos de abajo.
 *
 * Fixture: artículo propio ("zz") del dueño del fixture; depósito principal = `Address::principal_de()`.
 *
 * IMPORTANTE: PHP 7.4 estricto (ver RESTRICCIONES.md de la misión): nada de sintaxis de PHP 8.
 *
 * @group tienda
 */
class TopeDeLaTiendaTest extends AuditoriaStockTestCase
{
    /** Clase del helper (como string: todavía no existe antes de la construcción). */
    const HELPER = 'App\Http\Controllers\Helpers\cart\StoreStockLimitHelper';

    /**
     * Los casos de la matriz. Claves de cada caso:
     *  - config: flags de online_configurations, o null = el dueño no tiene fila;
     *  - stock: stock global del artículo (null = sin stock cargado);
     *  - variante: null (renglón sin variante) o ['stock' => n|null, 'deposito' => n|null] (renglón con esa variante);
     *  - otra_variante: true = el artículo tiene una variante pero el renglón se consulta SIN variante (caso l);
     *  - deposito: stock del artículo en el depósito principal (null = el artículo no reparte por depósitos);
     *  - reservado: unidades reservadas por otros pedidos en el depósito principal;
     *  - esperado: null (sin tope) o el tope.
     *
     * @return array
     */
    public function casos_de_la_matriz()
    {
        /** Flags de la reserva web activa (con los dos protocolos en 1). */
        $reserva = ['reservar_stock_pedidos_web' => 1, 'reserva_stock_erp_protocolo' => 1, 'reserva_stock_tienda_protocolo' => 1];

        return [
            'a) ignorar_stock, stock 5 -> sin tope' => [[
                'config' => ['ignorar_stock' => 1], 'stock' => 5, 'esperado' => null,
            ]],
            'b) stock null y stock_null_equal_0 apagado -> sin tope' => [[
                'config' => ['stock_null_equal_0' => 0], 'stock' => null, 'esperado' => null,
            ]],
            'c) stock null, stock_null_equal_0 y sin variante -> tope 0' => [[
                'config' => ['stock_null_equal_0' => 1], 'stock' => null, 'esperado' => 0,
            ]],
            'd) variante con stock null y stock_null_equal_0 -> sin tope' => [[
                'config' => ['stock_null_equal_0' => 1], 'stock' => 5, 'variante' => ['stock' => null, 'deposito' => null], 'esperado' => null,
            ]],
            'e) consultas activas y stock 0 -> sin tope' => [[
                'config' => ['permitir_pedidos_sin_stock' => 1], 'stock' => 0, 'esperado' => null,
            ]],
            'f) consultas activas y stock 3 -> tope 3' => [[
                'config' => ['permitir_pedidos_sin_stock' => 1], 'stock' => 3, 'esperado' => 3,
            ]],
            'g) consultas activas y stock -2 -> tope 0' => [[
                'config' => ['permitir_pedidos_sin_stock' => 1], 'stock' => -2, 'esperado' => 0,
            ]],
            'h) sin opciones y stock -200 -> tope 0' => [[
                'config' => [], 'stock' => -200, 'esperado' => 0,
            ]],
            'i) reserva activa, depósito principal 10 y 6 reservados -> tope 4' => [[
                'config' => $reserva, 'stock' => 10, 'deposito' => 10, 'reservado' => 6, 'esperado' => 4,
            ]],
            'j) reserva activa, variante con depósito 5 y 2 reservados -> tope 3' => [[
                'config' => $reserva, 'stock' => 5, 'variante' => ['stock' => 5, 'deposito' => 5], 'reservado' => 2, 'esperado' => 3,
            ]],
            'k) stock fraccionario 2.5 -> tope 2.5' => [[
                'config' => [], 'stock' => 2.5, 'esperado' => 2.5,
            ]],
            'l) artículo con variantes y renglón sin variante -> sin tope' => [[
                'config' => [], 'stock' => 5, 'otra_variante' => true, 'esperado' => null,
            ]],
            'm) el dueño no tiene online_configurations -> sin tope' => [[
                'config' => null, 'stock' => 5, 'esperado' => null,
            ]],
        ];
    }

    /**
     * CA7 — el tope del renglón para cada caso de la matriz.
     *
     * @dataProvider casos_de_la_matriz
     * @test
     *
     * @param array $caso Un caso de `casos_de_la_matriz()`.
     * @return void
     */
    public function test_matriz_del_tope($caso)
    {
        $this->assertTrue(class_exists(self::HELPER), 'Falta la clase ' . self::HELPER . ' (la crea la construcción).');

        /** Dueño del fixture. */
        $user = $this->usuario();

        // Configuración online del dueño: se rehace de cero para que no pese lo que traiga el fixture.
        OnlineConfiguration::where('user_id', $user->id)->delete();

        if (!is_null($caso['config'])) {
            OnlineConfiguration::create(array_merge(['user_id' => $user->id], $caso['config']));
        }

        $articulo = $this->crear_articulo('zz Tope tienda ' . uniqid(), ['stock' => $caso['stock']]);

        /** Depósito principal del comercio (sólo se usa en los casos con depósito o reserva). */
        $principal = Address::principal_de($user->id);

        /** Id de la variante consultada (null = renglón sin variante). */
        $variant_id = null;

        if (isset($caso['variante'])) {
            $variante = ArticleVariant::create([
                'article_id'          => $articulo->id,
                'variant_description' => 'Variante de prueba',
                'stock'               => $caso['variante']['stock'],
                'oculta'              => false,
            ]);

            $variant_id = $variante->id;

            if (!is_null($caso['variante']['deposito'])) {
                $this->assertNotNull($principal, 'El fixture no tiene depósito principal.');
                DB::table('address_article_variant')->insert([
                    'address_id' => $principal->id, 'article_variant_id' => $variante->id, 'amount' => $caso['variante']['deposito'],
                ]);
            }
        }

        if (!empty($caso['otra_variante'])) {
            // El artículo tiene una variante, pero el renglón se consulta sin variante: renglón inválido, sin tope.
            ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Otra', 'stock' => 3, 'oculta' => false]);
        }

        if (isset($caso['deposito'])) {
            $this->assertNotNull($principal, 'El fixture no tiene depósito principal.');
            DB::table('address_article')->insert(['article_id' => $articulo->id, 'address_id' => $principal->id, 'amount' => $caso['deposito']]);
        }

        if (!empty($caso['reservado'])) {
            // Unidades comprometidas por OTRO pedido en el depósito principal (sin movimiento de stock).
            DB::table('order_stock_reservations')->insert([
                'user_id'            => $user->id,
                'order_id'           => 987654400,
                'article_id'         => $articulo->id,
                'article_variant_id' => $variant_id,
                'address_id'         => $principal->id,
                'amount'             => $caso['reservado'],
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }

        /** Configuración fresca desde la base (como la lee el ajuste), o null. */
        $configuracion = OnlineConfiguration::where('user_id', $user->id)->first();

        $tope = call_user_func([self::HELPER, 'limit_for_line'], $configuracion, $articulo->id, $variant_id);

        if (is_null($caso['esperado'])) {
            $this->assertNull($tope, 'Se esperaba "sin tope" (null).');
        } else {
            $this->assertNotNull($tope, 'Se esperaba el tope ' . $caso['esperado'] . ' y vino "sin tope" (null).');
            $this->assertEqualsWithDelta((float) $caso['esperado'], (float) $tope, 0.0001);
        }
    }
}
