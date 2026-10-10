<?php

namespace Tests\Feature\PdfDeVentaPublico;

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\SaleController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Base de los tests de la misión pdf-de-venta-publico (10/10/2026): las rutas de PDF y export de
 * `routes/web.php` dejan de ser públicas (middleware `descarga.comercio`), los links que salen del
 * sistema llevan `?t=<token>` (tabla `pdf_links`) y hay una ventana de transición por dueño
 * (`users.pdf_links_legacy_until`).
 *
 * 🔴 EL CAMINO FELIZ DE UN PDF NO SE PUEDE PEDIR DESDE PHPUNIT: los constructores de las clases *Pdf
 * terminan en `$this->Output(); exit;` y ese exit mata el proceso del test (ver
 * Sales/7_Pdf_De_Modelo_Inexistente_Test). Por eso, para probar que la regla DEJA PASAR, las rutas
 * reales se piden con el controlador reemplazado en el contenedor por uno que contesta un texto fijo
 * (`fingir_controladores()`, que corre en el setUp): se prueba la ruta real, con su middleware real,
 * hasta la puerta del controlador.
 *
 * Todo lo que se siembra va con `DB::table()` (sin observers ni eventos) y dentro de la
 * transacción de EmpresaTestCase, que lo revierte al terminar.
 */
abstract class DescargaTestCase extends EmpresaTestCase
{
    /** Lo que contestan los controladores fingidos: si el cuerpo es éste, la regla dejó pasar. */
    const SERVIDA = 'DESCARGA-SERVIDA';

    /** @var \App\Models\User El dueño del fixture (id 500, el USER_ID del .env.testing). */
    protected $dueno;

    /**
     * El dueño del fixture, con la ventana de transición CERRADA (null): es el estado de una base
     * de testing recién sembrada y el de cualquier instalación nueva. Cada test que la necesita
     * abierta la abre explícitamente.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Los tests no tienen que pegarle a Pusher de verdad si algo dispara un broadcast.
        config(['broadcasting.default' => 'null']);

        $this->dueno = User::find(500);

        $this->cerrar_ventana($this->dueno->id);

        /*
         * Siempre, y no solo en los tests que esperan 200: si algún día la regla deja de cortar, un
         * test que espera 404 tiene que FALLAR con un 200 y no matar el proceso con el exit() del PDF
         * real (que deja la corrida sin resumen y sin XML de junit, medido el 10/10/2026 apagando el
         * middleware a mano).
         */
        $this->fingir_controladores();
    }

    /**
     * Un comercio distinto, dueño (sin owner_id), para los casos de base compartida.
     *
     * @return \App\Models\User
     */
    protected function crear_otro_comercio()
    {
        $id = DB::table('users')->insertGetId([
            'name'         => 'Otro comercio pdf publico',
            'company_name' => 'Otro comercio pdf publico',
            'email'        => 'pdf-publico-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'status'       => 'commerce',
            'api_url'      => 'https://api-otro.comerciocity.com',
            'created_at'   => Carbon::now(),
            'updated_at'   => Carbon::now(),
        ]);

        return User::find($id);
    }

    /**
     * Un empleado del dueño del fixture.
     *
     * @return \App\Models\User
     */
    protected function crear_empleado_del_dueno()
    {
        $id = DB::table('users')->insertGetId([
            'name'       => 'Empleado pdf publico',
            'email'      => 'pdf-publico-empleado-' . uniqid() . '@test.local',
            'password'   => Hash::make('secret'),
            'status'     => 'commerce',
            'owner_id'   => $this->dueno->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return User::find($id);
    }

    /**
     * Una venta del comercio indicado.
     *
     * @param  int  $user_id
     * @return int  Id de la venta.
     */
    protected function venta_de($user_id)
    {
        return DB::table('sales')->insertGetId([
            'user_id'    => $user_id,
            'num'        => 990000 + mt_rand(1, 9999),
            'total'      => 1500,
            'moneda_id'  => 1,
            'terminada'  => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Un presupuesto del comercio indicado (necesita un cliente: `budgets.client_id` no es nullable).
     *
     * @param  int  $user_id
     * @return int
     */
    protected function presupuesto_de($user_id)
    {
        $client_id = DB::table('clients')->insertGetId([
            'name'       => 'Cliente pdf publico',
            'user_id'    => $user_id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return DB::table('budgets')->insertGetId([
            'user_id'          => $user_id,
            'client_id'        => $client_id,
            'num'              => 990000 + mt_rand(1, 9999),
            'total'            => 100,
            'budget_status_id' => 1,
            'created_at'       => Carbon::now(),
            'updated_at'       => Carbon::now(),
        ]);
    }

    /**
     * Una cuenta corriente de cliente con un movimiento, del comercio indicado.
     *
     * @param  int  $user_id
     * @param  int|null  $user_id_del_movimiento  El `user_id` del movimiento (null = viejo, sin dueño).
     * @return array{credit_account_id: int, current_acount_id: int, client_id: int}
     */
    protected function cuenta_corriente_de($user_id, $user_id_del_movimiento = 'igual')
    {
        $client_id = DB::table('clients')->insertGetId([
            'name'       => 'Cliente cc pdf publico',
            'email'      => 'cliente-cc-' . uniqid() . '@test.local',
            'user_id'    => $user_id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $credit_account_id = DB::table('credit_accounts')->insertGetId([
            'model_name' => 'client',
            'model_id'   => $client_id,
            'moneda_id'  => 1,
            'user_id'    => $user_id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $current_acount_id = DB::table('current_acounts')->insertGetId([
            'status'            => 'pago_from_client',
            'credit_account_id' => $credit_account_id,
            'client_id'         => $client_id,
            'user_id'           => $user_id_del_movimiento === 'igual' ? $user_id : $user_id_del_movimiento,
            'haber'             => 100,
            'created_at'        => Carbon::now(),
            'updated_at'        => Carbon::now(),
        ]);

        return [
            'credit_account_id' => $credit_account_id,
            'current_acount_id' => $current_acount_id,
            'client_id'         => $client_id,
        ];
    }

    /**
     * Abre la ventana de transición del comercio (hasta dentro de 10 días).
     *
     * @param  int  $user_id
     * @return void
     */
    protected function abrir_ventana($user_id)
    {
        DB::table('users')->where('id', $user_id)->update(['pdf_links_legacy_until' => Carbon::now()->addDays(10)]);
    }

    /**
     * Cierra la ventana de transición del comercio (null, como una instalación nueva).
     *
     * @param  int  $user_id
     * @return void
     */
    protected function cerrar_ventana($user_id)
    {
        DB::table('users')->where('id', $user_id)->update(['pdf_links_legacy_until' => null]);
    }

    /**
     * Saca la sesión que EmpresaTestCase deja puesta (actingAs del dueño): el próximo request llega
     * como alguien que abrió el link sin estar logueado.
     *
     * @return void
     */
    protected function sin_sesion()
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Reemplaza en el contenedor los controladores de PDF que se piden en estos tests por versiones
     * que contestan SERVIDA en vez de armar el PDF (que termina en exit). Laravel resuelve el
     * controlador de cada ruta con `$container->make()`, así que la ruta, el grupo `web` y el
     * middleware son los reales.
     *
     * @return void
     */
    protected function fingir_controladores()
    {
        $this->app->instance(SaleController::class, new class extends SaleController {
            function pdf(Request $request, $id) { return response(DescargaTestCase::SERVIDA, 200); }
            function saleTicketPdf($sale_id) { return response(DescargaTestCase::SERVIDA, 200); }
            function deliveredArticlesPdf($id) { return response(DescargaTestCase::SERVIDA, 200); }
            function ticketRaw($id) { return response(DescargaTestCase::SERVIDA, 200); }
            function etiqueta_envio(Request $request, $sale_id) { return response(DescargaTestCase::SERVIDA, 200); }
            function afipTicketA4Pdf(Request $request, $id) { return response(DescargaTestCase::SERVIDA, 200); }
            function excel_export($from_date, $until_date = null) { return response(DescargaTestCase::SERVIDA, 200); }
        });

        $this->app->instance(BudgetController::class, new class extends BudgetController {
            function pdf(Request $request, $id, $with_prices, $with_images) { return response(DescargaTestCase::SERVIDA, 200); }
        });

        $this->app->instance(CurrentAcountController::class, new class extends CurrentAcountController {
            function pdfFromModel($current_acount_id, $cantidad_movimientos = 0, $type = 'simple') { return response(DescargaTestCase::SERVIDA, 200); }
            function pdf($id) { return response(DescargaTestCase::SERVIDA, 200); }
        });

        $this->app->instance(ArticleController::class, new class extends ArticleController {
            function listPdf($ids) { return response(DescargaTestCase::SERVIDA, 200); }
        });
    }

    /**
     * Vuelve al SaleController real, para los tests que necesitan que el controlador corra de verdad
     * (el candado `origin=tienda` vive adentro de `SaleController@pdf`). Se llama ANTES del primer
     * request del test: la ruta guarda el controlador que resolvió.
     *
     * @return void
     */
    protected function usar_el_sale_controller_real()
    {
        $this->app->forgetInstance(SaleController::class);
    }
}
