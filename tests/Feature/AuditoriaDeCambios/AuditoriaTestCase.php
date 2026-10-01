<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLog\AuditContext;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * Base de los tests de la auditoría de cambios (misión auditoria-de-cambios, 30/9/2026).
 *
 * Extiende `EmpresaTestCase` (base de testing real, InnoDB, fixture sembrado y usuario 500
 * autenticado). Agrega lo que toda prueba de auditoría necesita:
 *
 *  - Reinicia el `AuditContext` antes y después de cada test: sus estáticos SOBREVIVEN entre un
 *    test y el siguiente (y entre dos requests de un mismo test), y un lote, un tope o un aviso de
 *    falla de un test no puede contaminar al otro.
 *  - Recuerda el último id de `audit_logs` al arrancar, para que cada test vea solo las filas que
 *    generó él (la base de testing puede traer filas de corridas anteriores fuera de transacción).
 *
 * No termina en `Test.php` a propósito: PHPUnit no la toma como un test.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
abstract class AuditoriaTestCase extends EmpresaTestCase
{
    /**
     * Último id de audit_logs al empezar el test.
     *
     * @var int
     */
    protected $id_inicial = 0;

    /**
     * El usuario dueño del fixture (id 500).
     *
     * @var \App\Models\User
     */
    protected $dueno;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('audit_logs')) {
            $this->fail(
                'Falta la tabla audit_logs en la base de testing. Correla con: '
                . 'php artisan migrate --env=testing --path=database/migrations/2026_09_30_120000_create_audit_logs_table.php'
            );
        }

        AuditContext::reiniciar();

        $this->id_inicial = (int) AuditLog::max('id');

        $this->dueno = auth()->user();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        AuditContext::reiniciar();

        parent::tearDown();
    }

    /**
     * Filas de auditoría que generó ESTE test, opcionalmente filtradas por modelo y evento.
     *
     * @param string|null $clase Clase completa del modelo.
     * @param string|null $evento
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function filas($clase = null, $evento = null)
    {
        $consulta = AuditLog::where('id', '>', $this->id_inicial)->orderBy('id');

        if (!is_null($clase)) {
            $consulta->where('auditable_type', $clase);
        }

        if (!is_null($evento)) {
            $consulta->where('event', $evento);
        }

        return $consulta->get();
    }

    /**
     * Payload mínimo que acepta ArticleController (varias columnas de `articles` son NOT NULL sin
     * default y el controlador las asigna tal cual vienen del request).
     *
     * @param array $props
     * @return array
     */
    protected function payload_articulo(array $props = [])
    {
        return array_merge([
            'name'                           => 'ZZ Auditoria articulo',
            'cost'                           => 100,
            'iva_id'                         => 2,
            'aplicar_iva'                    => 1,
            'apply_provider_percentage_gain' => 0,
            'cost_in_dollars'                => 0,
            'provider_cost_in_dollars'       => 0,
            'online'                         => 0,
            'in_offer'                       => 0,
            'precio_pausado'                 => 0,
            'default_in_vender'              => 0,
            'personalizar_price_en_vender'   => 0,
            'omitir_en_lista_pdf'            => 0,
            'mercado_libre'                  => 0,
            'disponible_tienda_nube'         => 0,
            'requires_shipping'              => 0,
            'free_shipping'                  => 0,
            'es_insumo'                      => 0,
            'featured'                       => 0,
            'price_types'                    => [],
            'price_type_monedas'             => [],
            'tags'                           => [],
            'addresses'                      => [],
            'childrens'                      => [],
        ], $props);
    }

    /**
     * Crea un artículo directo en la base (sin pasar por el endpoint), del dueño del fixture.
     *
     * @param array $props
     * @return \App\Models\Article
     */
    protected function crear_articulo(array $props = [])
    {
        $articulo = new \App\Models\Article();

        $articulo->user_id = $this->dueno->id;
        $articulo->name    = 'ZZ Auditoria directo';
        $articulo->status  = 'active';
        $articulo->iva_id  = 2;

        foreach ($props as $campo => $valor) {
            $articulo->$campo = $valor;
        }

        $articulo->save();

        return $articulo;
    }

    /**
     * Crea un empleado del dueño del fixture.
     *
     * @param string $nombre
     * @return \App\Models\User
     */
    protected function crear_empleado($nombre = 'ZZ Empleado auditoria')
    {
        $sufijo = uniqid();

        return User::create([
            'name'         => $nombre,
            'company_name' => 'Ferreteria auditoria',
            'email'        => 'aud-empleado-' . $sufijo . '@test.local',
            'doc_number'   => 'DOC-AUD-' . $sufijo,
            'password'     => bcrypt('no-importa'),
            'owner_id'     => $this->dueno->id,
        ]);
    }
}
