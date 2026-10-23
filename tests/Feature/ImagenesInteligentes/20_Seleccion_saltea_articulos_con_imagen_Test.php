<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Events\ArticleBatchImagesProcessed;
use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ImageServiceCall;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

/**
 * Misión imagenes-listado-saltear-con-foto (23/10/2026). Pedido de Lucas, textual: «cuando buscan
 * imágenes inteligentes desde el listado, que no le vuelva a buscar imágenes a los artículos que ya
 * tienen imágenes». El motivo de fondo es el gasto de Serper/Google: cada artículo que ya tenía foto
 * y se volvía a buscar eran búsquedas pagadas para nada.
 *
 * Lo que protege:
 *   1. POST google/batch-assign-images descarta ANTES de crear la corrida a los elegidos que ya
 *      tienen ≥1 imagen (no tienen item, no se encolan) y lo dice en la respuesta (`articulos`,
 *      `saltados_con_imagen`).
 *   2. Si TODOS los elegidos tienen imagen no se crea nada (ni corrida, ni registro visible, ni job)
 *      y responde 422 con forma de validación (`errors.article_ids`).
 *   3. El motor saltea con `ya_tenia_imagen` a un artículo de una SELECCIÓN que consiguió foto entre
 *      la creación de la corrida y su turno (0 búsquedas).
 *   4. Las del ASISTENTE no cambian: el filtro ahí es `solo_sin_imagen` al ejecutar, y con
 *      `solo_sin_imagen=false` el dueño pidió buscar igual; ni encolar() ni el motor las filtran.
 *
 * DatabaseTransactions (por EmpresaTestCase), nunca RefreshDatabase: la base de testing está sembrada.
 */
class Seleccion_saltea_articulos_con_imagen_Test extends ImagenesInteligentesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ArticleBatchImagesProcessed::class, BackgroundProcessUpdated::class]);
    }

    /**
     * Le carga una imagen a mano a un artículo (🔴 el morph map usa el alias 'article', no el FQCN).
     *
     * @param  \App\Models\Article $articulo
     * @return void
     */
    protected function con_imagen(Article $articulo)
    {
        Image::create([
            'hosting_url'    => 'http://empresa.local/storage/cargada-a-mano-'.$articulo->id.'.webp',
            'imageable_id'   => $articulo->id,
            'imageable_type' => 'article',
        ]);
    }

    /**
     * Los ids de los items de una corrida, en orden.
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @return array
     */
    protected function ids_de_la_corrida(ImageAssignmentRun $run)
    {
        return ImageAssignmentItem::where('run_id', $run->id)->orderBy('orden')->pluck('article_id')->map(function ($id) {
            return (int) $id;
        })->all();
    }

    /**
     * Selección mixta desde el listado: 2 con imagen y 3 sin. Se buscan solo los 3, en el orden en
     * que se pidieron, y la respuesta dice cuántos se buscan y cuántos se saltearon.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function la_seleccion_del_listado_no_crea_items_para_los_que_ya_tienen_imagen()
    {
        $a = $this->nuevo_articulo('Martillo galponero');
        $b = $this->nuevo_articulo('Pinza universal');
        $c = $this->nuevo_articulo('Destornillador plano');
        $d = $this->nuevo_articulo('Llave francesa');
        $e = $this->nuevo_articulo('Cinta métrica 5 m');

        $this->con_imagen($b);
        $this->con_imagen($d);

        Queue::fake();

        $pedidos = [(int) $e->id, (int) $b->id, (int) $a->id, (int) $d->id, (int) $c->id];

        $respuesta = $this->postJson('api/google/batch-assign-images', ['article_ids' => $pedidos]);

        $respuesta->assertStatus(200);
        $this->assertSame('processing', $respuesta->json('status'));
        $this->assertSame(3, $respuesta->json('articulos'), 'Se mandan a buscar solo los que no tienen imagen.');
        $this->assertSame(2, $respuesta->json('saltados_con_imagen'), 'Los que ya tenían imagen se cuentan como salteados.');

        $run = ImageAssignmentRun::where('uuid', $respuesta->json('batch_uuid'))->first();
        $this->assertNotNull($run, 'El uuid de la respuesta es el de la asignación.');
        $this->assertSame(ImageAssignmentRun::ORIGEN_SELECCION, $run->origen);
        $this->assertSame(3, (int) $run->total_articulos);
        $this->assertSame([(int) $e->id, (int) $a->id, (int) $c->id], $this->ids_de_la_corrida($run), 'En el orden pedido, sin los que tenían imagen.');

        $this->assertSame(0, ImageAssignmentItem::where('user_id', $this->owner->id)->whereIn('article_id', [$b->id, $d->id])->count(),
            'Ninguno de los que ya tenían imagen tiene item: no se busca ni se gasta.');

        $proceso = BackgroundProcess::find($run->background_process_id);
        $this->assertSame(3, (int) $proceso->total);
        $this->assertSame('3 artículos', $proceso->detalle);

        Queue::assertPushed(ProcessImageAssignmentRunJob::class, function ($job) use ($run) {
            $propiedad = new \ReflectionProperty($job, 'run_id');
            $propiedad->setAccessible(true);

            return (int) $propiedad->getValue($job) === (int) $run->id;
        });
    }

    /**
     * Si todos los elegidos ya tienen imagen no se crea nada: ni corrida, ni registro visible, ni
     * job. Responde 422 con forma de validación para que la SPA lo muestre como aviso.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function si_todos_tienen_imagen_no_se_crea_nada_y_responde_422()
    {
        $a = $this->nuevo_articulo('Serrucho');
        $b = $this->nuevo_articulo('Escuadra');

        $this->con_imagen($a);
        $this->con_imagen($b);

        $corridas_antes  = ImageAssignmentRun::where('user_id', $this->owner->id)->count();
        $procesos_antes  = BackgroundProcess::where('user_id', $this->owner->id)->count();

        Queue::fake();

        $respuesta = $this->postJson('api/google/batch-assign-images', ['article_ids' => [$a->id, $b->id]]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonValidationErrors('article_ids');
        $this->assertSame('Los 2 artículos elegidos ya tienen imagen: no se buscó nada.', $respuesta->json('errors.article_ids.0'));

        $this->assertSame($corridas_antes, ImageAssignmentRun::where('user_id', $this->owner->id)->count(), 'No se crea ninguna corrida.');
        $this->assertSame($procesos_antes, BackgroundProcess::where('user_id', $this->owner->id)->count(), 'No se abre ningún registro visible.');
        Queue::assertNotPushed(ProcessImageAssignmentRunJob::class);

        // Con uno solo, el mensaje va en singular.
        $this->postJson('api/google/batch-assign-images', ['article_ids' => [$a->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.article_ids.0', 'El artículo elegido ya tiene imagen: no se buscó nada.');

        Queue::assertNotPushed(ProcessImageAssignmentRunJob::class);
    }

    /**
     * separar_con_imagen(): orden recibido, sin repetidos, y los ids ajenos o inexistentes no cuentan
     * en ninguno de los dos lados (crear() los descartaría igual).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function separar_con_imagen_respeta_el_orden_y_no_cuenta_ajenos_ni_inexistentes()
    {
        $con = $this->nuevo_articulo('Con foto');
        $sin = $this->nuevo_articulo('Sin foto');
        $otro_sin = $this->nuevo_articulo('Otro sin foto');

        $this->con_imagen($con);

        $otro_comercio = User::create([
            'name'         => 'Otro comercio',
            'company_name' => 'Otro comercio',
            'email'        => 'otro-comercio-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $ajeno = Article::create(['name' => 'Ajeno con foto', 'user_id' => $otro_comercio->id, 'status' => 'active']);
        $this->con_imagen($ajeno);

        $inexistente = (int) Article::max('id') + 1000;

        $separados = ImageAssignmentRunHelper::separar_con_imagen($this->owner->id, [
            $otro_sin->id, $con->id, (string) $sin->id, $otro_sin->id, $ajeno->id, $inexistente,
        ]);

        $this->assertSame([(int) $otro_sin->id, (int) $sin->id], $separados['sin_imagen']);
        $this->assertSame(1, $separados['con_imagen'], 'El ajeno con foto no se cuenta: no es del dueño.');
    }

    /**
     * La foto se cargó ENTRE la creación de la corrida de selección y el turno del artículo: el motor
     * lo saltea con `ya_tenia_imagen`, sin gastar ninguna búsqueda.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function en_la_seleccion_una_foto_cargada_antes_del_turno_no_se_busca()
    {
        $codigo   = '7791234567898';
        $articulo = $this->nuevo_articulo('Taladro percutor 650 W', $codigo);

        $seleccion = $this->asignacion([$articulo], ['origen' => ImageAssignmentRun::ORIGEN_SELECCION]);

        // Mientras esperaba su turno, alguien le cargó una imagen a mano.
        $this->con_imagen($articulo);

        $this->falsear(
            [$codigo => [$this->resultado($this->url_imagen('taladro'), 1000, 1000, 1)]],
            [$this->url_imagen('taladro') => $this->png(1000, 1000, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        $item = $this->procesar($seleccion, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item->status);
        $this->assertSame('ya_tenia_imagen', $item->motivo);
        $this->assertSame(0, (int) $item->busquedas);
        $this->assertCount(0, $this->consultas_serper, 'No se gastó ninguna búsqueda.');
        $this->assertSame(0, ImageServiceCall::where('run_id', $seleccion->id)->count(), 'No se consultó ningún servicio.');
        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $articulo->id)->count(), 'Sigue con su única imagen.');
    }

    /**
     * Las del ASISTENTE no cambian (decisión 2 del plan): con `solo_sin_imagen=false` el dueño pidió
     * buscarles también a los que ya tienen foto, y la corrida no guarda esa bandera. Ni encolar()
     * filtra ni el motor saltea.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function las_del_asistente_buscan_aunque_el_articulo_ya_tenga_imagen()
    {
        $codigo   = '7791234567898';
        $con_foto = $this->nuevo_articulo('Taladro percutor 650 W', $codigo);
        $sin_foto = $this->nuevo_articulo('Amoladora angular 115 mm');

        $this->con_imagen($con_foto);

        Queue::fake();

        $encolado = ImagenesAutomaticasHelper::encolar($this->owner, [$con_foto->id, $sin_foto->id], $this->owner->id, ImageAssignmentRun::ORIGEN_ASISTENTE);

        $this->assertSame(0, $encolado['saltados_con_imagen'], 'Con origen asistente no se filtra nada.');
        $this->assertSame(2, $encolado['articulos']);

        $run = ImageAssignmentRun::where('uuid', $encolado['batch_uuid'])->firstOrFail();
        $this->assertSame(ImageAssignmentRun::ORIGEN_ASISTENTE, $run->origen);
        $this->assertSame([(int) $con_foto->id, (int) $sin_foto->id], $this->ids_de_la_corrida($run));

        $this->falsear(
            [$codigo => [$this->resultado($this->url_imagen('taladro'), 1000, 1000, 1)]],
            [$this->url_imagen('taladro') => $this->png(1000, 1000, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        $item = $this->procesar($run, $con_foto);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status, 'El motor no saltea las del asistente.');
        $this->assertCount(1, $this->consultas_serper);
    }
}
