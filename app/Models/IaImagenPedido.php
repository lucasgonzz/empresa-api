<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un pedido de imagen generada del agente (misión app-ia-y-memoria, 29/10/2026, §4 E1.7 del plan).
 *
 * El dueño le pide una imagen al agente desde la app ("haceme una placa para Instagram con la
 * promo"); el agente llama a pedir_imagen, que deja este pedido y un bloque `imagen_generada`
 * `pendiente` en su respuesta. El pedido viaja al admin (EnviarPedidoDeImagenAlAdminJob, contrato
 * C2.1), la PC de Lucas lo genera con su cuenta de ChatGPT y el admin devuelve la imagen
 * (admin-sync/ia-imagenes/{uuid}/resultado, C1.4). Ahí se guarda en el disco `local` y el bloque pasa
 * a `lista`.
 *
 * `user_id` es el DUEÑO (la tenencia de GET ia-imagenes/{uuid} es por dueño). `uuid` es la identidad
 * que comparten este API y el admin. `estado`: pendiente | lista | fallida | no_disponible (el admin
 * no tiene la cola: versión vieja o sin configuración). `path` la ruta en el disco local
 * (`ia_imagenes/{owner}/{uuid}.{ext}`) cuando está lista.
 */
class IaImagenPedido extends Model
{
    const ESTADO_PENDIENTE = 'pendiente';

    const ESTADO_LISTA = 'lista';

    const ESTADO_FALLIDA = 'fallida';

    const ESTADO_NO_DISPONIBLE = 'no_disponible';

    protected $table = 'ia_imagen_pedidos';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'enviado_al_admin_en' => 'datetime',
    ];

    /**
     * El mensaje del asistente que lleva el bloque de esta imagen.
     */
    public function mensaje()
    {
        return $this->belongsTo(AiMessage::class, 'ai_message_id');
    }
}
