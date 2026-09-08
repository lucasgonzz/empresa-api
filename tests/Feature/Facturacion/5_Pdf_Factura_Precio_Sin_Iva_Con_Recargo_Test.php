<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\AfipTicket;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Surchage;
use App\Models\User;
use App\Services\PdfColumnService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Archivo 5 — "Precio sin IVA"/"Subtotal sin IVA" del PDF de factura ARCA reflejan el recargo
 * de la venta, igual que ya lo hacían "Importe IVA"/"Total con IVA".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL BUG QUE ESTA SUITE CIERRA (mision pdf-factura-neto-recargo, 8/9/2026)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `PdfColumnService::resolve_value()` resuelve las cuatro columnas de IVA por línea del PDF
 *  fiscal. Las dos de la izquierda (`item_price_without_iva`, `item_subtotal_without_iva`)
 *  miraban PRIMERO `item->pivot->price_sin_iva` — un snapshot que `SaleHelper::attachArticle()`
 *  calcula a partir del precio UNITARIO del artículo, sin conocer los descuentos/recargos de la
 *  venta (esos se aplican después, a nivel venta). Como ese campo siempre está seteado, el
 *  fallback dinámico y correcto (`$afip_helper->getArticlePrice()`/`subTotal()`) casi nunca se
 *  ejecutaba. Las dos columnas de la derecha (`item_iva_amount`, `item_subtotal_with_iva`)
 *  SIEMPRE pasaban por `$afip_helper`, así que ya reflejaban el recargo — de ahí la asimetría
 *  que reportó Lucas: "Total con IVA" bien, "Precio sin IVA" mal.
 *
 *  El fix invierte el orden de esos dos `case`: primero `$afip_helper` (si está disponible), y
 *  recién si no hay ticket AFIP (remito no fiscal) cae al snapshot de pivot, igual que antes.
 *
 *  🔴 HALLAZGO COLATERAL AL VERIFICAR (no es el bug de esta misión, no se toca acá): el fix
 *  también corrige una inconsistencia de REDONDEO preexistente e independiente del recargo. El
 *  snapshot redondea el precio UNITARIO a 2 decimales y recién ahí multiplica por cantidad;
 *  `AfipItemCalculator` multiplica primero (con precisión completa) y redondea al formatear. Con
 *  cantidad > 1 y un precio que no divide limpio por el factor de IVA, los dos caminos pueden
 *  diferir en 1 centavo — HOY, aunque no haya recargo. Medido con precio $1.000, cantidad 3, IVA
 *  21%: el snapshot da "Subtotal sin IVA" $2.479,35, pero "Subtotal sin IVA" sin redondear
 *  ($2.479,34) + "Importe IVA" ($520,66) da $3.000,00 exacto contra el $3.000,01 que da hoy la
 *  combinación snapshot + Importe IVA. El test 2 de abajo lo deja registrado con la misma
 *  tolerancia de un centavo del test 1, en vez de pedir igualdad exacta en esa única columna.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group facturacion
 */
class Pdf_Factura_Precio_Sin_Iva_Con_Recargo_Test extends EmpresaTestCase
{
    /**
     * Delta para comparaciones de plata.
     */
    const DELTA = 0.01;

    /**
     * Arma una venta mínima del comercio del fixture, con cliente Responsable Inscripto (para
     * que las columnas de IVA discriminado del PDF fiscal tengan sentido) y un renglón del
     * artículo centinela (21% IVA, real en el fixture).
     *
     * `price_sin_iva` se completa con `SaleHelper::get_price_sin_iva()`, EXACTAMENTE como lo
     * hace `SaleHelper::attachArticle()` en producción: a partir del precio unitario nomás, sin
     * mirar la venta. Es el snapshot que el bug usaba siempre y que, después del fix, pasa a ser
     * el fallback para cuando no hay ticket AFIP.
     *
     * @param  float  $price   Precio unitario CON IVA del renglón.
     * @param  int    $amount  Cantidad del renglón.
     * @return array
     */
    protected function armar_venta_con_renglon($price, $amount)
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->first();
        $this->assertNotNull($client, 'Falta el cliente Responsable Inscripto del fixture.');

        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $this->assertNotNull($articulo, 'Falta el artículo centinela del fixture.');
        $this->assertNotNull($articulo->iva, 'El artículo centinela del fixture no tiene IVA.');

        $sale = Sale::create([
            'user_id'                          => $user->id,
            'client_id'                        => $client->id,
            'omitir_en_cuenta_corriente'       => 0,
            'save_current_acount'              => 0,
            'terminada'                        => 1,
            'is_cerrada'                       => 0,
            'sub_total'                        => $price * $amount,
            'total'                            => $price * $amount,
            'moneda_id'                        => 1,
            'descuento'                        => 0,
            'aplicar_recargos_directo_a_items' => 0,
        ]);

        $price_sin_iva = SaleHelper::get_price_sin_iva(['id' => $articulo->id], $price);

        $sale->articles()->attach($articulo->id, [
            'amount'         => $amount,
            'price'          => $price,
            'price_sin_iva'  => $price_sin_iva,
            'iva_percentage' => $articulo->iva->percentage,
        ]);

        return $this->releer_primer_renglon($sale);
    }

    /**
     * Recarga la venta y devuelve su primer renglón marcado `is_article`, igual que
     * `NewSalePdf::get_sale_items()` lo marca ANTES de resolver columnas. Se usa tanto al armar
     * la venta como después de engancharle un recargo (que también invalida la relación
     * `articles` cacheada en el objeto viejo).
     *
     * @param  \App\Models\Sale  $sale
     * @return array
     */
    protected function releer_primer_renglon($sale)
    {
        $sale = $sale->fresh();
        $item = $sale->articles->first();

        $this->assertNotNull($item, 'La venta del test se quedó sin renglones.');

        /**
         * `AfipItemCalculator::get_article_price_with_discounts()` solo aplica descuentos y
         * recargos de VENTA a renglones marcados `is_article` (o `is_service` con su propio
         * flag). En `NewSalePdf` esa marca la pone `get_sale_items()` antes de llamar a
         * `resolve_value()`; acá se lo llama directo, así que hay que replicarla a mano — si no,
         * el recargo de este test no se aplicaría a ningún renglón.
         */
        $item->is_article = true;

        return ['sale' => $sale, 'item' => $item];
    }

    /**
     * Arma el `$afip_helper` de contexto tal como lo arma `NewSalePdf` para un comprobante
     * fiscal (vía `TicketInfoHelper::afip_helper()`), pero construyendo el `AfipHelper` directo:
     * nada en el camino bajo prueba vuelve a leer el `AfipTicket` de la base, así que alcanza
     * con una instancia en memoria, sin persistir.
     *
     * @param  \App\Models\Sale  $sale
     * @param  \App\Models\User  $user
     * @return AfipHelper
     */
    protected function armar_afip_helper($sale, $user)
    {
        $afip_ticket = new AfipTicket([
            'cbte_letra' => 'A',
            'cbte_tipo'  => 1,
        ]);

        return new AfipHelper($afip_ticket, $sale->articles, $sale->services, $user, $sale);
    }

    /**
     * Contexto para `PdfColumnService::resolve_value()`, con las mismas claves que arma
     * `NewSalePdf::get_profile_column_value()`.
     *
     * @param  \App\Models\Sale  $sale
     * @param  mixed  $item
     * @param  AfipHelper|null  $afip_helper
     * @return array
     */
    protected function armar_contexto($sale, $item, $afip_helper)
    {
        return [
            'item'        => $item,
            'index'       => 1,
            'sale'        => $sale,
            'afip_ticket' => $afip_helper ? $afip_helper->afip_ticket : null,
            'afip_helper' => $afip_helper,
            'numbers'     => Numbers::class,
        ];
    }

    /**
     * Convierte a float el string que formatea `Numbers::price()` ("$2.479,35" o "$3.000").
     * `number_format($price, 2, ',', '.')` usa '.' de miles y ',' de decimales: sacar el '$' y
     * los '.', después cambiar ',' por '.'.
     *
     * @param  string  $formateado
     * @return float
     */
    protected function a_numero($formateado)
    {
        $limpio = str_replace('$', '', (string) $formateado);
        $limpio = str_replace('.', '', $limpio);
        $limpio = str_replace(',', '.', $limpio);

        return (float) $limpio;
    }

    /**
     * Test 1 - con un recargo de venta del 10%, "Precio sin IVA" sube respecto del neto que
     * daría el mismo renglón SIN el recargo (el que persiste el snapshot de pivot, calculado
     * solo a partir del precio unitario), y "Subtotal sin IVA" + "Importe IVA" cierra contra
     * "Total con IVA" de la misma línea — el invariante que hoy, antes del fix, da falso.
     *
     * Números de este test (precio $1.000, cantidad 3, IVA 21%, recargo 10%): precio unitario
     * con recargo $1.100, neto $909,09, subtotal neto $2.727,27, IVA $572,73, total con IVA
     * $3.300 — cierra exacto.
     *
     * @group facturacion
     * @test
     */
    public function precio_sin_iva_refleja_el_recargo_de_la_venta()
    {
        $armado = $this->armar_venta_con_renglon(1000.00, 3);
        $sale = $armado['sale'];
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $recargo = Surchage::create([
            'name'       => 'Recargo fixture PDF neto',
            'percentage' => 10,
            'user_id'    => $user->id,
        ]);
        $sale->surchages()->attach($recargo->id, ['percentage' => 10]);

        $releido = $this->releer_primer_renglon($sale);
        $sale = $releido['sale'];
        $item = $releido['item'];

        $afip_helper = $this->armar_afip_helper($sale, $user);
        $contexto = $this->armar_contexto($sale, $item, $afip_helper);

        $precio_sin_iva_con_recargo = $this->a_numero(
            PdfColumnService::resolve_value('item_price_without_iva', $contexto)
        );
        $subtotal_sin_iva = $this->a_numero(
            PdfColumnService::resolve_value('item_subtotal_without_iva', $contexto)
        );
        $importe_iva = $this->a_numero(
            PdfColumnService::resolve_value('item_iva_amount', $contexto)
        );
        $total_con_iva = $this->a_numero(
            PdfColumnService::resolve_value('item_subtotal_with_iva', $contexto)
        );

        /**
         * El valor "sin el recargo" es exactamente el snapshot de pivot: pedirle a
         * resolve_value() el mismo resolver SIN afip_helper fuerza el camino de fallback, que es
         * justo el snapshot que `SaleHelper::attachArticle()` calculó ignorando
         * `sale->surchages` — así se compara sin duplicar la fórmula a mano.
         */
        $contexto_sin_afip_helper = $this->armar_contexto($sale, $item, null);
        $precio_sin_iva_sin_recargo = $this->a_numero(
            PdfColumnService::resolve_value('item_price_without_iva', $contexto_sin_afip_helper)
        );

        $this->assertGreaterThan(
            $precio_sin_iva_sin_recargo,
            $precio_sin_iva_con_recargo,
            '"Precio sin IVA" tiene que subir respecto del neto sin el recargo de la venta'
        );

        $this->assertEqualsWithDelta(
            $total_con_iva,
            $subtotal_sin_iva + $importe_iva,
            self::DELTA,
            '"Subtotal sin IVA" + "Importe IVA" tiene que cerrar contra "Total con IVA" de la misma línea'
        );
    }

    /**
     * Test 2 - red de seguridad: SIN recargo ni descuento de venta, "Precio sin IVA" da
     * exactamente lo mismo que daba antes del fix (el snapshot de pivot) — el fix no puede
     * cambiar nada en el renglón unitario cuando no hay ningún ajuste de venta.
     *
     * "Subtotal sin IVA" se verifica con la MISMA tolerancia de un centavo que ya usa el test 1,
     * no con igualdad exacta: ver el hallazgo colateral documentado en el encabezado de este
     * archivo (una diferencia de redondeo preexistente entre el snapshot, que redondea el precio
     * unitario ANTES de multiplicar por cantidad, y `AfipItemCalculator`, que redondea DESPUÉS —
     * independiente del recargo, y ya presente hoy).
     *
     * @group facturacion
     * @test
     */
    public function sin_recargo_ni_descuento_precio_sin_iva_no_cambia()
    {
        $armado = $this->armar_venta_con_renglon(1000.00, 3);
        $sale = $armado['sale'];
        $item = $armado['item'];
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $afip_helper = $this->armar_afip_helper($sale, $user);
        $contexto_nuevo = $this->armar_contexto($sale, $item, $afip_helper);
        $contexto_snapshot = $this->armar_contexto($sale, $item, null);

        $this->assertSame(
            PdfColumnService::resolve_value('item_price_without_iva', $contexto_snapshot),
            PdfColumnService::resolve_value('item_price_without_iva', $contexto_nuevo),
            'sin recargo ni descuento, "Precio sin IVA" tiene que dar exactamente lo mismo que el snapshot de pivot'
        );

        $subtotal_nuevo = $this->a_numero(
            PdfColumnService::resolve_value('item_subtotal_without_iva', $contexto_nuevo)
        );
        $subtotal_snapshot = $this->a_numero(
            PdfColumnService::resolve_value('item_subtotal_without_iva', $contexto_snapshot)
        );

        $this->assertEqualsWithDelta(
            $subtotal_snapshot,
            $subtotal_nuevo,
            self::DELTA,
            'sin recargo ni descuento, "Subtotal sin IVA" tiene que seguir dando prácticamente lo mismo que el snapshot (ver hallazgo de redondeo en el encabezado del archivo)'
        );

        /**
         * Bonus: el hallazgo colateral en positivo. Post-fix, "Subtotal sin IVA" + "Importe IVA"
         * cierra EXACTO contra "Total con IVA" incluso sin recargo — hoy (pre-fix) queda $0,01
         * arriba (ver números en el encabezado). No es el bug de esta misión, pero confirma que
         * el reordenamiento no rompe nada y de paso mejora la reconciliación.
         */
        $importe_iva = $this->a_numero(
            PdfColumnService::resolve_value('item_iva_amount', $contexto_nuevo)
        );
        $total_con_iva = $this->a_numero(
            PdfColumnService::resolve_value('item_subtotal_with_iva', $contexto_nuevo)
        );

        $this->assertEqualsWithDelta(
            $total_con_iva,
            $subtotal_nuevo + $importe_iva,
            self::DELTA,
            'incluso sin recargo, "Subtotal sin IVA" + "Importe IVA" tiene que cerrar contra "Total con IVA"'
        );
    }

    /**
     * Test 3 - guarda de moneda extranjera (hallazgo de un chequeo adversarial, 8/9/2026): en una
     * venta en USD, `AfipItemCalculator::get_article_price_raw()` ya convierte a pesos por
     * dentro (multiplica por `sale->valor_dolar`), y `format_sale_monetary_value()` de
     * `PdfColumnService` vuelve a convertir para mostrar — doble conversión, ~1000x el valor
     * correcto. Ese bug de fondo YA estaba en "Total con IVA"/"Importe IVA" (siempre pasaron por
     * `$afip_helper`, ANTES de esta misión) y no se arregla acá — arreglarlo de raíz toca código
     * compartido por columnas ajenas a este pedido (ver el informe de la misión). Lo que este test
     * fija es que, en USD, "Precio sin IVA" sigue dando el mismo número (correctamente escalado,
     * aunque sin el recargo) que daba ANTES de esta misión — el fix no puede empeorar la venta en
     * moneda extranjera respecto de cómo estaba.
     *
     * @group facturacion
     * @test
     */
    public function en_moneda_extranjera_precio_sin_iva_no_duplica_la_conversion()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->first();
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $price_usd = 10.00;
        $valor_dolar = 1000;

        $sale = Sale::create([
            'user_id'                          => $user->id,
            'client_id'                        => $client->id,
            'omitir_en_cuenta_corriente'       => 0,
            'save_current_acount'              => 0,
            'terminada'                        => 1,
            'is_cerrada'                       => 0,
            'sub_total'                        => $price_usd,
            'total'                            => $price_usd,
            'moneda_id'                        => 2,
            'valor_dolar'                      => $valor_dolar,
            'descuento'                        => 0,
            'aplicar_recargos_directo_a_items' => 0,
        ]);

        $price_sin_iva = SaleHelper::get_price_sin_iva(['id' => $articulo->id], $price_usd);

        $sale->articles()->attach($articulo->id, [
            'amount'         => 1,
            'price'          => $price_usd,
            'price_sin_iva'  => $price_sin_iva,
            'iva_percentage' => $articulo->iva->percentage,
        ]);

        $releido = $this->releer_primer_renglon($sale);
        $sale = $releido['sale'];
        $item = $releido['item'];

        $afip_helper = $this->armar_afip_helper($sale, $user);
        $contexto = $this->armar_contexto($sale, $item, $afip_helper);

        /**
         * El valor correcto, en pesos, para un artículo de 10 USD neteado y convertido a 1000: el
         * mismo cálculo que hace el snapshot de pivot (que es exactamente lo que este resolver
         * usaba ANTES de esta misión completa, para CUALQUIER moneda) — se lo pide sin
         * afip_helper para no duplicar la fórmula a mano.
         */
        $contexto_sin_afip_helper = $this->armar_contexto($sale, $item, null);
        $esperado = PdfColumnService::resolve_value('item_price_without_iva', $contexto_sin_afip_helper);
        $obtenido = PdfColumnService::resolve_value('item_price_without_iva', $contexto);

        $this->assertSame(
            $esperado,
            $obtenido,
            '"Precio sin IVA" en una venta en USD tiene que seguir dando el mismo valor que daba antes de esta misión (sin doble conversión)'
        );

        /**
         * Red de seguridad numérica explícita: si algún día se cambia el orden de nuevo y
         * reaparece la doble conversión, el valor se dispara a ~$8.260.000 en vez de ~$8.260 —
         * un assert de rango, no de igualdad exacta, para no atarse al centavo del redondeo.
         */
        $numero = $this->a_numero($obtenido);
        $this->assertLessThan(
            50000,
            $numero,
            '"Precio sin IVA" en USD no puede estar en el orden de los millones — señal de doble conversión de moneda'
        );
    }
}
