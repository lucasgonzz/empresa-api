<?php

namespace Tests\Feature\ImagenesGoogle;

use App\Jobs\ProcessArticleBatchImagesJob;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Feature tests del `batch_uuid` que POST google/batch-assign-images le devuelve al frontend.
 *
 * Protege una falla silenciosa que NO se ve con un solo comercio en pantalla. El canal de
 * broadcast se llama `article_batch_images.{owner_id}` y es PUBLICO: dos instalaciones que
 * compartan el id del owner y la misma app de Pusher estan literalmente en el mismo canal, y
 * cada una recibe los eventos de la otra. Como el frontend acepta el primer evento que llega y
 * ahi mismo se da de baja del canal, termina perdiendo el suyo: las imagenes se asignan bien
 * pero el modal de resumen no aparece nunca. Medido el 28/8/2026 entre dos instancias de demo
 * (demo2 y demo3, las dos con owner_id 300 y la misma PUSHER_APP_ID).
 *
 * Lo mismo pasa, sin ninguna segunda instalacion, con dos lotes seguidos del mismo usuario.
 *
 * La unica forma de que el frontend distinga su corrida es conocer el uuid ANTES de que llegue
 * el evento. De ahi que el uuid se genere en el controlador y no adentro del job.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada de antes y un
 * refresh la vaciaria, rompiendo el resto de las suites.
 */
class Uuid_del_lote_en_la_respuesta_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo fixture que el test de cuota).
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Autentica para las requests que siguen.
     *
     * 🔴 El `forgetGuards()` no es decorativo: la ruta esta bajo `auth:sanctum` y ese guard
     * cachea el usuario que resolvio la primera vez dentro del mismo container. Sin olvidarlo,
     * un segundo `actingAs()` no cambia quien resuelve la request. Esta documentado en
     * tests/Feature/Alertas/1_Ventas_sin_cobrar_dias_Test.php, donde costo una corrida en rojo.
     *
     * @param \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Lee el `batch_uuid` que quedo guardado en un job encolado.
     *
     * Es una property protected y se lee por reflexion a proposito: hacerla publica solo para el
     * test le ampliaria la superficie a una clase que corre en produccion. Lo que se esta
     * probando es justamente que el job se lleve el mismo uuid que se prometio en la respuesta.
     *
     * @param \App\Jobs\ProcessArticleBatchImagesJob $job
     * @return string
     */
    protected function uuid_del_job(ProcessArticleBatchImagesJob $job)
    {
        $property = new ReflectionProperty($job, 'batch_uuid');
        $property->setAccessible(true);

        return (string) $property->getValue($job);
    }

    /**
     * Dispara un lote con la cola falseada y devuelve la respuesta.
     *
     * @param \App\Models\User $user
     * @return \Illuminate\Testing\TestResponse
     */
    protected function disparar_lote($user)
    {
        $this->actuar_como($user);

        return $this->postJson('api/google/batch-assign-images', [
            'article_ids' => [1],
        ]);
    }

    /**
     * Test 1 -- la respuesta trae un `batch_uuid` no vacio.
     *
     * Sin este campo el frontend no tiene con que filtrar y vuelve a quedar a merced del primer
     * evento que pase por el canal.
     *
     * @return void
     */
    public function test_la_respuesta_del_lote_trae_el_uuid_de_la_corrida()
    {
        $user = $this->usuario_de_testing();
        if (!$user) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        Queue::fake();

        $response = $this->disparar_lote($user);

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'batch_uuid']);

        $this->assertNotSame('', (string) $response->json('batch_uuid'),
            'El endpoint tiene que devolver el uuid de la corrida, no una cadena vacia.');
    }

    /**
     * Test 2 -- 🔴 el uuid prometido es EXACTAMENTE el que se lleva el job encolado.
     *
     * Es el corazon del arreglo: si el controlador devolviera un uuid y despachara el job sin
     * el, el frontend filtraria por un uuid que el evento nunca va a traer y el modal no
     * aparece mas para nadie. Ese fallo no se nota mirando la respuesta del endpoint, que
     * sigue devolviendo un uuid perfectamente valido.
     *
     * ⚠️ ALCANCE, para no leer de mas en este test: llega hasta el job ENCOLADO. NO corre
     * handle(), asi que no cubre el caso de que handle() ignore `$this->batch_uuid` y genere
     * uno nuevo. Correr handle() de verdad arrastraria busqueda en Google, descarga de
     * imagenes y validacion por IA, que es justamente lo que el resto de esta carpeta evita.
     * Esa linea queda protegida por su comentario en el job, no por un test.
     *
     * @return void
     */
    public function test_el_job_se_lleva_el_mismo_uuid_que_devolvio_la_respuesta()
    {
        $user = $this->usuario_de_testing();
        if (!$user) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        Queue::fake();

        $uuid_prometido = (string) $this->disparar_lote($user)->json('batch_uuid');

        $self = $this;

        Queue::assertPushed(ProcessArticleBatchImagesJob::class,
            function ($job) use ($self, $uuid_prometido) {
                return $self->uuid_del_job($job) === $uuid_prometido;
            });
    }

    /**
     * Test 3 -- dos corridas no comparten uuid.
     *
     * Cubre el caso sin segunda instalacion: el mismo usuario disparando dos lotes seguidos. Con
     * uuids iguales el frontend no podria distinguirlos y volveria a quedarse con el primero.
     *
     * @return void
     */
    public function test_dos_corridas_seguidas_reciben_uuids_distintos()
    {
        $user = $this->usuario_de_testing();
        if (!$user) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        Queue::fake();

        $primero  = (string) $this->disparar_lote($user)->json('batch_uuid');
        $segundo  = (string) $this->disparar_lote($user)->json('batch_uuid');

        $this->assertNotSame($primero, $segundo,
            'Dos lotes distintos no pueden compartir el uuid: es lo unico que los separa.');
    }

    /**
     * Test 4 -- el job sigue aceptando que NO le pasen uuid.
     *
     * Compatibilidad hacia atras: el parametro se agrego al final y con default, asi que
     * cualquier despacho viejo de cinco argumentos tiene que seguir construyendo. Con vacio, el
     * handle() genera el suyo, que es como se comportaba antes.
     *
     * @return void
     */
    public function test_el_job_se_puede_construir_sin_uuid_como_antes()
    {
        $job = new ProcessArticleBatchImagesJob([1], 500, 'KEY-DE-PRUEBA', 'CX-DE-PRUEBA', 10);

        $this->assertSame('', $this->uuid_del_job($job),
            'Sin uuid el job tiene que quedar en vacio, que es la senal de "generalo vos".');
    }
}
