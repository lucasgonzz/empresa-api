<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AiMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Misión asistente-por-whatsapp — el esquema que el resto de la misión asume, y las dos guardas de
 * entrada del canal.
 *
 * 🔴 El test que más importa de este archivo es el de la CLAVE: las cuatro rutas de admin-sync del
 * asistente exigen `X-Admin-Api-Key` aunque `services.admin_api.require_api_key` esté en `false`,
 * que es como está en producción. Si alguna vez alguien "simplifica" el controlador y deja que la
 * validación dependa del middleware, este test se pone rojo — y tiene que ponerse rojo, porque ese
 * día el canal que carga compras, gastos y pagos queda abierto a cualquiera que sepa el dominio del
 * cliente.
 */
class Esquema_y_gate_Test extends AsistenteWhatsappTestCase
{
    /**
     * Default de una columna según information_schema (null si no hay default).
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @return string|null
     */
    protected function default_de_columna($tabla, $columna)
    {
        $fila = DB::selectOne(
            "SELECT COLUMN_DEFAULT as valor
             FROM information_schema.columns
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?",
            [$tabla, $columna]
        );

        return $fila ? $fila->valor : null;
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function ai_messages_suma_canal_con_default_sistema_y_el_wamid()
    {
        $this->assertTrue(Schema::hasColumn('ai_messages', 'canal'), 'Falta ai_messages.canal.');
        $this->assertTrue(Schema::hasColumn('ai_messages', 'whatsapp_message_id'), 'Falta ai_messages.whatsapp_message_id.');

        $this->assertEquals(
            'sistema',
            $this->default_de_columna('ai_messages', 'canal'),
            'El default tiene que ser sistema: es lo que deja al chat de la pantalla comportándose igual que antes de la misión.'
        );

        $conversation = $this->conversacion_whatsapp();

        $sin_canal = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Un mensaje de la pantalla',
            'estado'             => 'listo',
        ]);

        $sin_canal->refresh();

        $this->assertEquals('sistema', $sin_canal->canal, 'Un mensaje creado sin canal tiene que quedar en sistema.');
        $this->assertFalse($sin_canal->es_de_whatsapp());
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function las_tablas_nuevas_existen_con_sus_columnas()
    {
        $this->assertTrue(Schema::hasTable('ai_message_imagenes'), 'Falta la tabla ai_message_imagenes.');

        foreach (['id', 'ai_message_id', 'user_id', 'orden', 'path', 'mime', 'bytes', 'gestionada_at'] as $columna) {
            $this->assertTrue(
                Schema::hasColumn('ai_message_imagenes', $columna),
                'Falta la columna ai_message_imagenes.' . $columna . '.'
            );
        }

        $this->assertTrue(Schema::hasTable('mostrador_accesos'), 'Falta la tabla mostrador_accesos.');

        foreach (['id', 'mostrador_reporte_id', 'user_id', 'token_hash', 'expira_at', 'usado_at'] as $columna) {
            $this->assertTrue(
                Schema::hasColumn('mostrador_accesos', $columna),
                'Falta la columna mostrador_accesos.' . $columna . '.'
            );
        }

        $this->assertTrue(
            Schema::hasColumn('mostrador_reportes', 'avisado_at'),
            'Falta mostrador_reportes.avisado_at.'
        );
    }

    /**
     * 🔴 La clave se exige SIEMPRE, aunque require_api_key esté apagado. Ver el docblock de la
     * clase: es la guarda que evita que el canal quede abierto en producción.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_la_clave_son_401_aunque_require_api_key_este_apagado()
    {
        $this->dar_extension();

        $this->assertFalse(
            (bool) config('services.admin_api.require_api_key'),
            'Este test no prueba nada si require_api_key está prendido.'
        );

        $this->mandar([], false)->assertStatus(401);

        $this->getJson('api/admin-sync/asistente/mensajes/1')->assertStatus(401);
        $this->getJson('api/admin-sync/asistente/informes-pendientes')->assertStatus(401);
        $this->postJson('api/admin-sync/asistente/informes/1/avisado')->assertStatus(401);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function con_una_clave_equivocada_tambien_es_401()
    {
        $this->dar_extension();

        $this->postJson('api/admin-sync/asistente/mensajes', ['texto' => 'hola'], ['X-Admin-Api-Key' => 'otra-clave'])
            ->assertStatus(401);
    }

    /**
     * Una instancia que no tiene cargada su propia clave no puede autenticar a nadie: se corta con
     * 401 en vez de dejar pasar. "No hay clave configurada" nunca es un pase libre.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_clave_configurada_de_este_lado_tambien_es_401()
    {
        $this->dar_extension();

        config(['services.admin_api.api_key' => '']);

        $this->mandar()->assertStatus(401);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function sin_la_extension_asistente_ia_son_403()
    {
        Queue::fake();

        $this->mandar()->assertStatus(403);

        $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())->assertStatus(403);

        Queue::assertNothingPushed();
    }

    /**
     * El mensaje de otro dueño no se puede leer por el polling: el admin pregunta por un id y la
     * respuesta tiene que ser 404, no el texto del asistente de otro comercio.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_polling_no_ve_el_mensaje_de_otro_dueno()
    {
        $this->dar_extension();

        $otro = $this->otro_dueno();

        $conversacion_ajena = $this->conversacion_whatsapp(null, $otro);

        $mensaje_ajeno = $this->mensaje($conversacion_ajena, 'assistant', 'listo', [
            'contenido' => 'Las ventas del otro negocio',
        ]);

        $this->getJson('api/admin-sync/asistente/mensajes/' . $mensaje_ajeno->id, $this->headers())
            ->assertStatus(404);
    }

    /**
     * Un mensaje del chat de la pantalla tampoco se lee por este canal: el admin no tiene por qué
     * poder leer las conversaciones que el dueño tiene abiertas en el sistema.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_polling_no_ve_un_mensaje_del_canal_sistema()
    {
        $this->dar_extension();

        $conversation = $this->conversacion_whatsapp();

        $del_sistema = $this->mensaje($conversation, 'assistant', 'listo', [
            'canal'     => AiMessage::CANAL_SISTEMA,
            'contenido' => 'Algo que el dueño escribió desde la pantalla',
        ]);

        $this->getJson('api/admin-sync/asistente/mensajes/' . $del_sistema->id, $this->headers())
            ->assertStatus(404);
    }
}
