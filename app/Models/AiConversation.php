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
     * Titulo de una conversacion que nace de una sugerencia terminada: el nombre del tipo
     * mas la fecha en que la sugerencia se genero, en dia/mes/anio.
     *
     * Antes era "<tipo> #<id>" (pedido de Lucas del 19/8/2026: el id no le dice nada a
     * nadie; la fecha si). Vive ACA y no copiado en los tres jobs porque el formato es
     * uno solo para los tres tipos: con una copia por job, el dia que se le agregue la
     * hora entra en el que se estaba mirando y la bandeja queda con dos formatos.
     *
     * 🔴 La fecha sale de created_at de la SUGERENCIA, no de now(): el job corre despues
     * y una corrida que quedo encolada de un dia para el otro se titularia con la fecha
     * equivocada. now() es solo la red por si created_at viniera null.
     *
     * @param string $prefijo Nombre del tipo, ej. 'Sugerencia de stock'
     * @param mixed $momento created_at de la sugerencia (Carbon o null)
     * @return string
     */
    public static function titulo_con_fecha($prefijo, $momento)
    {
        $fecha = is_null($momento) ? now() : $momento;

        return $prefijo . ' ' . $fecha->format('d/m/Y');
    }

    /**
     * Mensajes del hilo en orden de llegada. El orden por id alcanza porque
     * los mensajes solo se agregan al final, nunca se reordenan.
     */
    public function messages()
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }
}
