<?php

namespace Tests\Feature\ArticuloSlug;

use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Backfill de slugs de artículos: `articles:completar-slugs {--aplicar} {--user_id=}`.
 *
 * Misión `tienda-articulos-sin-slug` (26/11/2026). Defecto de origen: la tienda muestra "No pudimos
 * cargar este producto" cuando se entra a un artículo cuyo `slug` es NULL o '' (en el comercio de
 * pesca mayorista había 915 de 1.385 en esa situación). Este comando completa los slugs que faltan,
 * con la MISMA regla que el ABM (`ArticleHelper::slug`, único por dueño), sin tocar `updated_at`.
 *
 * Contrato de salida que fijan estos tests:
 *   - modo reporte (sin `--aplicar`): una línea `Pendientes de completar: N` y no escribe nada;
 *   - modo aplicar: una línea final `Completados: N · Sin nombre utilizable: M`.
 *
 * Los dueños A y B son usuarios nuevos y propios de cada test, así los conteos son exactos aunque el
 * fixture tenga artículos. Los nombres empiezan con `ZZ ` para no chocar con el fixture.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class CompletarSlugsDeArticulosTest extends EmpresaTestCase
{
    /**
     * Fecha fija y vieja de `updated_at` con la que se siembran los artículos, para poder probar que
     * el comando no la mueve.
     *
     * @var string
     */
    const UPDATED_AT_VIEJO = '2020-01-02 03:04:05';

    /**
     * Crea un dueño nuevo (owner_id null).
     *
     * @param string $etiqueta
     * @return \App\Models\User
     */
    protected function crear_dueno($etiqueta)
    {
        return User::forceCreate([
            'name'         => 'Comercio ' . $etiqueta,
            'company_name' => 'ZZ Comercio ' . $etiqueta,
            'email'        => 'slug-' . $etiqueta . '-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Crea un empleado de un dueño (owner_id con valor).
     *
     * @param \App\Models\User $dueno
     * @return \App\Models\User
     */
    protected function crear_empleado(User $dueno)
    {
        return User::forceCreate([
            'name'         => 'Empleado de ' . $dueno->id,
            'company_name' => $dueno->company_name,
            'email'        => 'slug-emp-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $dueno->id,
        ]);
    }

    /**
     * Siembra un artículo con el slug EXACTO que se pida (NULL, '' o un valor) y `updated_at` viejo.
     *
     * El slug y la fecha se fuerzan con una escritura directa a la tabla después del alta: así el
     * fixture no depende de que el modelo (o un observer de altas) complete el slug por su cuenta.
     *
     * @param \App\Models\User $dueno
     * @param string $nombre
     * @param string|null $slug
     * @return \App\Models\Article
     */
    protected function sembrar_articulo(User $dueno, $nombre, $slug)
    {
        $articulo = new Article();
        $articulo->user_id = $dueno->id;
        $articulo->name    = $nombre;
        $articulo->status  = 'active';
        $articulo->iva_id  = 2;
        $articulo->save();

        DB::table('articles')->where('id', $articulo->id)->update([
            'slug'       => $slug,
            'updated_at' => self::UPDATED_AT_VIEJO,
        ]);

        return $articulo;
    }

    /**
     * Slug guardado hoy en la base (siempre se relee).
     *
     * @param int $id
     * @return string|null
     */
    protected function slug_de($id)
    {
        return DB::table('articles')->where('id', $id)->value('slug');
    }

    /**
     * `updated_at` guardado hoy en la base, como texto.
     *
     * @param int $id
     * @return string
     */
    protected function updated_at_de($id)
    {
        return (string) DB::table('articles')->where('id', $id)->value('updated_at');
    }

    /**
     * Escenario de CA1: dueño A con E (slug propio), X (slug NULL) e Y (slug ''), todos con el mismo
     * nombre; dueño B con Z (slug NULL) y el mismo nombre.
     *
     * @return array [A, B, E, X, Y, Z]
     */
    protected function escenario_ca1()
    {
        $a = $this->crear_dueno('A');
        $b = $this->crear_dueno('B');

        $nombre = 'ZZ Reel Surfish Flash 2000';

        $e = $this->sembrar_articulo($a, $nombre, 'zz-reel-surfish-flash-2000');
        $x = $this->sembrar_articulo($a, $nombre, null);
        $y = $this->sembrar_articulo($a, $nombre, '');
        $z = $this->sembrar_articulo($b, $nombre, null);

        return [$a, $b, $e, $x, $y, $z];
    }

    /**
     * CA1: el backfill completa los slugs vacíos (NULL y ''), únicos por dueño, sin mover `updated_at`
     * y sin tocar el que ya tenía slug. Se corre SIN `--user_id` (todos los dueños, como en producción).
     *
     * @return void
     */
    public function test_completa_slugs_vacios_por_dueno_sin_tocar_updated_at()
    {
        list($a, $b, $e, $x, $y, $z) = $this->escenario_ca1();

        $codigo = Artisan::call('articles:completar-slugs', ['--aplicar' => true]);

        $this->assertSame(0, $codigo, Artisan::output());

        $this->assertSame('zz-reel-surfish-flash-2000-1', $this->slug_de($x->id), 'X (NULL) recibe el primer sufijo libre.');
        $this->assertSame('zz-reel-surfish-flash-2000-2', $this->slug_de($y->id), 'Y (vacío) recibe el siguiente.');
        $this->assertSame('zz-reel-surfish-flash-2000', $this->slug_de($z->id), 'Z es de otro dueño: la unicidad es por dueño, queda sin sufijo.');
        $this->assertSame('zz-reel-surfish-flash-2000', $this->slug_de($e->id), 'E ya tenía slug y no se toca.');

        $this->assertSame(self::UPDATED_AT_VIEJO, $this->updated_at_de($x->id), 'No se mueve updated_at de X.');
        $this->assertSame(self::UPDATED_AT_VIEJO, $this->updated_at_de($y->id), 'No se mueve updated_at de Y.');
        $this->assertSame(self::UPDATED_AT_VIEJO, $this->updated_at_de($z->id), 'No se mueve updated_at de Z.');
    }

    /**
     * CA2 (solo lectura): sin `--aplicar` no cambia ningún slug y la salida informa la cantidad.
     *
     * @return void
     */
    public function test_sin_aplicar_no_escribe()
    {
        list($a, $b, $e, $x, $y, $z) = $this->escenario_ca1();

        $codigo = Artisan::call('articles:completar-slugs', ['--user_id' => $a->id]);
        $salida = Artisan::output();

        $this->assertSame(0, $codigo, $salida);
        $this->assertNull($this->slug_de($x->id), 'X sigue NULL en modo reporte.');
        $this->assertSame('', $this->slug_de($y->id), 'Y sigue vacío en modo reporte.');
        $this->assertNull($this->slug_de($z->id), 'Z sigue NULL en modo reporte.');
        $this->assertStringContainsString('Pendientes de completar: 2', $salida);
    }

    /**
     * CA2 (idempotencia): una segunda corrida con `--aplicar` no cambia nada e informa 0.
     *
     * @return void
     */
    public function test_segunda_corrida_no_cambia_nada()
    {
        list($a, $b, $e, $x, $y, $z) = $this->escenario_ca1();

        Artisan::call('articles:completar-slugs', ['--aplicar' => true, '--user_id' => $a->id]);
        $slug_x = $this->slug_de($x->id);
        $slug_y = $this->slug_de($y->id);
        $this->assertNotEmpty($slug_x, 'La primera corrida tiene que completar X.');

        $codigo = Artisan::call('articles:completar-slugs', ['--aplicar' => true, '--user_id' => $a->id]);
        $salida = Artisan::output();

        $this->assertSame(0, $codigo, $salida);
        $this->assertSame($slug_x, $this->slug_de($x->id));
        $this->assertSame($slug_y, $this->slug_de($y->id));
        $this->assertStringContainsString('Completados: 0', $salida);
    }

    /**
     * CA3: `--user_id=<A>` completa sólo a A; el artículo de B queda NULL.
     *
     * @return void
     */
    public function test_user_id_limita_al_dueno()
    {
        list($a, $b, $e, $x, $y, $z) = $this->escenario_ca1();

        $codigo = Artisan::call('articles:completar-slugs', ['--aplicar' => true, '--user_id' => $a->id]);
        $salida = Artisan::output();

        $this->assertSame(0, $codigo, $salida);
        $this->assertSame('zz-reel-surfish-flash-2000-1', $this->slug_de($x->id));
        $this->assertSame('zz-reel-surfish-flash-2000-2', $this->slug_de($y->id));
        $this->assertNull($this->slug_de($z->id), 'Z es de B: con --user_id=A no se toca.');
        $this->assertStringContainsString('Completados: 2', $salida);
    }

    /**
     * Valores inválidos de `--user_id`: pelado-no-numérico, vacío, id inexistente (se agrega el id de
     * un empleado dentro del test, porque necesita la base).
     *
     * @return array
     */
    public function user_id_invalidos_provider()
    {
        return [
            'no numerico'  => ['abc'],
            'vacio'        => [''],
            'cero'         => ['0'],
            'inexistente'  => ['999999999'],
            'empleado'     => ['__EMPLEADO__'],
        ];
    }

    /**
     * CA3: un `--user_id` inválido (no numérico, vacío, cero, inexistente o de un empleado) corta con
     * exit 1 y no cambia ningún slug, aunque se pida `--aplicar`.
     *
     * @dataProvider user_id_invalidos_provider
     * @param string $valor
     * @return void
     */
    public function test_user_id_invalido_o_empleado_corta_sin_escribir($valor)
    {
        list($a, $b, $e, $x, $y, $z) = $this->escenario_ca1();

        if ($valor === '__EMPLEADO__') {
            $valor = (string) $this->crear_empleado($a)->id;
        }

        $codigo = Artisan::call('articles:completar-slugs', ['--aplicar' => true, '--user_id' => $valor]);

        $this->assertSame(1, $codigo, 'Un --user_id inválido tiene que terminar con exit 1. Salida: ' . Artisan::output());
        $this->assertNull($this->slug_de($x->id));
        $this->assertSame('', $this->slug_de($y->id));
        $this->assertNull($this->slug_de($z->id));
    }

    /**
     * CA3: un artículo borrado (soft delete) sin slug no se toca, y uno con nombre `***` (sin slug
     * posible) queda NULL y se cuenta como "sin nombre utilizable"; el resto se completa.
     *
     * @return void
     */
    public function test_no_toca_borrados_ni_nombres_sin_slug_posible()
    {
        $a = $this->crear_dueno('A');

        $borrado = $this->sembrar_articulo($a, 'ZZ Articulo Borrado', null);
        $borrado->delete();
        $simbolos = $this->sembrar_articulo($a, '***', null);
        $normal   = $this->sembrar_articulo($a, 'ZZ Articulo Normal', null);

        $codigo = Artisan::call('articles:completar-slugs', ['--aplicar' => true, '--user_id' => $a->id]);
        $salida = Artisan::output();

        $this->assertSame(0, $codigo, $salida);
        $this->assertNull($this->slug_de($borrado->id), 'El borrado no se toca.');
        $this->assertNull($this->slug_de($simbolos->id), 'Un nombre sin slug posible queda NULL.');
        $this->assertSame('zz-articulo-normal', $this->slug_de($normal->id));
        $this->assertStringContainsString('Completados: 1', $salida);
        $this->assertStringContainsString('Sin nombre utilizable: 1', $salida);
    }
}
