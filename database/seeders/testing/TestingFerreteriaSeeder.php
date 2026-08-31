<?php

namespace Database\Seeders\testing;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Helpers\Seeders\ArticleSeederHelper;
use App\Models\Address;
use App\Models\AfipInformation;
use App\Models\Article;
use App\Models\Caja;
use App\Models\Client;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\CurrentAcountPaymentMethodDiscount;
use App\Models\ExpenseConcept;
use App\Models\ExtencionEmpresa;
use App\Models\Iva;
use App\Models\IvaCondition;
use App\Models\Moneda;
use App\Models\Provider;
use App\Models\ProviderDiscount;
use App\Models\SaleChannel;
use App\Models\SaleTax;
use App\Models\User;
use Database\Seeders\CAPaymentMethodTypeSeeder;
use Database\Seeders\ConceptoStockMovementSeeder;
use Database\Seeders\CurrentAcountPaymentMethodSeeder;
use Database\Seeders\DepositSeeder;
use Database\Seeders\ExtencionSeeder;
use Database\Seeders\IvaConditionSeeder;
use Database\Seeders\IvaSeeder;
use Database\Seeders\MonedaSeeder;
use Database\Seeders\PriceTypeSeeder;
use Database\Seeders\ProviderOrderStatusSeeder;
use Database\Seeders\ProviderSeeder;
use Database\Seeders\SaleChannelSeeder;
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
     * Cliente con cuenta corriente habilitada (Grupo 242 · Prompt 02). Lo usan los tests de
     * saldo anterior / saldo actual y el de reversion de cobros. No existe una columna dedicada
     * "cuenta corriente habilitada" en `clients`: el habilitado es un hecho de negocio (los tests
     * de tesoreria le crean sus propios `current_acounts`/`credit_accounts`), no un dato que este
     * fixture deba precargar.
     */
    const CLIENTE_CC = 'Cliente Cuenta Corriente';

    /**
     * Cliente de contado, sin cuenta corriente. Sirve de contraste con CLIENTE_CC.
     */
    const CLIENTE_CONTADO = 'Cliente Contado';

    /**
     * Cliente con condicion de IVA distinta a Responsable Inscripto (se le asigna "Exento"), para
     * los tests de facturacion por condicion del grupo 244.
     */
    const CLIENTE_EXENTO = 'Cliente Exento';

    /**
     * Metodo de pago de cuenta corriente "efectivo", tal cual lo crea `CurrentAcountPaymentMethodSeeder`.
     */
    const PAGO_EFECTIVO = 'Efectivo';

    /**
     * Metodo de pago de cuenta corriente de tarjeta de credito. `CurrentAcountPaymentMethodSeeder`
     * lo crea con el nombre "Credito" (no "Tarjeta de Credito"): se respeta el nombre real que deja
     * el seeder de produccion en vez de inventar uno nuevo.
     */
    const PAGO_TARJETA_CREDITO = 'Credito';

    /**
     * Concepto de gasto usado por las cajas comisionadas para el gasto automatico de comision.
     * Sin este concepto configurado en la caja (expense_concept_id null), el gasto no se genera
     * (ver Grupo 223 · Prompt 02).
     */
    const CONCEPTO_GASTO_COMISION = 'Comisiones bancarias';

    /**
     * Concepto de gasto operativo generico, para que el Estado de Resultados del grupo 245 tenga
     * algun gasto por fuera de las comisiones.
     */
    const CONCEPTO_GASTO_OPERATIVO = 'Alquiler';

    /**
     * Caja sin ninguna configuracion de liquidacion/comision (todas las columnas nuevas en null):
     * se comporta exactamente como una caja de siempre. Es la mas importante de las tres cajas de
     * este fixture porque prueba que los clientes en produccion (que nunca configuraron tesoreria)
     * no notan ningun cambio de comportamiento.
     */
    const CAJA_EFECTIVO = 'Caja Efectivo';

    /**
     * Caja con liquidacion diferida (14 dias) y comision de Mercado Pago (6.29% + IVA incluido),
     * con concepto de gasto de comision configurado.
     */
    const CAJA_MP = 'Caja Mercado Pago';

    /**
     * Caja con la misma configuracion de liquidacion/comision que CAJA_MP pero sin concepto de
     * gasto asociado (expense_concept_id null), para probar que una config de tesoreria incompleta
     * no revienta una venta (criterio de exito del Grupo 223).
     */
    const CAJA_SIN_CONCEPTO = 'Caja Sin Concepto';

    /**
     * Impuesto de ejemplo sobre ventas (IIBB), para que Posicion Fiscal (grupo 245) tenga un
     * renglon que mostrar en vez del estado vacio.
     */
    const IMPUESTO_IIBB = 'IIBB';

    /**
     * Porcentaje de descuento por pagar con el metodo "Efectivo" (circuito e2e de ventas).
     *
     * Es un `current_acount_payment_method_discount`, o sea el descuento que el sistema aplica SOLO
     * sobre el monto cobrado con ese metodo -- no es un descuento de la venta. Es la forma tipica
     * de premiar el pago en efectivo.
     */
    const DESCUENTO_EFECTIVO = 10;

    /**
     * Numero de punto de venta del `afip_information` del fixture.
     */
    const PUNTO_VENTA = 1;

    /**
     * 🔴 CUIT del punto de venta, y NO es un numero cualquiera: tiene que ser el mismo del
     * certificado de homologacion que el servidor tiene en `storage/app/afip/testing/`
     * (`CN=comerciocitytester`, `serialNumber=CUIT 20423548984`).
     *
     * Con otro CUIT, WSAA rechaza el pedido de ticket de acceso y el error que vuelve no dice en
     * ningun lado que el problema sea el CUIT: se ve como una falla generica de conexion con AFIP.
     */
    const PUNTO_VENTA_CUIT = '20423548984';

    /**
     * Slug de la extension de tienda online, tal cual lo siembra `ExtencionSeeder`. Es la que
     * habilita el campo "Disponible en la tienda" del articulo (ver seed_extenciones()).
     */
    const EXTENCION_ONLINE = 'online';

    /**
     * Slug de la extension de presupuestos. Sin ella, el toggle "Guardar como presupuesto" de
     * VENDER **no se dibuja** (`v-if="hasExtencion('budgets') && client"` en
     * stage-1/GuardarComoPresupuesto.vue), igual que pasaba con "Disponible en la tienda" y
     * `online`. Y sin ese toggle no hay forma de probar el circuito de presupuestos por interfaz.
     */
    const EXTENCION_PRESUPUESTOS = 'budgets';

    /**
     * Id del usuario del fixture, resuelto una sola vez por `USER_EMAIL` (ver user_id_fixture()).
     *
     * @var int|null
     */
    protected $user_id_fixture = null;

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
        // Grupo 242 · Prompt 02: lo nuevo (clientes/cajas/gastos/impuesto de ventas) se siembra
        // SIEMPRE, este completo o no el fixture viejo, porque es 100% firstOrCreate (no duplica).
        // Va antes del chequeo de completitud a proposito: es lo que permite que una base con el
        // fixture viejo ya sembrado (10 articulos, sin CAJA_MP) quede "completa" de nuevo sin volver
        // a pasar por seed_base_data() (que NO es idempotente y duplicaria usuarios/ivas/proveedores).
        $this->seed_ventas_y_tesoreria();

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
     * Chequea si el fixture ya esta completo: los 10 articulos del catalogo original (Prompt 613)
     * Y `CAJA_MP` (Grupo 242 · Prompt 02). Se agrega CAJA_MP a la condicion para que una base
     * sembrada con el fixture viejo (solo articulos) se re-sembrada y reciba lo nuevo una vez.
     *
     * @return bool
     */
    protected function fixture_completo()
    {
        // Si todavia no existe el usuario del fixture, el fixture no puede estar completo. Este
        // chequeo corre antes que `seed_base_data()`, asi que no se puede exigir el usuario aca
        // (a diferencia del resto del seeder, que ya lo tiene garantizado).
        $user = User::where('email', self::USER_EMAIL)->first();

        if (is_null($user)) {
            return false;
        }

        $nombres = array_column($this->catalogo(), 'name');

        // Acotado por usuario por el mismo motivo que los proveedores: articulos como "Martillo" o
        // "Pinza" existen en cualquier cuenta de ferreteria. Sin el filtro, los articulos de OTRO
        // usuario podian dar el fixture por completo y saltearse el sembrado.
        $articulos_completos = Article::whereIn('name', $nombres)
            ->where('user_id', $user->id)
            ->count() === count($nombres);

        $caja_mp_existe = Caja::where('name', self::CAJA_MP)->where('user_id', $user->id)->exists();

        return $articulos_completos && $caja_mp_existe;
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
        // Grupo 242 · Prompt 02: `seed_ventas_y_tesoreria()` corre siempre, antes de este metodo
        // (ver run()), y en una base 100% fresca necesita condiciones de IVA y metodos de pago de
        // cuenta corriente que todavia no existen, asi que los crea ella misma (firstOrCreate). Si
        // eso ya paso, `CAPaymentMethodTypeSeeder`/`CurrentAcountPaymentMethodSeeder`/`IvaConditionSeeder`
        // (que usan create(), no firstOrCreate) duplicarian esos datos si se llaman de nuevo aca.
        // Por eso se guardan detras de un chequeo de existencia, sin tocar los seeders compartidos.
        if (!CurrentAcountPaymentMethod::exists()) {
            $this->call(CAPaymentMethodTypeSeeder::class);
            $this->call(CurrentAcountPaymentMethodSeeder::class);
        }

        $this->call(IvaSeeder::class);

        if (!IvaCondition::exists()) {
            $this->call(IvaConditionSeeder::class);
        }

        $this->call(ProviderOrderStatusSeeder::class);
        $this->call(ConceptoStockMovementSeeder::class);

        // 🔴 Sin monedas sembradas, la lista de proveedores del SPA no se puede usar.
        //
        // `credit_accounts` nace con `moneda_id` 1 o 2 (lo hace CurrentAcountHelper al crear la
        // cuenta corriente de un proveedor), `ProviderController` eager-loadea
        // `credit_accounts.moneda`, y `components/common/BtnCurrentAcounts.vue` renderiza
        // `credit_account.moneda.name` sin chequear null. Con la tabla vacia ese render tira
        // "Cannot read properties of null (reading 'name')" por cada proveedor con cuenta
        // corriente: el boton C/C nunca aparece y no hay forma de abrir la cuenta corriente desde
        // la interfaz. Medido el 19/8/2026 en el slot s8, 82 errores de consola en
        // /proveedores/proveedores despues de cargar una compra con "Generar movimiento en Cuenta
        // Corriente".
        //
        // En una cuenta real `monedas` siempre esta sembrada (DatabaseSeeder la corre): esto es un
        // hueco del fixture, no del producto. Va detras del chequeo de existencia por el mismo
        // motivo que IvaConditionSeeder/CAPaymentMethodTypeSeeder de mas arriba: MonedaSeeder usa
        // create(), no firstOrCreate, y correrlo dos veces duplica Peso y Dolar.
        if (!Moneda::exists()) {
            $this->call(MonedaSeeder::class);
        }

        // Guardado por el mismo motivo: `seed_ventas_y_tesoreria()` corre antes que este
        // metodo y ya pudo haberlo sembrado. `UserSeeder` usa create() con id explicito.
        if (is_null(User::find(config('app.USER_ID')))) {
            $this->call(UserSeeder::class);
        }

        // Prompt 231/02: el usuario de prueba se marca como cuenta ya migrada a la dinamica
        // contable real (condicion fiscal manda por sobre la tilde legacy). El fixture de compras
        // describe esa dinamica nueva (RRII vs. MT via ArticlePricesHelper::iva_va_al_costo()),
        // no el comportamiento legacy que sigue leyendo aplicar_iva_al_costo directamente.
        User::where('email', self::USER_EMAIL)->update(['usar_condicion_fiscal_en_costeo' => 1]);

        $this->call(PriceTypeSeeder::class);
        $this->call(DepositSeeder::class);
    }

    /**
     * Siembra el lado de ventas y tesoreria del fixture (Grupo 242 · Prompt 02): clientes, metodos
     * de pago de cuenta corriente, conceptos de gasto, cajas y el impuesto de IIBB. Todo con
     * `firstOrCreate` para poder llamarse siempre (ver comentario en run()), sin depender de si
     * `seed_base_data()` ya corrio en esta ejecucion.
     *
     * @return void
     */
    protected function seed_ventas_y_tesoreria()
    {
        // Condiciones de IVA y metodos de pago de cuenta corriente son dependencias duras de
        // clientes/cajas. En una base 100% fresca todavia no existen (este metodo corre antes de
        // `seed_base_data()`), asi que se garantizan aca de forma idempotente. Si ya existen (fixture
        // viejo, o una ejecucion posterior de este mismo seeder) no se tocan.
        if (!IvaCondition::exists()) {
            $this->call(IvaConditionSeeder::class);
        }

        if (!CurrentAcountPaymentMethod::exists()) {
            $this->call(CAPaymentMethodTypeSeeder::class);
            $this->call(CurrentAcountPaymentMethodSeeder::class);
        }

        // `ArticlePurchaseHelper::get_sale_channel_id()` hace SaleChannel::where('slug', ...)->first()->id
        // sin chequear null: sin canales sembrados, cualquier POST a api/sale muere con 500. El seeder
        // de produccion usa create() (no es idempotente), por eso va detras del chequeo de existencia,
        // mismo criterio que IvaConditionSeeder/CAPaymentMethodTypeSeeder aca arriba.
        if (!SaleChannel::exists()) {
            $this->call(SaleChannelSeeder::class);
        }

        // `clients.user_id` es FK a `users`: sin el usuario del fixture, la primera
        // insercion de cliente viola la constraint. En una base 100% fresca ese usuario
        // todavia no existe, porque `UserSeeder` corre en `seed_base_data()`, DESPUES de
        // este metodo (ver run()). Mismo criterio que las tres dependencias de arriba, con
        // una razon extra para el chequeo de existencia: `UserSeeder` usa create() con id
        // explicito, correrlo dos veces revienta por clave duplicada.
        if (is_null(User::find(config('app.USER_ID')))) {
            $this->call(UserSeeder::class);
        }

        $this->seed_clientes();

        $this->seed_metodos_pago_cta_cte();

        // Circuito e2e completo (31/8/2026): el descuento por metodo de pago y el punto de venta de
        // homologacion. Los dos son `firstOrCreate`, asi que entran en el bloque que corre SIEMPRE.
        $this->seed_descuento_metodo_pago();

        $this->seed_afip_information();

        $this->seed_extenciones();

        $conceptos = $this->seed_conceptos_gasto();

        $this->seed_cajas_tesoreria($conceptos);

        $this->abrir_caja_efectivo();

        $this->seed_impuesto_iibb();
    }

    /**
     * Crea los 3 clientes del fixture (tarea 01), con `iva_condition_id` resuelto por nombre.
     * `CLIENTE_EXENTO` usa la condicion "Exento" (distinta a Responsable Inscripto) para los tests
     * de facturacion por condicion del grupo 244. `CLIENTE_CC`/`CLIENTE_CONTADO` usan "Responsable
     * inscripto" por default: la condicion no es lo relevante para esos dos, lo relevante es el
     * nombre (ver constante) que van a usar los tests de tesoreria/cuenta corriente.
     *
     * @return void
     */
    protected function seed_clientes()
    {
        $user_id = $this->user_id_fixture();

        // `iva_conditions` es una tabla global (no tiene columna user_id, ver su migracion), asi que
        // aca no hay nada que acotar por usuario: alcanza con exigir que existan.
        $iva_responsable_inscripto = $this->exigir(
            IvaCondition::where('name', 'Responsable inscripto')->first(),
            'la condicion de IVA "Responsable inscripto"',
            'Corre `IvaConditionSeeder` antes de este seeder.'
        );

        $iva_exento = $this->exigir(
            IvaCondition::where('name', 'Exento')->first(),
            'la condicion de IVA "Exento"',
            'Corre `IvaConditionSeeder` antes de este seeder.'
        );

        $clientes = [
            self::CLIENTE_CC       => $iva_responsable_inscripto,
            self::CLIENTE_CONTADO  => $iva_responsable_inscripto,
            self::CLIENTE_EXENTO   => $iva_exento,
        ];

        foreach ($clientes as $nombre => $iva_condition) {
            Client::firstOrCreate(
                ['name' => $nombre, 'user_id' => $user_id],
                ['iva_condition_id' => $iva_condition->id]
            );
        }
    }

    /**
     * Garantiza que existan los 2 metodos de pago de cuenta corriente que necesitan los tests
     * (tarea 02), resolviendolos por el nombre real que deja `CurrentAcountPaymentMethodSeeder`
     * ("Efectivo" y "Credito"). Si por algun motivo no existieran con ese nombre, se crean aca con
     * `firstOrCreate` (nunca modificando el seeder de produccion).
     *
     * @return void
     */
    protected function seed_metodos_pago_cta_cte()
    {
        // Sin `user_id`: `current_acount_payment_methods` es una tabla global (ver su migracion), y
        // el `firstOrCreate` no puede devolver null. No es un filtro olvidado.
        CurrentAcountPaymentMethod::firstOrCreate([
            'name' => self::PAGO_EFECTIVO,
        ]);

        CurrentAcountPaymentMethod::firstOrCreate([
            'name' => self::PAGO_TARJETA_CREDITO,
        ]);
    }

    /**
     * Descuento por metodo de pago para "Efectivo" (circuito e2e de ventas).
     *
     * A diferencia de un descuento de venta, este se aplica sobre el MONTO COBRADO con ese metodo,
     * automaticamente, cada vez que ese metodo se usa en un cobro. Sin el, el circuito de ventas no
     * tiene forma de verificar por la interfaz que el descuento por metodo de pago se aplique: en
     * una base recien sembrada la tabla `current_acount_payment_method_discounts` esta vacia.
     *
     * @return void
     */
    protected function seed_descuento_metodo_pago()
    {
        $efectivo = CurrentAcountPaymentMethod::where('name', self::PAGO_EFECTIVO)->first();

        if (is_null($efectivo)) {
            return;
        }

        // La clave del firstOrCreate es (metodo, usuario) y no incluye el porcentaje: si el
        // descuento ya existe con otro valor, este seeder no lo pisa. Es la misma politica que el
        // resto del fixture -- sembrar lo que falta, nunca corregir lo que ya esta.
        CurrentAcountPaymentMethodDiscount::firstOrCreate(
            [
                'current_acount_payment_method_id' => $efectivo->id,
                'user_id'                          => $this->user_id_fixture(),
            ],
            [
                'discount_percentage' => self::DESCUENTO_EFECTIVO,
                'cuotas'              => null,
            ]
        );
    }

    /**
     * Punto de venta (`afip_information`) del fixture, en modo HOMOLOGACION.
     *
     * Sin esto no se puede facturar una venta ni emitir una nota de credito: lo que decide si una
     * venta se factura es que se elija un punto de venta, y en una base recien sembrada no hay
     * ninguno.
     *
     * 🔴 `afip_ticket_production` va explicitamente en 0 aunque la columna ya tenga ese default.
     * Es la unica cosa que separa una emision de prueba contra `wsaahomo.afip.gov.ar` de una
     * emision REAL contra AFIP produccion, y un fixture de testing no puede depender de que nadie
     * haya cambiado el default de la migracion.
     *
     * @return void
     */
    protected function seed_afip_information()
    {
        $condicion = IvaCondition::where('name', 'Responsable inscripto')->first();

        if (is_null($condicion)) {
            return;
        }

        AfipInformation::firstOrCreate(
            [
                'user_id'     => $this->user_id_fixture(),
                'punto_venta' => self::PUNTO_VENTA,
            ],
            [
                'iva_condition_id'        => $condicion->id,
                'description'             => 'Punto de venta de homologacion',
                'razon_social'            => 'ComercioCity Tester',
                'domicilio_comercial'     => 'Av. Siempreviva 742',
                // Tiene que ser el CUIT del certificado de homologacion. Ver PUNTO_VENTA_CUIT.
                'cuit'                    => self::PUNTO_VENTA_CUIT,
                'ingresos_brutos'         => self::PUNTO_VENTA_CUIT,
                'inicio_actividades'      => '2020-01-01',
                'afip_ticket_production'  => 0,
            ]
        );
    }

    /**
     * Deja `CAJA_EFECTIVO` ABIERTA.
     *
     * 🔴 No es un detalle: el selector de caja de un cobro y el de un pago ofrecen SOLO las cajas
     * abiertas (`cajas_abiertas` en `src/mixins/vender/cajas.js`). Con las tres cajas cerradas
     * --como nacian-- el select existe, se dibuja, y esta VACIO: Playwright lo reporta como
     * "did not find some options", que manda a buscar el problema en el nombre de la caja o en el
     * vinculo caja-metodo de pago, cuando lo que pasa es que ninguna esta abierta.
     *
     * Se abre una sola, y con el helper de produccion (`CajaAperturaHelper`) en vez de tocar las
     * columnas a mano: abrir una caja es crear su `apertura_caja` Y marcarla, y una caja marcada
     * abierta sin apertura es un estado que en una cuenta real no existe.
     *
     * Las otras dos quedan cerradas a proposito: sirven de contraste, y el circuito de ventas con
     * multiples metodos de pago va a necesitar abrir alguna mas por su cuenta.
     *
     * @return void
     */
    protected function abrir_caja_efectivo()
    {
        $caja = Caja::where('name', self::CAJA_EFECTIVO)
                    ->where('user_id', $this->user_id_fixture())
                    ->first();

        if (is_null($caja) || $caja->abierta) {
            return;
        }

        $helper = new CajaAperturaHelper($caja->id);
        $helper->abrir_caja();
    }

    /**
     * Habilita las extensiones que el fixture necesita para que ciertas pantallas EXISTAN.
     *
     * 🔴 Sin ella, el campo **"Disponible en la tienda"** (`articles.online`) NO EXISTE EN NINGUNA
     * PANTALLA: `src/models/article.js` lo declara con `if_has_extencion: 'online'`, y
     * `common-vue/mixins/generals.js` filtra de plano las props cuya extension el usuario no tiene.
     * O sea que no aparece ni en el formulario del articulo ni en la actualizacion masiva, y el
     * dato igual existe en la base con el default 1 de la migracion: el operador no lo puede tocar.
     *
     * Es un hueco del fixture, no del producto -- una cuenta real que vende online tiene la
     * extension --, y sin taparlo no hay forma de verificar por interfaz que la masiva prenda y
     * apague la disponibilidad en el ecommerce.
     *
     * Lo mismo vale para `budgets`: sin ella el toggle "Guardar como presupuesto" de VENDER no se
     * dibuja, y el circuito de presupuestos no tiene por donde entrar.
     *
     * Se habilitan SOLO esas dos y no el catalogo entero a proposito: cada extension cambia lo que
     * la SPA dibuja, y prender de mas mueve pantallas que ningun test pidio.
     *
     * @return void
     */
    protected function seed_extenciones()
    {
        // El catalogo de extensiones lo crea `ExtencionSeeder` con `create()`, que no es
        // idempotente: va detras de un chequeo de existencia, mismo criterio que
        // IvaConditionSeeder / CAPaymentMethodTypeSeeder mas arriba.
        if (!ExtencionEmpresa::exists()) {
            $this->call(ExtencionSeeder::class);
        }

        $user = User::where('email', self::USER_EMAIL)->first();

        if (is_null($user)) {
            return;
        }

        $slugs = [self::EXTENCION_ONLINE, self::EXTENCION_PRESUPUESTOS];

        $ids = ExtencionEmpresa::whereIn('slug', $slugs)->pluck('id')->all();

        if (count($ids) === 0) {
            return;
        }

        // `syncWithoutDetaching` y no `attach`: attach agrega una fila mas cada vez que el seeder
        // se corre, y este metodo corre SIEMPRE (ver run()). Y tampoco `sync()`, que le sacaria al
        // usuario cualquier otra extension que alguien le haya prendido a mano.
        $user->extencions()->syncWithoutDetaching($ids);
    }

    /**
     * Crea los 2 conceptos de gasto del fixture (tarea 03): el de comisiones bancarias (que usan
     * las cajas comisionadas para el gasto automatico) y uno operativo generico.
     *
     * @return array<string,\App\Models\ExpenseConcept> ExpenseConcept indexado por nombre.
     */
    protected function seed_conceptos_gasto()
    {
        $user_id = $this->user_id_fixture();

        $nombres = [self::CONCEPTO_GASTO_COMISION, self::CONCEPTO_GASTO_OPERATIVO];

        $conceptos = [];

        foreach ($nombres as $nombre) {
            $conceptos[$nombre] = ExpenseConcept::firstOrCreate(
                ['name' => $nombre, 'user_id' => $user_id],
                ['num' => $this->siguiente_num(ExpenseConcept::class, $user_id)]
            );
        }

        return $conceptos;
    }

    /**
     * Crea las 3 cajas de tesoreria del fixture (tarea 04). `CAJA_EFECTIVO` queda sin ninguna
     * columna de liquidacion/comision seteada (todo null), igual que una caja de siempre.
     * `CAJA_MP` y `CAJA_SIN_CONCEPTO` comparten la misma config de liquidacion/comision de Mercado
     * Pago; la unica diferencia es que `CAJA_SIN_CONCEPTO` no tiene `expense_concept_id`.
     *
     * @param array<string,\App\Models\ExpenseConcept> $conceptos Conceptos de gasto (ver seed_conceptos_gasto).
     * @return void
     */
    protected function seed_cajas_tesoreria($conceptos)
    {
        $user_id = $this->user_id_fixture();

        $concepto_comision_id = $conceptos[self::CONCEPTO_GASTO_COMISION]->id;

        Caja::firstOrCreate(
            ['name' => self::CAJA_EFECTIVO, 'user_id' => $user_id],
            [
                'num'                   => $this->siguiente_num(Caja::class, $user_id),
                'dias_liquidacion'      => null,
                'comision_porcentaje'   => null,
                'expense_concept_id'    => null,
                'comision_iva_alicuota' => null,
                'comision_iva_incluido' => 0,
            ]
        );

        Caja::firstOrCreate(
            ['name' => self::CAJA_MP, 'user_id' => $user_id],
            [
                'num'                   => $this->siguiente_num(Caja::class, $user_id),
                'dias_liquidacion'      => 14,
                'comision_porcentaje'   => 6.29,
                'expense_concept_id'    => $concepto_comision_id,
                'comision_iva_alicuota' => 21,
                'comision_iva_incluido' => 1,
            ]
        );

        Caja::firstOrCreate(
            ['name' => self::CAJA_SIN_CONCEPTO, 'user_id' => $user_id],
            [
                'num'                   => $this->siguiente_num(Caja::class, $user_id),
                'dias_liquidacion'      => 14,
                'comision_porcentaje'   => 6.29,
                'expense_concept_id'    => null,
                'comision_iva_alicuota' => 21,
                'comision_iva_incluido' => 1,
            ]
        );

        // No se siembra ningun caja_liquidacion_configs (tarea 04): los overrides por metodo de
        // pago los crea cada test que los necesita, porque cada uno prueba una combinacion distinta
        // de la cascada de liquidacion.
    }

    /**
     * Crea el impuesto de IIBB de ejemplo (tarea 05), un unico `SaleTax` que aplica a todos los
     * articulos.
     *
     * @return void
     */
    protected function seed_impuesto_iibb()
    {
        $user_id = $this->user_id_fixture();

        SaleTax::firstOrCreate(
            ['name' => self::IMPUESTO_IIBB, 'user_id' => $user_id],
            [
                'percentage'    => 3.5,
                'apply_to_all'  => true,
                'activo'        => true,
            ]
        );
    }

    /**
     * Id del usuario duenio del fixture, resuelto por `USER_EMAIL` (nunca por `config('app.USER_ID')`,
     * que depende del .env activo) y cacheado para no repetir la consulta.
     *
     * Todas las busquedas de modelos de este seeder se acotan con este id: sin el filtro, un modelo
     * homonimo de OTRO usuario puede ganar el `first()` (nombres como "Buenos Aires" o "Martillo" se
     * repiten entre cuentas), y el fixture terminaria leyendo y modificando datos ajenos.
     *
     * @return int
     * @throws \RuntimeException Si el usuario del fixture no existe en la base.
     */
    protected function user_id_fixture()
    {
        if (is_null($this->user_id_fixture)) {
            $user = User::where('email', self::USER_EMAIL)->first();

            $this->exigir(
                $user,
                sprintf('el usuario del fixture (email %s)', self::USER_EMAIL),
                'Corre `UserSeeder` antes de este seeder.'
            );

            $this->user_id_fixture = $user->id;
        }

        return $this->user_id_fixture;
    }

    /**
     * Corta la ejecucion con un mensaje claro cuando un modelo que el fixture da por sentado no
     * aparece. Antes estas consultas devolvian `null` en silencio y el seeder seguia, hasta reventar
     * veinte lineas mas abajo con un "propiedad de null" que no decia nada de la causa real.
     *
     * @param mixed $modelo Resultado de la consulta (modelo o null).
     * @param string $que Que se estaba buscando, en criollo.
     * @param string $como_arreglar Que tiene que hacer quien lee el error.
     * @return mixed El mismo modelo, para poder encadenar.
     * @throws \RuntimeException Si $modelo es null.
     */
    protected function exigir($modelo, $que, $como_arreglar)
    {
        if (is_null($modelo)) {
            throw new \RuntimeException(
                sprintf('TestingFerreteriaSeeder: no se encontro %s. %s', $que, $como_arreglar)
            );
        }

        return $modelo;
    }

    /**
     * Calcula el proximo correlativo `num` para un modelo con esa columna, acotado por `user_id`.
     * Equivalente minimo (sin el lock transaccional del `Controller::num()` de produccion, que
     * necesita un `Request`) para usar en un seeder.
     *
     * @param string $modelo Clase del modelo Eloquent (ej. Caja::class).
     * @param int $user_id Owner al que se le calcula el correlativo.
     * @return int
     */
    protected function siguiente_num($modelo, $user_id)
    {
        $ultimo = $modelo::where('user_id', $user_id)->max('num');

        return is_null($ultimo) ? 1 : $ultimo + 1;
    }

    /**
     * Crea los proveedores del fixture reusando `ProviderSeeder` (ya crea "Buenos Aires" y
     * "Rosario" con esos nombres exactos) y los resuelve por nombre —para no depender del orden de
     * insercion— **acotados al usuario del fixture** (ver user_id_fixture()), que es lo unico que
     * garantiza que el proveedor que se engancha sea el propio y no el homonimo de otra cuenta.
     *
     * @return array<string,\App\Models\Provider> Provider indexado por PROVIDER_BSAS/PROVIDER_OTRO.
     * @throws \RuntimeException Si falta alguno de los dos proveedores para ese usuario.
     */
    protected function seed_providers()
    {
        $this->call(ProviderSeeder::class);

        $user_id = $this->user_id_fixture();

        $providers = [];

        foreach ([self::PROVIDER_BSAS, self::PROVIDER_OTRO] as $nombre) {
            // El `where('user_id', ...)` NO sobra: "Buenos Aires" es un nombre de proveedor de lo
            // mas comun y se repite entre usuarios. Sin el filtro, el `first()` puede devolver el
            // proveedor de otra cuenta, y a partir de ahi el fixture le cuelga las bonificaciones
            // del 10% y el 5% a un proveedor ajeno (lo MODIFICA) y la compra del test sale con
            // totales que no tienen nada que ver con lo que se esta probando.
            $providers[$nombre] = $this->exigir(
                Provider::where('name', $nombre)->where('user_id', $user_id)->first(),
                sprintf('el proveedor "%s" del usuario del fixture', $nombre),
                'Corre `ProviderSeeder` (o el seeder base) antes de este seeder.'
            );
        }

        return $providers;
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
            'user_id' => $this->user_id_fixture(),
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

            /**
             * Precios finales calculados con la logica central del sistema, igual que un alta real.
             * El usuario es el mismo con el que se resolvieron proveedores y deposito (por email, no
             * por el USER_ID del .env activo): mezclar dos fuentes de user_id en el mismo fixture es
             * justo lo que hacia que un modelo ajeno se colara.
             */
            ArticleHelper::setFinalPrice($created_article, $this->user_id_fixture());
        }
    }

    /**
     * Resuelve el `iva_id` buscando por `percentage` (columna de tipo texto en `ivas`: valores
     * numericos como '21'/'10.5' conviven con literales 'Exento'/'No Gravado').
     *
     * `ivas` es una tabla global (no tiene columna user_id, ver su migracion): no hay nada que
     * acotar por usuario, pero si el iva no esta el articulo quedaba con `iva_id` null en silencio
     * y el error recien aparecia al calcular precios. Por eso ahora corta aca.
     *
     * @param string $percentage Valor tal cual se guarda en ivas.percentage.
     * @return int
     * @throws \RuntimeException Si no existe un iva con ese percentage.
     */
    protected function resolver_iva_id($percentage)
    {
        $iva = $this->exigir(
            Iva::where('percentage', $percentage)->first(),
            sprintf('el iva con percentage "%s"', $percentage),
            'Corre `IvaSeeder` antes de este seeder.'
        );

        return $iva->id;
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
