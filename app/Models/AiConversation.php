<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conversación del asistente de IA del negocio.
 *
 * Tenencia doble: `auth_user_id` es la PERSONA dueña de la conversación (las
 * respuestas pueden traer saldos de clientes: un empleado no lee las del dueño
 * ni al revés) y `user_id` es el dueño de la cuenta, que es por quien filtran
 * las tools de consulta cuando el job genera la respuesta sin sesión.
 *
 * origen:
 * - 'usuario': la abrió una persona desde el panel del chat.
 * - 'sugerencia_stock': la creó automáticamente el resumen de una sugerencia
 *   de stock terminada (referencia_id = stock_suggestions.id). Las misiones
 *   futuras suman sus propios orígenes (compras, ofertas).
 *
 * `contexto` guarda el bloque de DATOS ya calculados de la sugerencia (no las
 * instrucciones de redacción): viaja como segundo bloque del system en cada
 * pedido a la IA, para que pueda charlar sobre esos números sin recalcular.
 *
 * `titulo` null significa "todavía se está infiriendo" y la SPA muestra
 * "Nueva conversación"; si la inferencia falla queda null para siempre y no
 * pasa nada (un título es cosmético).
 */
class AiConversation extends Model
{
    protected $guarded = [];

    /**
     * Mensajes del hilo en orden de llegada. El orden por id alcanza porque
     * los mensajes solo se agregan al final, nunca se reordenan.
     */
    public function messages()
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }
}
