<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Provider;
use App\Services\AuditLog\AuditContext;
use App\Services\AuditLog\AuditLogRecorder;
use Illuminate\Support\Facades\Log;
use App\Models\Sale;

/**
 * Test 9 del plan: al pasar `max_filas_por_lote` queda exactamente UNA fila `truncated` con el
 * total omitido (misión auditoria-de-cambios, 30/9/2026).
 *
 * Es la red de seguridad contra una operación masiva que nadie previó (un job que guarda miles de
 * filas por Eloquent y no está en `jobs_masivos`): acota el peor caso de crecimiento de la tabla y
 * lo deja dicho, no lo esconde. El tope se baja por `config()` para no tener que guardar 500 filas.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class TopePorLoteTest extends AuditoriaTestCase
{
    /**
     * Fuera de un request o job, el cierre lo dispara quien controla el lote (acá, el test).
     *
     * @return void
     */
    public function test_al_pasar_el_tope_queda_una_sola_fila_truncated_con_el_total_omitido()
    {
        config(['audit_log.max_filas_por_lote' => 3]);

        for ($i = 1; $i <= 7; $i++) {
            Provider::create(['name' => 'ZZ Proveedor tope ' . $i, 'user_id' => $this->dueno->id]);
        }

        // Los siete se guardaron (el tope no toca al negocio)...
        $this->assertSame(7, Provider::where('name', 'like', 'ZZ Proveedor tope %')->count());

        // ...pero solo tres quedaron auditados, más UNA fila truncated.
        $this->assertCount(3, $this->filas(Provider::class, 'created'));
        $this->assertCount(1, $this->filas(null, 'truncated'), 'Exactamente una fila truncated por lote.');

        // Cierre del lote: la fila truncated se actualiza con el total omitido (4 = 7 - 3).
        AuditLogRecorder::cerrar_marco(AuditContext::marco());

        $truncada = $this->filas(null, 'truncated')->first();

        $this->assertSame(['limite' => 3, 'omitidas' => 4], json_decode($truncada->new_values, true));
        $this->assertNull($truncada->auditable_id, 'La fila truncated es un aviso del lote, no un cambio de un modelo.');
        $this->assertSame(AuditContext::marco()->uuid, $truncada->batch_uuid);
    }

    /**
     * Un lote nuevo (otro request) empieza de cero: el tope es por acción, no por proceso.
     *
     * @return void
     */
    public function test_el_tope_es_por_lote_y_un_request_nuevo_arranca_de_cero()
    {
        config(['audit_log.max_filas_por_lote' => 2]);

        for ($i = 1; $i <= 4; $i++) {
            Provider::create(['name' => 'ZZ Proveedor lote A ' . $i, 'user_id' => $this->dueno->id]);
        }

        $this->assertCount(1, $this->filas(null, 'truncated'));

        $lote_anterior = AuditContext::marco()->uuid;

        // Otro request de HTTP: otro lote, con su cuenta en cero.
        $this->postJson('/api/article', $this->payload_articulo(['name' => 'ZZ Auditoria lote nuevo']))->assertStatus(201);

        $del_request = $this->filas()->where('batch_uuid', '!=', $lote_anterior);

        $this->assertNotSame($lote_anterior, AuditContext::marco()->uuid, 'Un request nuevo abre un lote nuevo.');

        // Con tope 2 el alta escribe sus dos primeras filas: no heredó las del lote anterior (si las
        // hubiera heredado, ya estaría pasado del tope y no escribiría ninguna).
        $this->assertSame(
            2,
            $del_request->whereNotIn('event', ['truncated'])->count(),
            'El request nuevo tenía que poder escribir sus propias filas: su lote empieza en cero.'
        );
    }

    /**
     * En HTTP el cierre corre solo, al terminar el request (`terminating`).
     *
     * @return void
     */
    public function test_en_http_el_total_omitido_se_actualiza_al_terminar_el_request()
    {
        config(['audit_log.max_filas_por_lote' => 1]);

        // Un alta de artículo por el endpoint toca varias tablas: con tope 1 pasa de largo.
        $this->postJson('/api/article', $this->payload_articulo(['name' => 'ZZ Auditoria tope http']))->assertStatus(201);

        $truncadas = $this->filas(null, 'truncated');

        $this->assertCount(1, $truncadas, 'Un request que pasa el tope deja una sola fila truncated.');

        $datos = json_decode($truncadas->first()->new_values, true);

        $this->assertSame(1, $datos['limite']);
        $this->assertGreaterThanOrEqual(1, $datos['omitidas'], 'El terminating actualizó el total omitido.');
    }

    /**
     * En la cola el cierre corre al terminar el job (JobProcessed).
     *
     * @return void
     */
    public function test_en_la_cola_el_total_omitido_se_actualiza_al_terminar_el_job()
    {
        config([
            'audit_log.max_filas_por_lote' => 4,
            'audit_log.jobs_masivos'       => [],
        ]);

        // El job de prueba guarda 5 artículos con su created + updated cada uno (10 filas) + un
        // BackgroundProcess: con tope 4 pasan 4 y el resto se cuenta.
        AuditoriaTrabajoMasivoDePrueba::dispatch($this->dueno->id, 5);

        $this->assertCount(1, $this->filas(null, 'truncated'));

        $datos = json_decode($this->filas(null, 'truncated')->first()->new_values, true);

        // 5 created + 5 updated de costo + 1 BackgroundProcess created = 11 filas posibles; 4 escritas.
        $this->assertSame(['limite' => 4, 'omitidas' => 7], $datos);

        $this->assertSame(4, $this->filas()->whereNotIn('event', ['truncated'])->count());
    }

    /**
     * Al cerrar un lote que pasó el tope se deja un Log::warning con el total omitido.
     *
     * @return void
     */
    public function test_al_cerrar_un_lote_truncado_sale_un_warning_con_el_total_omitido()
    {
        config(['audit_log.max_filas_por_lote' => 2]);

        Log::spy();

        for ($i = 1; $i <= 5; $i++) {
            Provider::create(['name' => 'ZZ Proveedor tope log ' . $i, 'user_id' => $this->dueno->id]);
        }

        AuditLogRecorder::cerrar_marco(AuditContext::marco());

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje) {
                return strpos((string) $mensaje, 'pasó el tope de auditoría') !== false
                    && strpos((string) $mensaje, 'se omitieron 3 filas') !== false;
            })
            ->once();
    }

    /**
     * Los modelos de `modelos_sin_tope` (plata y stock) se escriben SIEMPRE, aunque el lote ya haya
     * pasado el tope; los demás se omiten. Es la corrección de la venta de 150+ renglones, que
     * perdía justo las últimas filas (Caja, CurrentAcount, Sale updated...).
     *
     * @return void
     */
    public function test_los_modelos_sin_tope_se_escriben_siempre_y_los_demas_se_omiten()
    {
        config(['audit_log.max_filas_por_lote' => 2]);

        // Cuatro proveedores: los dos primeros llenan el cupo, los otros dos se omiten.
        for ($i = 1; $i <= 4; $i++) {
            Provider::create(['name' => 'ZZ Proveedor sin tope ' . $i, 'user_id' => $this->dueno->id]);
        }

        $this->assertCount(2, $this->filas(Provider::class, 'created'));
        $this->assertCount(1, $this->filas(null, 'truncated'));

        // Ya pasado el tope: dos ventas (modelo sin tope) y un proveedor más (con tope).
        Sale::create([
            'user_id' => $this->dueno->id, 'client_id' => null, 'terminada' => 1, 'sub_total' => 100,
            'total' => 100, 'moneda_id' => 1, 'descuento' => 0,
        ]);
        $venta = Sale::create([
            'user_id' => $this->dueno->id, 'client_id' => null, 'terminada' => 1, 'sub_total' => 200,
            'total' => 200, 'moneda_id' => 1, 'descuento' => 0,
        ]);
        $venta->update(['total' => 250]);
        Provider::create(['name' => 'ZZ Proveedor sin tope 5', 'user_id' => $this->dueno->id]);

        $this->assertCount(2, $this->filas(Sale::class, 'created'), 'Las ventas se escriben aunque el lote ya pasó el tope.');
        $this->assertCount(1, $this->filas(Sale::class, 'updated'), 'Y sus cambios también.');
        $this->assertCount(2, $this->filas(Provider::class, 'created'), 'Los proveedores de después del tope se omiten.');

        // Al cierre, lo omitido son solo los tres proveedores; las ventas no cuentan como omitidas.
        AuditLogRecorder::cerrar_marco(AuditContext::marco());

        $this->assertSame(
            ['limite' => 2, 'omitidas' => 3],
            json_decode($this->filas(null, 'truncated')->first()->new_values, true)
        );
    }
}
