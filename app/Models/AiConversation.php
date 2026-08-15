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
 *   de stock terminada (referencia_id = stock_suggestions.id).
 * - 'sugerencia_compra': ídem con una sugerencia de compra a proveedores
 *   terminada (referencia_id = purchase_suggestions.id). La misión de compras
 *   sumó el origen y NO actualizó esta lista: la deuda se salda acá (15/8/2026).
 * - 'sugerencia_oferta': ídem con una corrida del motor de ofertas por cliente
 *   terminada (referencia_id = offer_suggestions.id). Toda misión que sume un
 *   origen actualiza esta lista en el MISMO commit: no hay enum ni constante.
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
