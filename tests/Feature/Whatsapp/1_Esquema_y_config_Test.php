<?php

namespace Tests\Feature\Whatsapp;

use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión whatsapp-agente — F8/1: el esquema nuevo y los casts de los modelos.
 *
 * Lo que protege este archivo es la promesa que hace segura la migración: las tres
 * columnas nuevas existen, los dos tiempos de espera defaultean a 0 y `ai_status` nace
 * null. Eso junto significa que correr las migraciones solas, sin que nadie configure
 * nada, deja EXACTAMENTE el comportamiento anterior a la misión (respuesta al instante,
 * sin confirmación humana) y no le cambia ni una fila de significado a ninguna empresa
 * que ya tenga el bot andando.
 *
 * Los defaults se leen de `information_schema` y no del modelo a propósito: lo que
 * importa es lo que hay escrito en la base, no lo que alguien creyó configurar en PHP.
 */
class Esquema_y_config_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: ningún test de esta suite sale a la red.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F8-1',
            'company_name' => 'Ferreteria F8-1',
            'email'        => 'whatsapp-f8-1-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Default de una columna según information_schema (null si no hay default).
     *
     * @param string $tabla
     * @param string $columna
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
     * true si el índice existe en la tabla.
     *
     * @param string $tabla
     * @param string $nombre_indice
     * @return bool
     */
    protected function indice_existe($tabla, $nombre_indice)
    {
        $filas = DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = '{$nombre_indice}'");

        return ! empty($filas);
    }

    /**
     * Config del bot con las tres credenciales técnicas cargadas (son NOT NULL sin
     * default, así que no se puede crear una fila sin ellas).
     *
     * @param array $extra
     * @return WhatsappBotConfig
     */
    protected function config_del_bot(array $extra = [])
    {
        return WhatsappBotConfig::create(array_merge([
            'user_id'         => $this->comercio->id,
            'kapso_api_key'   => 'kapso-f8-1',
            'phone_number_id' => '111222333',
            'webhook_secret'  => 'secreto-f8-1',
            'is_active'       => true,
        ], $extra));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function los_tiempos_de_espera_del_agente_existen_y_defaultean_a_cero()
    {
        foreach (['ai_reply_delay_seconds', 'ai_confirm_delay_seconds'] as $columna) {
            $this->assertTrue(
                Schema::hasColumn('whatsapp_bot_configs', $columna),
                "Falta la columna whatsapp_bot_configs.{$columna}."
            );

            $this->assertEquals(
                '0',
                (string) $this->default_de_columna('whatsapp_bot_configs', $columna),
                "{$columna} TIENE que defaultear a 0: es lo único que garantiza que la migración sola "
                . 'no le cambie el comportamiento a ninguna empresa que ya tenga el bot andando.'
            );
        }

        // Y el default llega efectivamente al modelo recién creado, sin pasarlos.
        $config = $this->config_del_bot();
        $config->refresh();

        $this->assertEquals(0, (int) $config->ai_reply_delay_seconds);
        $this->assertEquals(0, (int) $config->ai_confirm_delay_seconds);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function los_mensajes_tienen_el_eje_de_confirmacion_con_su_indice()
    {
        foreach (['ai_status', 'ai_auto_send_at'] as $columna) {
            $this->assertTrue(
                Schema::hasColumn('whatsapp_chat_messages', $columna),
                "Falta la columna whatsapp_chat_messages.{$columna}."
            );

            $this->assertNull(
                $this->default_de_columna('whatsapp_chat_messages', $columna),
                "{$columna} nace null: todas las filas que ya existen quedan en 'no aplica'."
            );
        }

        $this->assertTrue(
            $this->indice_existe('whatsapp_chat_messages', 'wcm_ai_status_idx'),
            'Falta el índice wcm_ai_status_idx: los pendientes se buscan por ai_status en cada entrante.'
        );
    }

    /**
     * @group whatsapp
     * @test
     */
    public function articles_guarda_la_huella_del_texto_vectorizado()
    {
        $this->assertTrue(
            Schema::hasColumn('articles', 'embedding_source_hash'),
            'Falta la columna articles.embedding_source_hash.'
        );

        $this->assertNull(
            $this->default_de_columna('articles', 'embedding_source_hash'),
            'La huella nace null: los artículos ya vectorizados no tienen ninguna guardada todavía.'
        );
    }

    /**
     * Un mensaje como los que ya existían (creado sin tocar ninguna columna nueva) sigue
     * siendo perfectamente válido: eso es lo que quiere decir "ninguna fila existente
     * cambia de significado".
     *
     * @group whatsapp
     * @test
     */
    public function un_mensaje_creado_sin_las_columnas_nuevas_sigue_siendo_valido()
    {
        $chat = WhatsappChat::create([
            'user_id'      => $this->comercio->id,
            'phone'        => '5493416000001',
            'unread_count' => 0,
        ]);

        $message = WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => 'manual',
            'body'             => 'Mensaje de los de antes.',
        ]);

        $message->refresh();

        $this->assertNull($message->ai_status, 'Un mensaje que no salió del agente queda en ai_status null.');
        $this->assertNull($message->ai_auto_send_at);
        $this->assertEquals(
            'pendiente',
            $message->delivery_status,
            'delivery_status sigue naciendo en pendiente: el eje de Meta no se tocó.'
        );
    }

    /**
     * @group whatsapp
     * @test
     */
    public function los_casts_nuevos_devuelven_entero_y_carbon()
    {
        // Se guardan como string a propósito: es lo que llega de un formulario.
        $config = $this->config_del_bot([
            'ai_reply_delay_seconds'   => '45',
            'ai_confirm_delay_seconds' => '90',
        ]);
        $config->refresh();

        $this->assertSame(45, $config->ai_reply_delay_seconds, 'ai_reply_delay_seconds tiene que castear a int.');
        $this->assertSame(90, $config->ai_confirm_delay_seconds, 'ai_confirm_delay_seconds tiene que castear a int.');

        $chat = WhatsappChat::create([
            'user_id'      => $this->comercio->id,
            'phone'        => '5493416000002',
            'unread_count' => 0,
        ]);

        $message = WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'out',
            'source'           => 'ia',
            'body'             => 'Respuesta esperando confirmación.',
            'ai_status'        => 'a_confirmar',
            'ai_auto_send_at'  => now()->addSeconds(120),
        ]);
        $message->refresh();

        $this->assertInstanceOf(
            Carbon::class,
            $message->ai_auto_send_at,
            'ai_auto_send_at tiene que castear a fecha: el front arma el contador regresivo con el ISO.'
        );
        $this->assertEquals('a_confirmar', $message->ai_status);
    }
}
