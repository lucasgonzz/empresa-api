<?php

namespace Tests\Feature\AgentesIa;

use App\Models\ExtencionEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Misión agentes-ia-extension-unica (22/10/2026) — la puerta con la que el admin lee y prende/apaga la extensión
 * de un cliente: `GET|PUT api/admin-sync/agentes-ia`.
 *
 * Lo que protege:
 *
 *  - `admin.api.key:siempre`: sin `X-Admin-Api-Key` (o con una que no es), **401**; y una instancia SIN clave
 *    cargada también contesta 401, porque el PUT escribe un permiso del comercio y «no hay clave» no puede
 *    significar «pasá».
 *  - GET → `{ok, activa}`; PUT `{activa}` → `{ok, activa}`, **idempotente** (prender dos veces deja UN vínculo).
 *  - Apagar toca SOLO `agentes_ia`: las demás extensiones del dueño quedan.
 *  - Sin dueño resoluble → **409**. Body mal formado → 422.
 *  - Una instancia que recibió el código pero no el seeder (sin la fila en el catálogo) igual puede prenderla.
 */
class Puerta_del_admin_Test extends AgentesIaTestCase
{
    /**
     * Cantidad de vínculos dueño ↔ agentes_ia (el pivote no tiene unique: se cuenta a mano).
     *
     * @return int
     */
    protected function vinculos()
    {
        return DB::table('extencion_empresa_user')
            ->where('user_id', $this->comercio()->id)
            ->where('extencion_empresa_id', $this->fila_de_agentes_ia()->id)
            ->count();
    }

    /**
     * @test
     * @return void
     */
    public function sin_la_clave_del_admin_es_401_en_el_get_y_en_el_put()
    {
        $this->getJson('api/admin-sync/agentes-ia')->assertStatus(401);
        $this->putJson('api/admin-sync/agentes-ia', ['activa' => true])->assertStatus(401);

        $this->getJson('api/admin-sync/agentes-ia', ['X-Admin-Api-Key' => 'otra-clave'])->assertStatus(401);
        $this->putJson('api/admin-sync/agentes-ia', ['activa' => true], ['X-Admin-Api-Key' => 'otra-clave'])->assertStatus(401);

        $this->assertSame(0, $this->vinculos(), 'Un PUT sin clave válida prendió la extensión.');
    }

    /**
     * Una instancia sin ADMIN_API_INBOUND_KEY cargada también rechaza (modo `siempre`), aunque el flag de
     * require_api_key esté apagado: la puerta ESCRIBE un permiso.
     *
     * @test
     * @return void
     */
    public function una_instancia_sin_clave_cargada_tambien_contesta_401()
    {
        config(['services.admin_api.api_key' => null, 'services.admin_api.require_api_key' => false]);

        $this->getJson('api/admin-sync/agentes-ia')->assertStatus(401);
        $this->putJson('api/admin-sync/agentes-ia', ['activa' => true], ['X-Admin-Api-Key' => 'cualquiera'])->assertStatus(401);

        $this->assertSame(0, $this->vinculos());
    }

    /**
     * @test
     * @return void
     */
    public function el_get_dice_si_el_comercio_la_tiene()
    {
        $this->getJson('api/admin-sync/agentes-ia', $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertExactJson(['ok' => true, 'activa' => false]);

        $this->dar_extension();

        $this->getJson('api/admin-sync/agentes-ia', $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertExactJson(['ok' => true, 'activa' => true]);
    }

    /**
     * Prender es idempotente: dos PUT seguidos dejan UN solo vínculo y la misma respuesta.
     *
     * @test
     * @return void
     */
    public function el_put_prende_y_es_idempotente()
    {
        $this->putJson('api/admin-sync/agentes-ia', ['activa' => true], $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertExactJson(['ok' => true, 'activa' => true]);

        $this->putJson('api/admin-sync/agentes-ia', ['activa' => true], $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertExactJson(['ok' => true, 'activa' => true]);

        $this->assertSame(1, $this->vinculos(), 'Prender dos veces duplicó el vínculo.');
        $this->assertTrue(\App\Http\Controllers\Helpers\UserHelper::hasExtencion('agentes_ia', $this->comercio()->fresh()));
    }

    /**
     * Apagar saca SOLO agentes_ia y deja las demás extensiones del dueño. Apagar una que no está no falla.
     *
     * @test
     * @return void
     */
    public function el_put_apaga_solo_agentes_ia_y_apagar_dos_veces_no_falla()
    {
        $otra = ExtencionEmpresa::forceCreate(['slug' => 'zz_testigo_agentes_ia', 'name' => 'Testigo']);

        $this->comercio()->extencions()->attach($otra->id);
        $this->dar_extension();

        $this->putJson('api/admin-sync/agentes-ia', ['activa' => false], $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertExactJson(['ok' => true, 'activa' => false]);

        $this->assertSame(0, $this->vinculos());
        $this->assertSame(1, DB::table('extencion_empresa_user')
            ->where('user_id', $this->comercio()->id)
            ->where('extencion_empresa_id', $otra->id)
            ->count(), 'Apagar agentes_ia le sacó otra extensión al dueño.');

        $this->putJson('api/admin-sync/agentes-ia', ['activa' => false], $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertExactJson(['ok' => true, 'activa' => false]);
    }

    /**
     * Sin dueño resoluble (USER_ID que no existe): 409 en el GET y en el PUT, sin tocar nada.
     *
     * @test
     * @return void
     */
    public function sin_dueno_resoluble_es_409()
    {
        config(['app.USER_ID' => 999999999]);

        $this->getJson('api/admin-sync/agentes-ia', $this->cabeceras_de_admin())
             ->assertStatus(409)
             ->assertJsonStructure(['message']);

        $this->putJson('api/admin-sync/agentes-ia', ['activa' => true], $this->cabeceras_de_admin())
             ->assertStatus(409);

        $this->assertSame(0, $this->vinculos());
    }

    /**
     * El body tiene que traer `activa` booleano: si no, 422 y no se toca nada.
     *
     * @test
     * @return void
     */
    public function un_body_mal_formado_es_422()
    {
        $this->putJson('api/admin-sync/agentes-ia', [], $this->cabeceras_de_admin())->assertStatus(422);
        $this->putJson('api/admin-sync/agentes-ia', ['activa' => 'tal vez'], $this->cabeceras_de_admin())->assertStatus(422);

        $this->assertSame(0, $this->vinculos());
    }

    /**
     * La instancia que recibió el código pero no el seeder del despliegue (sin la fila en el catálogo) igual
     * puede prenderla: el PUT siembra la fila (idempotente) y sigue.
     *
     * @test
     * @return void
     */
    public function prender_funciona_aunque_falte_la_fila_del_catalogo()
    {
        ExtencionEmpresa::where('slug', 'agentes_ia')->delete();

        $this->putJson('api/admin-sync/agentes-ia', ['activa' => true], $this->cabeceras_de_admin())
             ->assertStatus(200)
             ->assertExactJson(['ok' => true, 'activa' => true]);

        $this->assertSame(1, ExtencionEmpresa::where('slug', 'agentes_ia')->count());
    }

    /**
     * Prender desde la puerta del admin habilita de verdad las rutas de IA del comercio: el contrato entre
     * proyectos termina en el 403 de las rutas.
     *
     * @test
     * @return void
     */
    public function prender_por_la_puerta_abre_las_rutas_de_ia_y_apagar_las_cierra()
    {
        $this->putJson('api/admin-sync/agentes-ia', ['activa' => true], $this->cabeceras_de_admin())->assertStatus(200);

        $this->actingAs($this->comercio()->fresh(), 'web');
        $this->assertFalse($this->es_el_403_de_la_extension($this->getJson('api/whatsapp-templates')));

        $this->putJson('api/admin-sync/agentes-ia', ['activa' => false], $this->cabeceras_de_admin())->assertStatus(200);

        $this->actingAs($this->comercio()->fresh(), 'web');
        $this->assertTrue($this->es_el_403_de_la_extension($this->getJson('api/whatsapp-templates')));
    }
}
