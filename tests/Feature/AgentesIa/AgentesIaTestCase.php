<?php

namespace Tests\Feature\AgentesIa;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Database\Seeders\ExtencionAgentesIaSeeder;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\EmpresaTestCase;

/**
 * Base de los tests de la extensión única «Agentes de IA» (misión agentes-ia-extension-unica, 22/10/2026).
 *
 * Trabaja sobre el comercio del fixture de ferretería (el usuario autenticado por EmpresaTestCase) y le
 * da o le saca `agentes_ia` a mano, creando la fila del catálogo con el seeder real si la base del slot
 * todavía no la tiene sembrada.
 *
 * 🔴 Nada de esto sale a la red: `Http::fake()` y las claves de las IA en null. Las colas corren en sync
 * (phpunit.xml), así que los tests que despachan jobs los fakean con `Queue::fake()` por su cuenta.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types, promoción de
 * constructor, readonly, enum ni #[...].
 */
abstract class AgentesIaTestCase extends EmpresaTestCase
{
    /** Slug de la extensión única. */
    const SLUG = 'agentes_ia';

    /** La clave de admin que tiene cargada la instancia en los tests de las puertas de admin-sync. */
    const CLAVE_ADMIN = 'clave-admin-agentes-ia';

    /**
     * El comercio del fixture, SIN la extensión: cada test arranca apagado y se la da si la necesita.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 200)]);
        Notification::fake();

        config([
            'services.anthropic.api_key' => null,
            'services.openai.api_key'    => null,
            'services.deepseek.api_key'  => null,
            'broadcasting.default'       => 'null',
            'services.admin_api.api_key' => self::CLAVE_ADMIN,
            'services.admin_api.require_api_key' => null,
        ]);

        /* La instancia es la de este comercio (la base de testing tiene varios dueños). */
        config(['app.USER_ID' => $this->comercio()->id]);

        $this->quitar_extension($this->comercio());
    }

    /**
     * El dueño del fixture (el usuario que EmpresaTestCase deja autenticado).
     *
     * @return \App\Models\User
     */
    protected function comercio()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * La fila de `agentes_ia` en el catálogo; la siembra con el seeder real si falta.
     *
     * @return \App\Models\ExtencionEmpresa
     */
    protected function fila_de_agentes_ia()
    {
        $fila = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (is_null($fila)) {
            (new ExtencionAgentesIaSeeder())->run();
            $fila = ExtencionEmpresa::where('slug', self::SLUG)->first();
        }

        return $fila;
    }

    /**
     * Le da la extensión a un usuario (sin duplicar el vínculo).
     *
     * @param  \App\Models\User|null  $user  null = el comercio del fixture.
     * @return void
     */
    protected function dar_extension($user = null)
    {
        $user = $user ?: $this->comercio();

        $user->extencions()->syncWithoutDetaching([$this->fila_de_agentes_ia()->id]);
        $user->load('extencions');
        $this->actingAs($user, 'web');
    }

    /**
     * Le saca la extensión a un usuario, aunque la base del slot ya se la tenga asignada.
     *
     * @param  \App\Models\User|null  $user  null = el comercio del fixture.
     * @return void
     */
    protected function quitar_extension($user = null)
    {
        $user = $user ?: $this->comercio();

        $user->extencions()->detach($this->fila_de_agentes_ia()->id);
        $user->load('extencions');
        $this->actingAs($user, 'web');
    }

    /**
     * true si la respuesta es el 403 del gate de la extensión (y no otro 403, como el de «solo el dueño»).
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return bool
     */
    protected function es_el_403_de_la_extension($respuesta)
    {
        return $respuesta->getStatusCode() === 403
            && strpos((string) $respuesta->json('message'), 'Extensión requerida: ' . self::SLUG) !== false;
    }

    /**
     * Cabeceras de admin con la clave correcta.
     *
     * @return array
     */
    protected function cabeceras_de_admin()
    {
        return ['X-Admin-Api-Key' => self::CLAVE_ADMIN];
    }
}
