<?php

namespace Database\Seeders\testing;

use App\Models\Address;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserConfiguration;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Cuenta de testing CON listas de precios, para explorar la importacion de Excel
 * (exploracion del 2/9/2026, slot s8).
 *
 * Por que una cuenta aparte y no la de la ferreteria: la cuenta e2e principal NO usa listas de
 * precios y toda su suite (specs de listado/vender/compras) asume precio unico. Prenderle
 * `listas_de_precio` le cambiaria el caracter al fixture compartido para siempre: cada recalculo
 * de un articulo pasaria a escribir pivots en `article_price_type` (updateExistingPivot corre en
 * cada `setFinalPrice`). Esta cuenta es un tenant aparte en la misma base del slot; los specs de
 * exploracion de importacion se loguean con ella y no tocan a la ferreteria.
 *
 * Que arma (todo idempotente, se puede correr N veces):
 *  - Usuario 'Exploracion Listas' (doc 5678 / clave 1234), RRII migrada, `listas_de_precio = 1`.
 *  - SIN impuesto IIBB a proposito: los precios esperados de los specs se calculan a mano y
 *    tienen que salir redondos (final de lista = costo x (1+margen%) x 1.21).
 *  - Extensiones de listas: `articulo_margen_de_ganancia_segun_lista_de_precios` (sin ella la SPA
 *    no muestra el grupo de columnas de listas en el modal de importacion con IA, ver
 *    ai-excel-import/Index.vue ~2368) y `setear_precio_final_en_listas_de_precio`.
 *  - Listas: Mayorista 20% (se incluye en el excel para clientes) y Minorista 40%, las dos con
 *    `setear_precio_final = 0` de default (el caso "precio fijado" lo arma el propio Excel de la
 *    exploracion, por articulo).
 *  - Sucursales 'Sucursal Centro' y 'Sucursal Norte': la importacion deriva el nombre de columna
 *    del street (`sucursal_centro` / `sucursal_norte`, ver ProcessRow::obtener_stock_addresses).
 *  - Proveedores 'Proveedor Alfa' y 'Proveedor Beta'.
 *  - Categoria 'Herramientas' pre-existente (el caso "categoria ya creada"); 'Pintureria' NO se
 *    siembra a proposito: la tiene que crear la importacion.
 */
class TestingImportListasSeeder extends Seeder
{
    const USER_EMAIL = 'exploracion.listas@testing.local';
    const USER_DOC   = '5678';

    public function run()
    {
        $user = $this->seed_usuario();
        $this->seed_extenciones($user);
        $this->seed_listas($user);
        $this->seed_sucursales($user);
        $this->seed_proveedores($user);
        $this->seed_categoria($user);
        $this->seed_articulos_heredados_con_bar_code_duplicado($user);
    }

    /**
     * @return User
     */
    protected function seed_usuario()
    {
        $user = User::where('email', self::USER_EMAIL)->first();

        if (is_null($user)) {

            /*
             * default_version/api_url se copian del usuario de la ferreteria si existe: es lo que
             * setup-slot.ps1 ya dejo apuntando a los puertos DE ESTE slot. Sin esto, el mixin
             * check_version.js redirige el navegador al entorno de la carpeta fija en el primer
             * login (misma trampa que documenta e2e/README.md).
             */
            $referencia = User::whereNull('owner_id')->orderBy('id')->first();

            $user = User::create([
                'name'                => 'Exploracion Listas',
                'company_name'        => 'Distribuidora Exploracion',
                'email'               => self::USER_EMAIL,
                'doc_number'          => self::USER_DOC,
                'password'            => bcrypt('1234'),
                'phone'               => '0000000000',
                'dollar'              => 1000,
                'iva_included'        => 0,
                'payment_expired_at'  => Carbon::now()->addYears(5),
                'api_url'             => !is_null($referencia) ? $referencia->api_url : null,
                'default_version'     => !is_null($referencia) ? $referencia->default_version : null,
                'info_afip_del_primer_punto_de_venta' => 0,
            ]);
        }

        /*
         * Los flags que esta exploracion necesita, aplicados SIEMPRE (tambien sobre una cuenta ya
         * creada por una corrida anterior): RRII migrada, listas prendidas, y el lock de sesion
         * unica liberado (activity_minutes = 0, mismo motivo que documenta e2e/setup-slot.ps1:
         * cada corrida loguea con un session_id nuevo).
         */
        $user->condicion_iva_precios = User::CONDICION_RRII;
        $user->usar_condicion_fiscal_en_costeo = 1;
        $user->listas_de_precio = 1;
        $user->activity_minutes = 0;
        $user->session_id = null;
        $user->last_activity = null;
        $user->save();

        if (!UserConfiguration::where('user_id', $user->id)->exists()) {
            UserConfiguration::create([
                'current_acount_pagado_details'    => 'Saldado',
                'current_acount_pagandose_details' => 'Recibo de pago',
                'iva_included'                     => 1,
                'can_make_afip_tickets'            => 0,
                'user_id'                          => $user->id,
            ]);
        }

        return $user;
    }

    protected function seed_extenciones(User $user)
    {
        $slugs = [
            'articulo_margen_de_ganancia_segun_lista_de_precios',
            'setear_precio_final_en_listas_de_precio',
        ];

        foreach ($slugs as $slug) {
            $extencion = ExtencionEmpresa::where('slug', $slug)->first();

            if (!is_null($extencion)) {
                $user->extencions()->syncWithoutDetaching($extencion->id);
            }
        }
    }

    protected function seed_listas(User $user)
    {
        PriceType::firstOrCreate(
            ['name' => 'Mayorista', 'user_id' => $user->id],
            [
                'num'                                  => 1,
                'position'                             => 1,
                'percentage'                           => 20,
                'setear_precio_final'                  => 0,
                'incluir_en_lista_de_precios_de_excel' => 1,
            ]
        );

        PriceType::firstOrCreate(
            ['name' => 'Minorista', 'user_id' => $user->id],
            [
                'num'                 => 2,
                'position'            => 2,
                'percentage'          => 40,
                'setear_precio_final' => 0,
            ]
        );
    }

    protected function seed_sucursales(User $user)
    {
        Address::firstOrCreate(['street' => 'Sucursal Centro', 'user_id' => $user->id]);
        Address::firstOrCreate(['street' => 'Sucursal Norte', 'user_id' => $user->id]);
    }

    protected function seed_proveedores(User $user)
    {
        Provider::firstOrCreate(
            ['name' => 'Proveedor Alfa', 'user_id' => $user->id],
            ['num' => 1]
        );

        Provider::firstOrCreate(
            ['name' => 'Proveedor Beta', 'user_id' => $user->id],
            ['num' => 2]
        );
    }

    protected function seed_categoria(User $user)
    {
        Category::firstOrCreate(
            ['name' => 'Herramientas', 'user_id' => $user->id],
            ['num' => 1]
        );
    }

    /**
     * Dos articulos con el MISMO bar_code (30000001): data sucia deliberada, como la que queda en
     * una cuenta migrada de otro sistema (y como A7/A8 del tenant 900 de tests/Import). Una fila
     * de Excel con ese codigo tiene que resolver a AmbiguousMatch y saltearse: es el escenario
     * "codigo de barras repetido EN BASE" que la exploracion afirma
     * (exploracion-importacion-codigos.spec.js). No se crean por la API a proposito: ningun
     * camino sano del producto deja dos articulos con el mismo bar_code, y ese es el punto.
     */
    protected function seed_articulos_heredados_con_bar_code_duplicado(User $user)
    {
        $heredados = [
            ['name' => 'Heredado Duplicado Uno', 'cost' => 111],
            ['name' => 'Heredado Duplicado Dos', 'cost' => 222],
        ];

        foreach ($heredados as $i => $heredado) {
            \App\Models\Article::firstOrCreate(
                ['name' => $heredado['name'], 'user_id' => $user->id],
                [
                    'num'      => 9000 + $i,
                    'bar_code' => '30000001',
                    'cost'     => $heredado['cost'],
                    'status'   => 'active',
                ]
            );
        }
    }
}
