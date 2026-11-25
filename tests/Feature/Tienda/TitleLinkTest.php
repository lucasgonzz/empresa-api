<?php

namespace Tests\Feature\Tienda;

use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TitleLinkTest extends TestCase
{
    use DatabaseTransactions;

    private function banner()
    {
        Auth::forgetGuards();
        $this->actingAs(User::findOrFail(500), 'web');
        return Title::create(['user_id' => 500, 'header' => 'Anterior', 'link' => '/anterior']);
    }

    public function validos()
    {
        return [
            ['https://example.test/ofertas?q=1#promo', 'https://example.test/ofertas?q=1#promo'],
            ['http://example.test/', 'http://example.test/'],
            ['/categorias/1?q=1', '/categorias/1?q=1'],
            ['categorias/1', '/categorias/1'],
            ['  /ofertas  ', '/ofertas'],
            ['', null], [null, null],
        ];
    }

    /** @dataProvider validos */
    public function test_alta_y_actualizacion_guardan_link_con_el_banner($valor, $esperado)
    {
        $banner = $this->banner();
        $this->putJson('api/title/'.$banner->id, ['header' => 'Nuevo', 'link' => $valor])
            ->assertOk()->assertJsonPath('model.link', $esperado);
        $this->assertSame($esperado, $banner->fresh()->link);
        $this->assertSame('Nuevo', $banner->fresh()->header);
        $this->putJson('api/title/'.$banner->id, ['header' => 'Sin link en payload'])->assertOk();
        $this->assertSame($esperado, $banner->fresh()->link);
        $alta = $this->postJson('api/title', ['header' => 'Alta', 'link' => $valor]);
        $alta->assertCreated()->assertJsonPath('model.link', $esperado);
        $this->assertSame($esperado, Title::findOrFail($alta->json('model.id'))->link);
    }

    public function invalidos()
    {
        return array_map(function ($valor) { return [$valor]; }, [
            [], ['x'], (object) ['url' => '/x'], true, 123,
            'javascript:alert(1)', 'data:text/html,x', 'ftp://example.test/x',
            '//example.test/x', '/\\example.test', 'https://user:pass@example.test/x',
            'https://user@example.test', "https://example.test/\nx", "/x\tq", '/%2fexample.test',
            '/%5cexample.test', '/%252fexample.test', '/foo/../bar', 'https://example.test/\\x',
            'https://example.test/%0ax', str_repeat('x', 501),
        ]);
    }

    /** @dataProvider invalidos */
    public function test_link_invalido_no_crea_ni_modifica_otros_campos($valor)
    {
        $banner = $this->banner();
        $foto = $banner->fresh()->toArray();
        $cantidad = Title::count();
        $this->postJson('api/title', ['header' => 'No crear', 'link' => $valor])
            ->assertStatus(422)->assertJsonValidationErrors('link');
        $this->assertSame($cantidad, Title::count());
        $this->putJson('api/title/'.$banner->id, ['header' => 'No guardar', 'link' => $valor])
            ->assertStatus(422)->assertJsonValidationErrors('link');
        $this->assertEquals($foto, $banner->fresh()->toArray());
    }

    public function test_tenencia_y_permisos_previos_del_banner_se_conservan()
    {
        $banner = $this->banner();
        $ajeno = Title::create(['user_id' => 501, 'header' => 'Ajeno', 'link' => '/ajeno']);
        $this->putJson('api/title/'.$ajeno->id, ['header' => 'Intruso', 'link' => []])->assertNotFound();
        $this->assertSame('/ajeno', $ajeno->fresh()->link);
        $empleado = User::create(['owner_id' => 500, 'name' => 'Empleado banner U6', 'email' => 'u6-banner@example.test', 'password' => 'sintetica']);
        Auth::forgetGuards();
        $this->actingAs($empleado, 'web');
        // Title ya permite operar al empleado del dueño; no agrega permisos nuevos.
        $this->putJson('api/title/'.$banner->id, ['header' => 'Empleado', 'link' => '/empleado'])->assertOk();
        $this->assertSame('/empleado', $banner->fresh()->link);
        $this->putJson('api/title/'.$ajeno->id, ['header' => 'Intruso', 'link' => '/x'])->assertNotFound();
    }
}
