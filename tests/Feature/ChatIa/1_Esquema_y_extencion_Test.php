<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTokenUsage;
use App\Models\ExtencionEmpresa;
use Database\Seeders\ExtencionAsistenteIaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión chat-ia-y-modulo-ia — P1: esquema del chat y extensión asistente_ia.
 *
 * Protege que las migraciones nuevas dejen exactamente el esquema que el resto
 * de la misión asume: las tres tablas (ai_conversations, ai_messages,
 * ai_token_usages) con sus columnas, defaults e índices; que los modelos crean
 * y relacionan sin sorpresas; y que el seeder suelto de la extensión es
 * idempotente de verdad (correrlo dos veces deja UNA fila).
 */
class Esquema_y_extencion_Test extends TestCase
{
    use DatabaseTransactions;

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
     * @group chat-ia
     * @test
     */
    public function ai_conversations_existe_con_sus_columnas_defaults_e_indices()
    {
        $this->assertTrue(
            Schema::hasTable('ai_conversations'),
            'Falta la tabla ai_conversations.'
        );

        $columnas = [
            'id', 'user_id', 'auth_user_id', 'titulo', 'origen',
            'referencia_id', 'contexto', 'last_message_at',
            'created_at', 'updated_at',
        ];

        foreach ($columnas as $columna) {
            $this->assertTrue(
                Schema::hasColumn('ai_conversations', $columna),
                "Falta la columna ai_conversations.{$columna}."
            );
        }

        $this->assertEquals(
            'usuario',
            $this->default_de_columna('ai_conversations', 'origen'),
            'origen tiene que defaultear a usuario: es el caso normal de una conversación abierta desde el panel.'
        );

        $indices = [
            'ai_conversations_auth_user_last_message_index',
            'ai_conversations_user_index',
            'ai_conversations_origen_referencia_index',
        ];

        foreach ($indices as $indice) {
            $this->assertTrue(
                $this->indice_existe('ai_conversations', $indice),
                "Falta el índice {$indice} en ai_conversations."
            );
        }
    }

    /**
     * @group chat-ia
     * @test
     */
    public function ai_messages_existe_con_sus_columnas_default_e_indice()
    {
        $this->assertTrue(
            Schema::hasTable('ai_messages'),
            'Falta la tabla ai_messages.'
        );

        $columnas = [
            'id', 'ai_conversation_id', 'rol', 'contenido', 'estado',
            'error_mensaje', 'created_at', 'updated_at',
        ];

        foreach ($columnas as $columna) {
            $this->assertTrue(
                Schema::hasColumn('ai_messages', $columna),
                "Falta la columna ai_messages.{$columna}."
            );
        }

        $this->assertEquals(
            'listo',
            $this->default_de_columna('ai_messages', 'estado'),
            'estado tiene que defaultear a listo: los mensajes user nacen definitivos.'
        );

        $this->assertTrue(
            $this->indice_existe('ai_messages', 'ai_messages_conversation_id_index'),
            'Falta el índice ai_messages_conversation_id_index en ai_messages.'
        );
    }

    /**
     * @group chat-ia
     * @test
     */
    public function ai_token_usages_existe_con_sus_columnas_defaults_e_indices()
    {
        $this->assertTrue(
            Schema::hasTable('ai_token_usages'),
            'Falta la tabla ai_token_usages.'
        );

        $columnas = [
            'id', 'user_id', 'auth_user_id', 'proceso', 'modelo',
            'input_tokens', 'output_tokens',
            'cache_creation_input_tokens', 'cache_read_input_tokens',
            'ai_conversation_id', 'referencia_id',
            'created_at', 'updated_at',
        ];

        foreach ($columnas as $columna) {
            $this->assertTrue(
                Schema::hasColumn('ai_token_usages', $columna),
                "Falta la columna ai_token_usages.{$columna}."
            );
        }

        // Los cuatro contadores nacen en 0: un usage incompleto de la API no deja nulls.
        foreach (['input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $contador) {
            $this->assertEquals(
                '0',
                (string) $this->default_de_columna('ai_token_usages', $contador),
                "{$contador} tiene que defaultear a 0."
            );
        }

        $indices = [
            'ai_token_usages_user_created_index',
            'ai_token_usages_proceso_index',
        ];

        foreach ($indices as $indice) {
            $this->assertTrue(
                $this->indice_existe('ai_token_usages', $indice),
                "Falta el índice {$indice} en ai_token_usages."
            );
        }
    }

    /**
     * @group chat-ia
     * @test
     */
    public function una_conversacion_crea_y_relaciona_sus_mensajes_en_orden()
    {
        $conversation = AiConversation::create([
            'user_id'      => 999901,
            'auth_user_id' => 999902,
        ]);

        // Los defaults del esquema tienen que llegar al modelo recién creado.
        $conversation->refresh();
        $this->assertEquals('usuario', $conversation->origen);
        $this->assertNull($conversation->titulo);

        $user_message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Hola, ¿cuánto stock tengo de tornillos?',
        ]);

        $assistant_message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
        ]);

        $user_message->refresh();
        $this->assertEquals('listo', $user_message->estado, 'Un mensaje user nace listo por default.');
        $this->assertNull($assistant_message->contenido, 'El assistant pendiente nace sin contenido.');

        $mensajes = $conversation->messages()->get();

        $this->assertCount(2, $mensajes);
        $this->assertEquals('user', $mensajes[0]->rol, 'La relación messages() tiene que venir ordenada por id.');
        $this->assertEquals('assistant', $mensajes[1]->rol);

        $this->assertEquals(
            $conversation->id,
            $assistant_message->conversation->id,
            'La relación inversa conversation() tiene que resolver por ai_conversation_id.'
        );

        // El registro de tokens acepta una fila mínima con los contadores en default.
        $usage = AiTokenUsage::create([
            'user_id'            => 999901,
            'proceso'            => 'chat_mensaje',
            'modelo'             => 'claude-de-prueba',
            'ai_conversation_id' => $conversation->id,
        ]);

        $usage->refresh();
        $this->assertEquals(0, (int) $usage->input_tokens);
        $this->assertEquals(0, (int) $usage->output_tokens);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_seeder_suelto_corrido_dos_veces_deja_una_sola_extension()
    {
        (new ExtencionAsistenteIaSeeder())->run();
        (new ExtencionAsistenteIaSeeder())->run();

        $filas = ExtencionEmpresa::where('slug', 'asistente_ia')->get();

        $this->assertCount(
            1,
            $filas,
            'El seeder tiene que ser idempotente: dos corridas dejan UNA fila con slug asistente_ia.'
        );

        $this->assertEquals('Asistente IA', $filas[0]->name);
    }
}
