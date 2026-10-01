<?php

namespace Tests\Feature\IvaEnArticulosSinIva;

use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Base comun de la suite de `iva_en_articulos_sin_iva` (mision iva-a-articulos-sin-iva-en-vender,
 * 1/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUE ES EL FLAG, EN UNA LINEA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  En Vender, al lado de "Precios con IVA" (`iva_aplicado`), un check nuevo: "Sumar IVA a los
 *  articulos sin IVA". Prendido, a los articulos con `aplicar_iva` apagado la SPA les suma el IVA de
 *  su alicuota. La API no calcula nada con el: lo GUARDA en `sales` / `budgets`, lo PRESERVA en un
 *  update que no lo manda (SPA vieja), lo PROPAGA al confirmar, duplicar y consolidar, y lo DEVUELVE
 *  en el modelo para que la SPA sepa de que estado parten los precios guardados.
 *
 *  Por eso esta suite mide solo la columna, leida de la BASE despues de pegarle al endpoint real.
 *
 * ⚠️ `SaleController::store()` tiene una guarda anti-duplicados (mismo comercio, mismo cliente,
 * mismo total en 5 segundos → 200 vacio en vez de 201). Ningun test de esta suite crea dos ventas
 * por el endpoint; si alguno lo hiciera, tienen que tener totales distintos.
 *
 * DatabaseTransactions (lo hereda de `EmpresaTestCase`) sobre la base sembrada del slot.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
abstract class IvaEnArticulosSinIvaTestCase extends EmpresaTestCase
{
    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** Precio del unico renglon de cada comprobante. */
    const PRECIO = 1210.00;

    /** Slug de la extension que gatea `BudgetController::duplicate()`. */
    const EXTENCION_DUPLICAR = 'duplicar_presupuestos';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_presupuesto();
    }

    /**
     * Los dos estados de presupuesto que usan alta, update y confirmar. Mismo cuidado que
     * Presupuestos/1, /5 y /7: la tabla puede venir vacia en la base del slot.
     *
     * @return void
     */
    protected function sembrar_estados_de_presupuesto()
    {
        $estados = [
            self::ESTADO_SIN_CONFIRMAR => 'Sin confirmar',
            self::ESTADO_CONFIRMADO    => 'Confirmado',
        ];

        foreach ($estados as $id => $name) {

            if (is_null(BudgetStatus::find($id))) {
                $estado = new BudgetStatus();
                $estado->id = $id;
                $estado->name = $name;
                $estado->save();
            }
        }
    }

    /**
     * El comercio del fixture.
     *
     * @return \App\Models\User
     */
    protected function comercio()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return $user;
    }

    /**
     * El cliente de cuenta corriente del fixture (tiene sus cuentas creadas, asi que confirmar un
     * presupuesto suyo no se cae buscando la cuenta corriente).
     *
     * @return \App\Models\Client
     */
    protected function cliente()
    {
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();

        $this->assertNotNull($client, 'Falta el cliente de cuenta corriente del fixture.');

        return $client;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Ventas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Payload de POST api/sale con un renglon del articulo centinela (molde de ForzarTotal). SIN
     * `iva_en_articulos_sin_iva`: cada test decide si lo manda.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($overrides = [])
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        return array_merge([
            'client_id'                  => null,
            'address_id'                 => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 1,
            'to_check'                   => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'employee_id'                => null,
            'sub_total'                  => self::PRECIO,
            'total'                      => self::PRECIO,
            'terminada'                  => 1,
            'seller_id'                  => null,
            'cantidad_cuotas'            => null,
            'cuota_descuento'            => 0,
            'cuota_recargo'              => 0,
            'caja_id'                    => null,
            'afip_tipo_comprobante_id'   => null,
            'descuento'                  => null,
            'moneda_id'                  => 1,
            'iva_aplicado'               => 1,
            'discounts'                  => [],
            'surchages'                  => [],
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => self::PRECIO,
                    'amount'       => 1,
                ],
            ],
        ], $overrides);
    }

    /**
     * Crea una venta por el endpoint real y devuelve el cuerpo de la respuesta (el `model` que
     * viaja de vuelta a la SPA).
     *
     * @param  array  $payload
     * @return array
     */
    protected function crear_venta_por_endpoint($payload)
    {
        $response = $this->postJson('api/sale', $payload);

        $response->assertStatus(201);

        $modelo = $response->json('model');

        $this->assertNotNull($modelo, 'POST api/sale no devolvio la venta creada.');

        return $modelo;
    }

    /**
     * Venta guardada directo en la base (lo que se mide en estos casos es el update).
     *
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function venta_en_base($overrides = [])
    {
        return Sale::create(array_merge([
            'user_id'                    => $this->comercio()->id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'discount_stock'             => 0,
            'iva_aplicado'               => 1,
            'moneda_id'                  => 1,
            'descuento'                  => 0,
            'sub_total'                  => self::PRECIO,
            'total'                      => self::PRECIO,
        ], $overrides));
    }

    /**
     * Payload minimo de PUT api/sale/{id} (molde de ForzarTotal y Sales/31). SIN
     * `iva_en_articulos_sin_iva`: es el PUT de la SPA anterior a esta mision.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array             $overrides
     * @return array
     */
    protected function payload_actualizar_venta($sale, $overrides = [])
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        return array_merge([
            'client_id'                  => $sale->client_id,
            'save_current_acount'        => $sale->save_current_acount,
            'omitir_en_cuenta_corriente' => $sale->omitir_en_cuenta_corriente,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'sub_total'                  => self::PRECIO,
            'total'                      => self::PRECIO,
            'moneda_id'                  => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => self::PRECIO,
                    'amount'       => 1,
                ],
            ],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Presupuestos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Payload de POST api/budget con un renglon del articulo centinela (molde de ForzarTotal/9).
     * SIN `iva_en_articulos_sin_iva`.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_presupuesto($overrides = [])
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        return array_merge([
            'client_id'                        => $this->cliente()->id,
            'start_at'                         => null,
            'finish_at'                        => null,
            'observations'                     => null,
            'price_type_id'                    => null,
            'sale_status_id'                   => null,
            'discount_stock'                   => 0,
            'iva_aplicado'                     => 1,
            'total'                            => self::PRECIO,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'address_id'                       => null,
            'surchages_in_services'            => 1,
            'discounts_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => 0,
            'moneda_id'                        => 1,
            'valor_dolar'                      => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'services'                         => [],
            'promocion_vinotecas'              => [],
            'combos'                           => [],
            'articles'                         => [[
                'id'     => $articulo->id,
                'status' => 'active',
                'pivot'  => [
                    'amount'   => 1,
                    'bonus'    => null,
                    'location' => null,
                    'price'    => self::PRECIO,
                ],
            ]],
        ], $overrides);
    }

    /**
     * Crea un presupuesto por el endpoint real y devuelve el cuerpo de la respuesta.
     *
     * @param  array  $payload
     * @return array
     */
    protected function crear_presupuesto_por_endpoint($payload)
    {
        $response = $this->postJson('api/budget', $payload);

        $response->assertStatus(201);

        $modelo = $response->json('model');

        $this->assertNotNull($modelo, 'POST api/budget no devolvio el presupuesto creado.');

        return $modelo;
    }

    /**
     * Presupuesto guardado directo en la base, sin renglones (lo que se mide es el update, el
     * duplicado o la confirmacion, no el alta).
     *
     * @param  array  $overrides
     * @return \App\Models\Budget
     */
    protected function presupuesto_en_base($overrides = [])
    {
        return Budget::create(array_merge([
            'num'                              => 9800,
            'user_id'                          => $this->comercio()->id,
            'client_id'                        => $this->cliente()->id,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'total'                            => 0,
            'discount_stock'                   => 0,
            'iva_aplicado'                     => 1,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => 0,
        ], $overrides));
    }

    /**
     * Payload de PUT api/budget/{id} sin renglones (molde de ForzarTotal/9, test 4). SIN
     * `iva_en_articulos_sin_iva`.
     *
     * @param  \App\Models\Budget  $budget
     * @param  array               $overrides
     * @return array
     */
    protected function payload_actualizar_presupuesto($budget, $overrides = [])
    {
        return array_merge([
            'client_id'             => $budget->client_id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => 'actualizado por el test',
            'total'                 => $budget->total,
            'budget_status_id'      => $budget->budget_status_id,
            'address_id'            => null,
            'surchages_in_services' => 1,
            'discounts_in_services' => 1,
            'moneda_id'             => 1,
            'sale_status_id'        => null,
            'discount_stock'        => 0,
            'iva_aplicado'          => 1,
            'articles'              => [],
            'services'              => [],
            'promocion_vinotecas'   => [],
            'combos'                => [],
            'discounts'             => [],
            'surchages'             => [],
        ], $overrides);
    }

    /**
     * Le da al comercio la extension `duplicar_presupuestos`. `extencion_empresas` puede venir
     * vacia en la base del slot, asi que la fila del catalogo se crea si falta (forceCreate: el
     * modelo no declara $fillable). DatabaseTransactions revierte todo.
     *
     * @return void
     */
    protected function dar_extencion_duplicar()
    {
        $extencion = ExtencionEmpresa::where('slug', self::EXTENCION_DUPLICAR)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::EXTENCION_DUPLICAR,
                'name' => 'Duplicar presupuestos',
            ]);
        }

        $user = $this->comercio();
        $user->extencions()->syncWithoutDetaching([$extencion->id]);
        $user->load('extencions');
    }
}
