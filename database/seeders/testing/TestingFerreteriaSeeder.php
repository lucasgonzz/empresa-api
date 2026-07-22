<?php

namespace Database\Seeders\testing;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Seeders\ArticleSeederHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\Iva;
use App\Models\Provider;
use App\Models\ProviderDiscount;
use Database\Seeders\CAPaymentMethodTypeSeeder;
use Database\Seeders\ConceptoStockMovementSeeder;
use Database\Seeders\CurrentAcountPaymentMethodSeeder;
use Database\Seeders\DepositSeeder;
use Database\Seeders\IvaConditionSeeder;
use Database\Seeders\IvaSeeder;
use Database\Seeders\PriceTypeSeeder;
use Database\Seeders\ProviderOrderStatusSeeder;
use Database\Seeders\ProviderSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Seeder;

/**
 * Seeder determinista de testing (Grupo 184, Prompt 613).
 *
 * Deja la base de testing en un estado EXACTAMENTE reproducible para automatizar la verificacion
 * numerica del modulo de compras (hoy hecha a mano por Lucas con calculadora). Pensado para correr
 * despues de `migrate:fresh`, sobre la base de testing (nunca la de desarrollo, ver
 * `tests/Feature/Compras/README.md` y el seguro de `ComprasTestCase`).
 *
 * Los tests SIEMPRE resuelven proveedores/articulos/deposito por las constantes publicas de esta
 * clase (nunca por ID hardcodeado): el nombre es el contrato estable entre este seeder y los tests
 * de los prompts 614 a 617.
 */
class TestingFerreteriaSeeder extends Seeder
{
    /**
     * Proveedor con bonificaciones (10% y 5%), origen de 5 de los 10 articulos del fixture.
     */
    const PROVIDER_BSAS = 'Buenos Aires';

    /**
     * Proveedor sin bonificaciones, origen de los otros 5 articulos del fixture.
     */
    const PROVIDER_OTRO = 'Rosario';

    /**
     * Deposito (columna `address_id` de provider_orders, "Deposito" en el form de compras del SPA)
     * usado como destino de stock por defecto en `payload_compra()`.
     */
    const DEPOSITO = 'Principal';

    /**
     * Email del usuario de prueba: mismo valor hardcodeado que usa `UserSeeder` para el usuario
     * base, sin importar `FOR_USER`/`USER_ID` del .env activo. Se resuelve por email (no por id)
     * para no depender de que `.env.testing` fije `USER_ID`.
     */
    const USER_EMAIL = 'lucasgonzalez5500@gmail.com';

    /**
     * Articulo centinela: si no existe, el fixture no fue sembrado (o quedo a medio sembrar).
     * Lo usa `ComprasTestCase::verificar_fixture_sembrado()`.
     */
    const ARTICULO_CENTINELA = 'Martillo acero';

    /**
     * Ejecuta el seeder completo.
     *
     * Idempotencia (tarea 2f): si el fixture ya esta completo (los 10 articulos existen), no hace
     * nada. Evita duplicar articulos/bonificaciones si el seeder se corre dos veces seguidas sin
     * un `migrate:fresh` en el medio. Los seeders base que se llaman abajo (UserSeeder, IvaSeeder,
     * etc.) no son idempotentes por si solos (usan `create()` sin `firstOrCreate`), por eso todo el
     * fixture se trata como una unidad: se siembra completo una sola vez.
     *
     * @return void
     */
    public function run()
    {
        if ($this->fixture_completo()) {
            return;
        }

        $this->seed_base_data();

        $providers = $this->seed_providers();

        $this->seed_bonificaciones($providers[self::PROVIDER_BSAS]);

        $deposito = $this->seed_deposito();

        $this->seed_articulos($providers, $deposito);
    }

    /**
     * Chequea si los 10 articulos del catalogo del fixture ya existen (fixture completo).
     *
     * @return bool
     */
    protected function fixture_completo()
    {
        $nombres = array_column($this->catalogo(), 'name');

        return Article::whereIn('name', $nombres)->count() === count($nombres);
    }

    /**
     * Datos base minimos para una cuenta funcional (tarea 2a): usuario, IVAs, condiciones de IVA,
     * estados de orden de compra, tipo de metodo de pago de cuenta corriente + metodos de pago de
     * cuenta corriente, tipos de precio y depositos (modelo `Deposit`, feature de acopios, sin
     * relacion con el "Deposito Principal" de la tarea 2d). Se reusan los seeders existentes tal
     * cual (no se duplica su contenido).
     *
     * `ConceptoStockMovementSeeder` no esta en la lista minima textual del prompt, pero es una
     * dependencia dura: sin los conceptos ("Creacion de deposito", "Compra a proveedor", etc.) que
     * usa `StockMovementController::crear()`, cualquier movimiento de stock (el que arma el stock
     * inicial de cada articulo del fixture, y el que van a generar los provider_order de los tests
     * 615/616) revienta con "Trying to get property 'name' of non-object" al resolver
     * `stock_movement->concepto_movement`. Se agrega para que el fixture sea sembrable end-to-end.
     *
     * @return void
     */
    protected function seed_base_data()
    {
        $this->call(CAPaymentMethodTypeSeeder::class);
        $this->call(CurrentAcountPaymentMethodSeeder::class);
        $this->call(IvaSeeder::class);
        $this->call(IvaConditionSeeder::class);
        $this->call(ProviderOrderStatusSeeder::class);
        $this->call(ConceptoStockMovementSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(PriceTypeSeeder::class);
        $this->call(DepositSeeder::class);
    }

    /**
     * Crea los proveedores del fixture reusando `ProviderSeeder` (ya crea "Buenos Aires" y
     * "Rosario" con esos nombres exactos) y los resuelve por nombre para no depender del orden de
     * insercion ni de `config('app.USER_ID')`.
     *
     * @return array<string,\App\Models\Provider> Provider indexado por PROVIDER_BSAS/PROVIDER_OTRO.
     */
    protected function seed_providers()
    {
        $this->call(ProviderSeeder::class);

        return [
            self::PROVIDER_BSAS => Provider::where('name', self::PROVIDER_BSAS)->first(),
            self::PROVIDER_OTRO => Provider::where('name', self::PROVIDER_OTRO)->first(),
        ];
    }

    /**
     * Bonificaciones (`provider_discounts`) del proveedor Buenos Aires: 10% y 5%, en ese orden
     * (tarea 2c). Rosario no lleva ninguna. Se crean a mano (no via `ProviderDiscountSeeder`) para
     * no depender de que ese seeder asuma `provider_id = 1`.
     *
     * @param \App\Models\Provider $proveedor_bsas
     * @return void
     */
    protected function seed_bonificaciones($proveedor_bsas)
    {
        ProviderDiscount::create([
            'percentage'  => 10,
            'provider_id' => $proveedor_bsas->id,
        ]);

        ProviderDiscount::create([
            'percentage'  => 5,
            'provider_id' => $proveedor_bsas->id,
        ]);
    }

    /**
     * Crea el deposito "Principal" (tarea 2d): un `Address` (asi lo modela el sistema: el campo
     * "Deposito" del form de compras del SPA es `provider_order.address_id`, ver
     * `empresa-spa/src/models/provider_order.js`), no el modelo `Deposit` (feature de acopios,
     * sin relacion).
     *
     * @return \App\Models\Address
     */
    protected function seed_deposito()
    {
        return Address::create([
            'street'  => self::DEPOSITO,
            'user_id' => config('app.USER_ID'),
        ]);
    }

    /**
     * Crea los 10 articulos del catalogo (tarea 2e), con proveedor y stock deterministicos
     * (a diferencia de `FerreteriaArticlesSeeder`, que reparte proveedores en forma rotativa y no
     * es reproducible). Usa el helper estandar del proyecto para que el fixture pase por la misma
     * logica que un alta real de articulo.
     *
     * @param array<string,\App\Models\Provider> $providers Providers indexados por nombre (ver seed_providers).
     * @param \App\Models\Address $deposito Deposito "Principal" (ver seed_deposito), destino del stock inicial.
     * @return void
     */
    protected function seed_articulos($providers, $deposito)
    {
        /** Helper estandar de creacion de articulos (mismo que usa FerreteriaArticlesSeeder). */
        $article_helper = new ArticleSeederHelper();

        foreach ($this->catalogo() as $item) {

            /** Proveedor asignado en forma fija segun la tabla de la especificacion. */
            $provider = $providers[$item['provider']];

            /**
             * Iva resuelto por porcentaje (nunca por id hardcodeado): `Exento`/`No Gravado` se
             * guardan como texto en `ivas.percentage` (ver IvaSeeder), por eso la busqueda es
             * siempre por el valor de percentage, sea numerico o texto.
             */
            $iva_id = $this->resolver_iva_id($item['iva_percentage']);

            /**
             * Payload del articulo: `stock` alimenta tanto el pivot de proveedor (cantidad de
             * referencia) como, via `addresses`, el movimiento de stock inicial determinista hacia
             * el deposito Principal (evita el `rand(10,100)` que usaria `setStockMovement()` si no
             * se le pasa una direccion explicita).
             */
            $article_payload = [
                'name'        => $item['name'],
                'cost'        => $item['cost'],
                'stock'       => $item['stock'],
                'provider_id' => $provider->id,
                'iva_id'      => $iva_id,
                'addresses'   => [
                    [
                        'id'     => $deposito->id,
                        'amount' => $item['stock'],
                    ],
                ],
            ];

            $created_article = $article_helper->crear_article($article_payload);

            /** Precios finales calculados con la logica central del sistema, igual que un alta real. */
            ArticleHelper::setFinalPrice($created_article, config('app.USER_ID'));
        }
    }

    /**
     * Resuelve el `iva_id` buscando por `percentage` (columna de tipo texto en `ivas`: valores
     * numericos como '21'/'10.5' conviven con literales 'Exento'/'No Gravado').
     *
     * @param string $percentage Valor tal cual se guarda en ivas.percentage.
     * @return int|null
     */
    protected function resolver_iva_id($percentage)
    {
        $iva = Iva::where('percentage', $percentage)->first();

        return is_null($iva) ? null : $iva->id;
    }

    /**
     * Catalogo fijo de los 10 articulos del fixture (tarea 2e). Nombres y costos vienen de
     * `database/seeders/articles/ferreteria.php` (no se inventan valores nuevos); la asignacion de
     * proveedor, el iva y el stock son los que pide la especificacion del prompt 613.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function catalogo()
    {
        return [
            ['name' => 'Martillo acero',    'provider' => self::PROVIDER_BSAS, 'cost' => 2000, 'iva_percentage' => '21',         'stock' => 10],
            ['name' => 'Pinza',             'provider' => self::PROVIDER_BSAS, 'cost' => 1000, 'iva_percentage' => '21',         'stock' => 10],
            ['name' => 'Alicate',           'provider' => self::PROVIDER_BSAS, 'cost' => 300,  'iva_percentage' => '21',         'stock' => 10],
            ['name' => 'Cuchilla',          'provider' => self::PROVIDER_BSAS, 'cost' => 500,  'iva_percentage' => '10.5',       'stock' => 10],
            ['name' => 'Cuchara',           'provider' => self::PROVIDER_BSAS, 'cost' => 100,  'iva_percentage' => 'Exento',     'stock' => 10],
            ['name' => 'Pata de cama',      'provider' => self::PROVIDER_OTRO, 'cost' => 50,   'iva_percentage' => '21',         'stock' => 10],
            ['name' => 'Marco para cama',   'provider' => self::PROVIDER_OTRO, 'cost' => 50,   'iva_percentage' => '21',         'stock' => 10],
            ['name' => 'Clavos N° 2',       'provider' => self::PROVIDER_OTRO, 'cost' => 50,   'iva_percentage' => '10.5',       'stock' => 10],
            ['name' => 'Pintura para cama', 'provider' => self::PROVIDER_OTRO, 'cost' => 50,   'iva_percentage' => 'No Gravado', 'stock' => 10],
            ['name' => 'Martillo',          'provider' => self::PROVIDER_OTRO, 'cost' => 1000, 'iva_percentage' => '21',         'stock' => 10],
        ];
    }
}
