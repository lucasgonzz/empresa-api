<?php

namespace Tests\Feature\Balanzas;

use App\Models\Balanza;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión balanzas-configurables (3/10/2026) — el comando `balanzas:migrar-desde-extensiones`, que
 * corre en el despliegue y pasa a cada comercio de las extensiones viejas a `tickets_de_balanza`.
 *
 * Se corre de verdad, con `Artisan::call`, sobre dueños creados en el test (la transacción de
 * EmpresaTestCase lo revierte todo, también lo que el comando le haga a los dueños del fixture).
 *
 * El artículo de la extensión vieja es el que estaba hardcodeado en `check_balanza()`: 60 en
 * local y 6346 en cualquier otro entorno (en testing, 6346). En la base del slot puede existir o
 * no, y ser de cualquiera: `articulo_legacy()` lo deja como cada test lo necesita, adentro de la
 * transacción, en los dos casos.
 *
 * Lo que se protege:
 *   - Las cuatro reglas por dueño (PLU; las dos -> PLU, caso La Martina; solo importe -> balanzas
 *     con la balanza '22' si el artículo es suyo, caso Panchito; sin balanza si no lo es).
 *   - Que es idempotente y respeta lo que el dueño ya eligió.
 *   - Que `--simular` no escribe nada.
 *   - 🔴 Que NO borra las filas de las extensiones: el código viejo las sigue leyendo en el frente
 *     activo mientras dura el despliegue.
 *
 * @group balanzas
 */
class Comando_migrar_desde_extensiones_Test extends EmpresaTestCase
{
    const COMANDO = 'balanzas:migrar-desde-extensiones';

    /** Salida del último `correr()`. */
    protected $salida = '';

    // ─────────────────────────────────────────────────────────────────────────────
    //  Ayudas
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * El id del artículo de la extensión vieja, escrito a mano (no leído del helper): es lo que
     * el comando tiene que respetar.
     *
     * @return int
     */
    protected function id_legacy()
    {
        return config('app.APP_ENV') == 'local' ? 60 : 6346;
    }

    /**
     * Un dueño nuevo, con las extensiones viejas que se le pidan y, opcionalmente, un
     * `tickets_de_balanza` ya cargado.
     *
     * @param  string       $nombre
     * @param  array        $slugs
     * @param  string|null  $modo
     * @return \App\Models\User
     */
    protected function dueno($nombre, $slugs = [], $modo = null)
    {
        $user = User::create([
            'name'     => 'zz Balanzas comando ' . $nombre,
            'email'    => 'balanzas-comando-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        foreach ($slugs as $slug) {

            $extension = ExtencionEmpresa::where('slug', $slug)->first();

            if (is_null($extension)) {
                $extension = ExtencionEmpresa::forceCreate(['name' => $slug, 'slug' => $slug]);
            }

            $user->extencions()->attach($extension->id);
        }

        if (!is_null($modo)) {
            DB::table('users')->where('id', $user->id)->update(['tickets_de_balanza' => $modo]);
        }

        return $user;
    }

    /**
     * Deja el artículo de la extensión vieja como de `$owner_id` (y borrado o no), exista o no en
     * la base. Por query builder: es un fixture y no tiene por qué disparar el observer.
     *
     * @param  int   $owner_id
     * @param  bool  $borrado
     * @return void
     */
    protected function articulo_legacy($owner_id, $borrado = false)
    {
        $datos = [
            'user_id'    => $owner_id,
            'name'       => 'Carniceria (articulo de la extension vieja, test)',
            'deleted_at' => $borrado ? now() : null,
        ];

        if (DB::table('articles')->where('id', $this->id_legacy())->exists()) {

            DB::table('articles')->where('id', $this->id_legacy())->update($datos);
            return;
        }

        DB::table('articles')->insert(array_merge($datos, [
            'id'         => $this->id_legacy(),
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * Corre el comando y guarda la salida. Tiene que terminar con exit 0.
     *
     * @param  bool  $simular
     * @return void
     */
    protected function correr($simular = false)
    {
        $exit = Artisan::call(self::COMANDO, $simular ? ['--simular' => true] : []);

        $this->salida = Artisan::output();

        $this->assertSame(0, $exit, 'El comando tiene que terminar bien. Salida: ' . $this->salida);
    }

    /**
     * `tickets_de_balanza` del dueño, leído directo de la tabla.
     *
     * @param  \App\Models\User  $user
     * @return string|null
     */
    protected function modo_de($user)
    {
        return DB::table('users')->where('id', $user->id)->value('tickets_de_balanza');
    }

    /**
     * Las balanzas del dueño.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Support\Collection
     */
    protected function balanzas_de($user)
    {
        return Balanza::where('user_id', $user->id)->orderBy('id')->get();
    }

    /**
     * ¿El dueño sigue teniendo asignada la extensión?
     *
     * @param  \App\Models\User  $user
     * @param  string  $slug
     * @return bool
     */
    protected function tiene_extension($user, $slug)
    {
        return DB::table('extencion_empresa_user')
                    ->join('extencion_empresas', 'extencion_empresas.id', '=', 'extencion_empresa_user.extencion_empresa_id')
                    ->where('extencion_empresa_user.user_id', $user->id)
                    ->where('extencion_empresas.slug', $slug)
                    ->exists();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Las reglas por dueño
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Con la extensión de PLU -> 'plu', sin balanzas.
     *
     * @test
     */
    public function dueno_con_plu_pasa_a_plu()
    {
        $dueno = $this->dueno('PLU', ['plu_balanza_bar_code']);

        $this->correr();

        $this->assertSame('plu', $this->modo_de($dueno));
        $this->assertCount(0, $this->balanzas_de($dueno));
        $this->assertStringContainsString('Dueño ' . $dueno->id . ' ', $this->salida, 'La salida tiene una línea por dueño migrado.');
    }

    /**
     * Caso La Martina: con las DOS extensiones -> 'plu' y SIN balanza, aunque el artículo de la
     * extensión de importe sea suyo (en su base es una hamburguesa). La salida lo informa.
     *
     * @test
     */
    public function dueno_con_las_dos_pasa_a_plu_sin_balanza()
    {
        $dueno = $this->dueno('La Martina', ['balanza_bar_code', 'plu_balanza_bar_code']);
        $this->articulo_legacy($dueno->id);

        $this->correr();

        $this->assertSame('plu', $this->modo_de($dueno));
        $this->assertCount(0, $this->balanzas_de($dueno), 'Con las dos extensiones no se crea la balanza de importe.');
        $this->assertStringContainsString('También tiene balanza_bar_code', $this->salida, 'La salida tiene que avisar el caso de las dos extensiones.');
    }

    /**
     * Caso Panchito: solo la extensión de importe y el artículo de la extensión vieja es SUYO ->
     * 'balanzas' + la balanza '22' (importe, dígitos por defecto) apuntando a ese artículo.
     *
     * @test
     */
    public function dueno_con_solo_importe_y_el_articulo_propio_pasa_a_balanzas_con_la_balanza_22()
    {
        $dueno = $this->dueno('Panchito', ['balanza_bar_code']);
        $this->articulo_legacy($dueno->id);

        $this->correr();

        $this->assertSame('balanzas', $this->modo_de($dueno));

        $balanzas = $this->balanzas_de($dueno);

        $this->assertCount(1, $balanzas);
        $this->assertSame('22', $balanzas[0]->prefijo);
        $this->assertSame('importe', $balanzas[0]->tipo_dato);
        $this->assertNull($balanzas[0]->digitos, 'Dígitos por defecto: los 7 de la lectura vieja.');
        $this->assertSame($this->id_legacy(), $balanzas[0]->article_id);
        $this->assertSame('Balanza (tickets que empiezan con 22)', $balanzas[0]->nombre);
    }

    /**
     * Solo importe pero el artículo de la extensión vieja es de OTRO comercio (en la base
     * compartida vieja el 6346 es de uno solo) -> 'balanzas' SIN balanza, y la salida lo dice.
     *
     * @test
     */
    public function dueno_con_solo_importe_y_el_articulo_ajeno_pasa_a_balanzas_sin_balanza()
    {
        $otro = $this->dueno('Dueño del 6346');
        $this->articulo_legacy($otro->id);

        $dueno = $this->dueno('Importe sin articulo', ['balanza_bar_code']);

        $this->correr();

        $this->assertSame('balanzas', $this->modo_de($dueno));
        $this->assertCount(0, $this->balanzas_de($dueno), 'No se le imputan tickets a un artículo de otro comercio.');
        $this->assertStringContainsString('SIN balanza', $this->salida);
        $this->assertStringContainsString('es de otro comercio', $this->salida);

        // Y el dueño del artículo, sin extensiones, no se toca.
        $this->assertNull($this->modo_de($otro));
    }

    /**
     * Solo importe con el artículo propio pero BORRADO -> 'balanzas' sin balanza.
     *
     * @test
     */
    public function dueno_con_solo_importe_y_el_articulo_borrado_pasa_a_balanzas_sin_balanza()
    {
        $dueno = $this->dueno('Importe articulo borrado', ['balanza_bar_code']);
        $this->articulo_legacy($dueno->id, true);

        $this->correr();

        $this->assertSame('balanzas', $this->modo_de($dueno));
        $this->assertCount(0, $this->balanzas_de($dueno));
        $this->assertStringContainsString('está borrado', $this->salida);
    }

    /**
     * Sin ninguna de las dos extensiones -> no se toca.
     *
     * @test
     */
    public function dueno_sin_extensiones_no_se_toca()
    {
        $dueno = $this->dueno('Sin balanzas');

        $this->correr();

        $this->assertNull($this->modo_de($dueno));
        $this->assertCount(0, $this->balanzas_de($dueno));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Idempotencia, simulación y extensiones
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Un dueño que ya eligió (aunque sea 'ninguno') queda intacto, tenga las extensiones que tenga.
     *
     * @test
     */
    public function un_dueno_ya_configurado_queda_intacto()
    {
        $eligio_ninguno = $this->dueno('Eligio ninguno', ['plu_balanza_bar_code'], 'ninguno');
        $eligio_plu = $this->dueno('Eligio plu', ['balanza_bar_code'], 'plu');
        $this->articulo_legacy($eligio_plu->id);

        $this->correr();

        $this->assertSame('ninguno', $this->modo_de($eligio_ninguno));
        $this->assertSame('plu', $this->modo_de($eligio_plu));
        $this->assertCount(0, $this->balanzas_de($eligio_plu), 'A un dueño ya configurado no se le crea la balanza.');
        $this->assertStringContainsString('ya tiene tickets_de_balanza', $this->salida);
    }

    /**
     * Correrlo dos veces no duplica la balanza ni cambia nada: la segunda corrida ve al dueño ya
     * configurado.
     *
     * @test
     */
    public function la_segunda_corrida_no_duplica()
    {
        $dueno = $this->dueno('Panchito dos corridas', ['balanza_bar_code']);
        $this->articulo_legacy($dueno->id);

        $this->correr();
        $this->correr();

        $this->assertSame('balanzas', $this->modo_de($dueno));
        $this->assertCount(1, $this->balanzas_de($dueno), 'La segunda corrida no puede crear otra balanza 22.');
        $this->assertStringContainsString('Dueño ' . $dueno->id . ' ', $this->salida);
        $this->assertStringContainsString('ya tiene tickets_de_balanza = \'balanzas\'', $this->salida);
    }

    /**
     * Si el dueño (todavía sin configurar) ya tiene una balanza '22', no se crea otra.
     *
     * @test
     */
    public function si_ya_tiene_una_balanza_22_no_se_duplica()
    {
        $dueno = $this->dueno('Ya tenia la 22', ['balanza_bar_code']);
        $this->articulo_legacy($dueno->id);

        $existente = Balanza::create([
            'user_id'   => $dueno->id,
            'nombre'    => 'Cargada a mano',
            'prefijo'   => '22',
            'tipo_dato' => 'importe',
        ]);

        $this->correr();

        $this->assertSame('balanzas', $this->modo_de($dueno));

        $balanzas = $this->balanzas_de($dueno);

        $this->assertCount(1, $balanzas);
        $this->assertSame($existente->id, $balanzas[0]->id);
        $this->assertSame('Cargada a mano', $balanzas[0]->nombre, 'La balanza que ya estaba no se toca.');
    }

    /**
     * `--simular` dice lo que haría y no escribe NADA: ni el modo ni la balanza.
     *
     * @test
     */
    public function simular_no_escribe()
    {
        $plu = $this->dueno('Simular PLU', ['plu_balanza_bar_code']);
        $importe = $this->dueno('Simular importe', ['balanza_bar_code']);
        $this->articulo_legacy($importe->id);

        $balanzas_antes = Balanza::count();

        $this->correr(true);

        $this->assertNull($this->modo_de($plu));
        $this->assertNull($this->modo_de($importe));
        $this->assertEquals($balanzas_antes, Balanza::count(), 'Simulando no se crea ninguna balanza.');

        $this->assertStringContainsString('SIMULACIÓN', $this->salida);
        $this->assertStringContainsString('pasaría a \'plu\'', $this->salida);
        $this->assertStringContainsString('se crearía la balanza \'22\'', $this->salida);
    }

    /**
     * 🔴 Las filas de las extensiones siguen ahí después de migrar: el código viejo del frente
     * activo las sigue leyendo mientras dura el despliegue.
     *
     * @test
     */
    public function las_filas_de_las_extensiones_siguen_ahi()
    {
        $plu = $this->dueno('Extensiones PLU', ['plu_balanza_bar_code']);
        $las_dos = $this->dueno('Extensiones las dos', ['balanza_bar_code', 'plu_balanza_bar_code']);
        $importe = $this->dueno('Extensiones importe', ['balanza_bar_code']);
        $this->articulo_legacy($importe->id);

        $this->correr();

        $this->assertTrue($this->tiene_extension($plu, 'plu_balanza_bar_code'));
        $this->assertTrue($this->tiene_extension($las_dos, 'plu_balanza_bar_code'));
        $this->assertTrue($this->tiene_extension($las_dos, 'balanza_bar_code'));
        $this->assertTrue($this->tiene_extension($importe, 'balanza_bar_code'));

        // Y efectivamente migró (si no, que sigan ahí no probaría nada).
        $this->assertSame('plu', $this->modo_de($plu));
        $this->assertSame('plu', $this->modo_de($las_dos));
        $this->assertSame('balanzas', $this->modo_de($importe));
    }
}
