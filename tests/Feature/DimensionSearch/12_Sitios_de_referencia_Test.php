<?php

namespace Tests\Feature\DimensionSearch;

use App\Http\Controllers\Helpers\DimensionReferenceSiteHelper;
use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Models\DimensionReferenceSite;
use App\Models\DimensionSearchRun;
use Illuminate\Support\Facades\Queue;

/**
 * Los sitios de referencia de la búsqueda masiva de peso y medidas (misión medidas-sitios-de-referencia,
 * 11/11/2026): la lista por comercio (normalizada, hasta 8), los endpoints GET / PUT (editar solo el acceso
 * maestro), la foto que guarda cada corrida, la previa y el comando `medidas:buscar --sitios=`.
 */
class Sitios_de_referencia_Test extends DimensionSearchTestCase
{
    /**
     * @group dimension-search
     * @test
     */
    public function normaliza_urls_y_dominios_y_descarta_vacios_y_repetidos()
    {
        $this->assertSame('obi.de', DimensionReferenceSiteHelper::normalizar('https://www.obi.de/gartenschlauch/p/1234?x=1#y'));
        $this->assertSame('obi.de', DimensionReferenceSiteHelper::normalizar('  OBI.DE  '));
        $this->assertSame('obi.de', DimensionReferenceSiteHelper::normalizar('site:obi.de'));
        $this->assertSame('verdefacile.eu', DimensionReferenceSiteHelper::normalizar('www.verdefacile.eu/it/'));
        $this->assertSame('carbydoctor.com.au', DimensionReferenceSiteHelper::normalizar('http://carbydoctor.com.au:8080/x'));
        $this->assertSame('tienda.ejemplo.com.ar', DimensionReferenceSiteHelper::normalizar('//tienda.ejemplo.com.ar/'));
        $this->assertSame('', DimensionReferenceSiteHelper::normalizar('   '));
        $this->assertSame('', DimensionReferenceSiteHelper::normalizar(null));

        // Sin punto, con espacios, o que no es texto: no es un dominio.
        $this->assertNull(DimensionReferenceSiteHelper::normalizar('localhost'));
        $this->assertNull(DimensionReferenceSiteHelper::normalizar('obi de'));
        $this->assertNull(DimensionReferenceSiteHelper::normalizar('-malo-.com'));
        $this->assertNull(DimensionReferenceSiteHelper::normalizar(['obi.de']));

        $lista = DimensionReferenceSiteHelper::normalizar_lista(['https://www.obi.de/p/1', 'obi.de', '', 'meteorpiston.com']);

        $this->assertTrue($lista['ok']);
        $this->assertSame(['obi.de', 'meteorpiston.com'], $lista['sitios']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_get_trae_la_lista_del_comercio_y_no_la_de_otro()
    {
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['obi.de', 'verdefacile.eu']);
        DimensionReferenceSiteHelper::guardar($this->otro_comercio()->id, ['ajeno.com']);

        // Leer no exige el acceso maestro: lo ve quien ve la solapa.
        $r = $this->getJson('api/dimension-search-reference-sites');

        $r->assertStatus(200);
        $this->assertSame(['obi.de', 'verdefacile.eu'], $r->json('sitios'));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_put_reemplaza_la_lista_normalizada_y_solo_lo_puede_hacer_el_acceso_maestro()
    {
        // Sin acceso maestro: 403 y no se toca nada.
        $this->putJson('api/dimension-search-reference-sites', ['sitios' => ['obi.de']])->assertStatus(403);
        $this->assertSame(0, DimensionReferenceSite::where('user_id', $this->owner->id)->count());

        $this->actuar_como($this->owner, true);

        $r = $this->putJson('api/dimension-search-reference-sites', ['sitios' => [
            'https://www.obi.de/gartenschlauch/p/1234',
            'verdefacile.eu',
            'https://meteorpiston.com/',
            'carbydoctor.com.au',
            'obi.de',
            '',
        ]]);

        $r->assertStatus(200);
        $this->assertSame(['obi.de', 'verdefacile.eu', 'meteorpiston.com', 'carbydoctor.com.au'], $r->json('sitios'));
        $this->assertSame(['obi.de', 'verdefacile.eu', 'meteorpiston.com', 'carbydoctor.com.au'], DimensionReferenceSiteHelper::listar($this->owner->id));

        // Reemplaza la lista ENTERA (no suma).
        $r = $this->putJson('api/dimension-search-reference-sites', ['sitios' => ['meteorpiston.com']]);
        $r->assertStatus(200);
        $this->assertSame(['meteorpiston.com'], DimensionReferenceSiteHelper::listar($this->owner->id));

        // Una lista vacía la borra.
        $this->putJson('api/dimension-search-reference-sites', ['sitios' => []])->assertStatus(200)->assertJson(['sitios' => []]);
        $this->assertSame([], DimensionReferenceSiteHelper::listar($this->owner->id));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_put_rechaza_un_dominio_invalido_o_mas_de_ocho_sin_tocar_la_lista()
    {
        $this->actuar_como($this->owner, true);
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['obi.de']);

        $r = $this->putJson('api/dimension-search-reference-sites', ['sitios' => ['verdefacile.eu', 'no es un sitio']]);

        $r->assertStatus(422);
        $this->assertStringContainsString('no es un sitio', $r->json('message'));
        $this->assertNotEmpty($r->json('errors'));
        $this->assertSame(['obi.de'], DimensionReferenceSiteHelper::listar($this->owner->id), 'Con una entrada mala no se guarda ninguna.');

        $nueve = [];

        for ($i = 1; $i <= 9; $i++) {
            $nueve[] = 'sitio'.$i.'.com';
        }

        $r = $this->putJson('api/dimension-search-reference-sites', ['sitios' => $nueve]);

        $r->assertStatus(422);
        $this->assertStringContainsString('hasta 8', $r->json('message'));
        $this->assertSame(['obi.de'], DimensionReferenceSiteHelper::listar($this->owner->id));

        // Ocho sí (y los repetidos no cuentan para el tope).
        $ocho = array_merge(array_slice($nueve, 0, 8), ['sitio1.com', 'https://www.sitio2.com/']);
        $this->putJson('api/dimension-search-reference-sites', ['sitios' => $ocho])->assertStatus(200);
        $this->assertCount(8, DimensionReferenceSiteHelper::listar($this->owner->id));

        // Sin la lista: 422.
        $this->putJson('api/dimension-search-reference-sites', ['sitios' => 'obi.de'])->assertStatus(422);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function la_corrida_del_catalogo_guarda_la_foto_de_los_sitios_y_la_previa_los_muestra()
    {
        Queue::fake();
        $this->actuar_como($this->owner, true);

        $this->nuevo_articulo('MANGUERA GARDENA 13MM', null);

        DimensionReferenceSiteHelper::guardar($this->owner->id, ['obi.de', 'verdefacile.eu']);

        $previa = $this->getJson('api/dimension-search-runs/catalogo/previa');
        $previa->assertStatus(200);
        $this->assertSame(['obi.de', 'verdefacile.eu'], $previa->json('sitios_de_referencia'));

        $r = $this->postJson('api/dimension-search-runs/catalogo', []);
        $r->assertStatus(201);
        $this->assertSame(['obi.de', 'verdefacile.eu'], $r->json('sitios_de_referencia'));

        $run = DimensionSearchRun::findOrFail($r->json('id'));
        $this->assertSame(['obi.de', 'verdefacile.eu'], $run->sitios_de_referencia);

        // Es una foto: cambiar la lista después no cambia la corrida.
        DimensionReferenceSiteHelper::guardar($this->owner->id, ['meteorpiston.com']);
        $this->assertSame(['obi.de', 'verdefacile.eu'], $this->getJson('api/dimension-search-runs/'.$run->id)->json('sitios_de_referencia'));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function una_corrida_sin_sitios_los_guarda_en_null_y_el_payload_devuelve_lista_vacia()
    {
        $run = $this->corrida([$this->nuevo_articulo('MANGUERA GARDENA 13MM')]);

        $this->assertNull($run->fresh()->sitios_de_referencia);
        $this->assertSame([], DimensionSearchRunHelper::payload_de_corrida($run->fresh())['sitios_de_referencia']);
        $this->assertSame([], $this->getJson('api/dimension-search-runs/'.$run->id)->json('sitios_de_referencia'));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_comando_guarda_los_sitios_con_la_opcion_y_el_dry_run_solo_los_muestra()
    {
        Queue::fake();
        $anterior = config('app.USER_ID');
        config(['app.USER_ID' => $this->owner->id]);

        try {
            $this->nuevo_articulo('MANGUERA GARDENA 13MM', null);

            // Dry-run: se muestran normalizados y no se guardan.
            $this->artisan('medidas:buscar', ['--dry-run' => true, '--sitios' => 'https://www.obi.de/p/1, verdefacile.eu'])
                ->expectsOutput('Sitios de referencia (se buscan primero):   obi.de, verdefacile.eu')
                ->assertExitCode(0);

            $this->assertSame([], DimensionReferenceSiteHelper::listar($this->owner->id));
            $this->assertSame(0, DimensionSearchRun::where('user_id', $this->owner->id)->count());

            // Un sitio inválido: error y no se lanza nada.
            $this->artisan('medidas:buscar', ['--sitios' => 'obi.de,no vale'])->assertExitCode(1);
            $this->assertSame([], DimensionReferenceSiteHelper::listar($this->owner->id));

            // De verdad: guarda la lista y la corrida sale con la foto.
            $this->artisan('medidas:buscar', ['--sitios' => 'obi.de,meteorpiston.com'])->assertExitCode(0);

            $this->assertSame(['obi.de', 'meteorpiston.com'], DimensionReferenceSiteHelper::listar($this->owner->id));
            $run = DimensionSearchRun::where('user_id', $this->owner->id)->firstOrFail();
            $this->assertSame(['obi.de', 'meteorpiston.com'], $run->sitios_de_referencia);

            // `--sitios=` vacío no toca la lista.
            $this->artisan('medidas:buscar', ['--dry-run' => true, '--sitios' => ''])
                ->expectsOutput('Sitios de referencia (se buscan primero):   obi.de, meteorpiston.com')
                ->assertExitCode(0);
            $this->assertSame(['obi.de', 'meteorpiston.com'], DimensionReferenceSiteHelper::listar($this->owner->id));
        } finally {
            config(['app.USER_ID' => $anterior]);
        }
    }
}
