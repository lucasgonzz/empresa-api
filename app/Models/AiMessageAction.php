<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Tarjeta de carga que el asistente de IA propone y la persona confirma o cancela (misión
 * asistente-ia-acciones, 15/9/2026).
 *
 * El ciclo de vida lo manejan AccionesIaHelper (crear, reemplazar, descartar) y
 * EjecutorAccionesIaHelper (confirmar y cancelar, con candado). Este modelo solo sabe dos cosas:
 * cómo se serializa para la SPA y cuándo una propuesta ya venció.
 *
 * estado:
 * - 'propuesta': se puede confirmar o cancelar.
 * - 'confirmada': se ejecutó; `resultado` trae el texto y la ruta.
 * - 'cancelada': la persona tocó Cancelar.
 * - 'reemplazada': una corrección posterior armó otra tarjeta para la misma carga.
 * - 'vencida': propuesta de más de HORAS_VENCIMIENTO horas (ver getEstadoAttribute()).
 * - 'descartada': el mensaje que la propuso terminó en error; la SPA no la pinta.
 */
class AiMessageAction extends Model
{
    /**
     * Horas después de las cuales una propuesta sin resolver se lee como vencida. Pasado ese
     * tiempo los datos de la tarjeta (saldos, cajas abiertas, la fecha de "hoy") pueden no ser los
     * que la persona tiene en la cabeza al tocar Confirmar.
     */
    const HORAS_VENCIMIENTO = 24;

    const ESTADO_PROPUESTA   = 'propuesta';
    const ESTADO_CONFIRMADA  = 'confirmada';
    const ESTADO_CANCELADA   = 'cancelada';
    const ESTADO_REEMPLAZADA = 'reemplazada';
    const ESTADO_VENCIDA     = 'vencida';
    const ESTADO_DESCARTADA  = 'descartada';

    const TIPO_GASTO           = 'gasto';
    const TIPO_PAGO            = 'pago';
    const TIPO_TAREA_NUEVA     = 'tarea_nueva';
    const TIPO_TAREA_EDITAR    = 'tarea_editar';
    const TIPO_TAREA_COMPLETAR = 'tarea_completar';

    /* Misión agente-ia-mano-derecha (16/9/2026): armar un combo y armar una oferta por cliente. */
    const TIPO_COMBO           = 'combo';
    const TIPO_OFERTA          = 'oferta';

    /**
     * La compra que nace para recibir la foto de una factura (misión asistente-por-whatsapp,
     * 16/9/2026). Al confirmarla se crea (o se reusa) la compra del proveedor y se le cuelga el
     * escaneo; los artículos los carga el dueño después, revisando el escaneo desde la pantalla.
     */
    const TIPO_COMPRA_CON_FACTURA = 'compra_con_factura';

    /**
     * Asignar como foto de una sucursal una foto que el dueño mandó (misión
     * foto-sucursal-y-asistente-configurable, 17/9/2026). Es una carga inocua y reversible —no toca
     * plata ni borra nada—, así que es la única que el modo "resuelto" auto-confirma
     * (HerramientasDeCarga::AUTO_CONFIRMABLES).
     */
    const TIPO_FOTO_SUCURSAL = 'foto_sucursal';

    /*
     * Misión asistente-masivas-imagenes-y-remito (19/9/2026): el asistente opera el catálogo en lote
     * y los diseños de PDF. Desde acá la foto de sucursal ya NO es la única auto-confirmable: mandar
     * a buscar imágenes (categorías y artículos) y cambiar un diseño de PDF son cargas inocuas y
     * reversibles y también entran en HerramientasDeCarga::AUTO_CONFIRMABLES. La actualización
     * masiva NUNCA: siempre la confirma la persona, esté en la confianza que esté.
     */
    const TIPO_IMAGENES_CATEGORIAS  = 'imagenes_categorias';
    const TIPO_IMAGEN_CATEGORIA     = 'imagen_categoria';
    const TIPO_IMAGENES_ARTICULOS   = 'imagenes_articulos';
    const TIPO_ACTUALIZACION_MASIVA = 'actualizacion_masiva';
    const TIPO_DISENO_PDF           = 'diseno_pdf';

    /*
     * Misión asistente-omnisciente (21/9/2026): el ABM genérico y la venta. Un alta, una edición o
     * una baja de cualquier entidad del catálogo de escritura (CatalogoDeEscrituraIaHelper) que se
     * ejecuta llamando al MISMO controller que usa la pantalla, y una venta por el camino de Vender
     * (PropuestaVentaIaHelper). 🔴 NINGUNO de los cuatro se auto-confirma, esté la confianza como
     * esté: crear, cambiar o borrar datos del negocio lo confirma siempre la persona.
     */
    const TIPO_ALTA    = 'alta';
    const TIPO_EDICION = 'edicion';
    const TIPO_BAJA    = 'baja';
    const TIPO_VENTA   = 'venta';

    protected $guarded = [];

    /**
     * `datos` es el payload interno de ejecución y `clave`/`referencia_updated_at` son mecánica del
     * reemplazo y del control de edición concurrente: nada de eso es de la tarjeta y la SPA no los
     * tiene que poder leer (contrato §2.3 del plan).
     *
     * @var array<int,string>
     */
    protected $hidden = ['datos', 'clave', 'referencia_updated_at'];

    /**
     * `resultado` se castea como OBJETO y no como array a propósito: la ruta lleva `params: {}` y
     * con el cast de array un objeto vacío vuelve de la base como `[]` y viaja a la SPA como una
     * lista. Con 'object' el `{}` se conserva de punta a punta.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'ai_conversation_id' => 'integer',
        'ai_message_id'      => 'integer',
        'user_id'            => 'integer',
        'auth_user_id'       => 'integer',
        'datos'              => 'array',
        'presentacion'       => 'array',
        'resultado'          => 'object',
        'resuelta_at'        => 'datetime',
    ];

    /**
     * El estado ya viene resuelto: una propuesta con más de HORAS_VENCIMIENTO horas se lee como
     * 'vencida' aunque en la base siga 'propuesta'. Así no hace falta un cron que las barra y la SPA
     * mira un solo campo. El paso real a 'vencida' en la base lo escribe el ejecutor cuando alguien
     * intenta confirmarla (afuera de la transacción, para que el rollback no se lo lleve).
     *
     * @param  string|null  $valor  Estado guardado en la base.
     * @return string|null
     */
    public function getEstadoAttribute($valor)
    {
        if ($valor === self::ESTADO_PROPUESTA && $this->vencio()) {
            return self::ESTADO_VENCIDA;
        }

        return $valor;
    }

    /**
     * El estado tal como está guardado, sin la lectura del vencimiento.
     *
     * @return string|null
     */
    public function estado_guardado()
    {
        return isset($this->attributes['estado']) ? $this->attributes['estado'] : null;
    }

    /**
     * true si la tarjeta se creó hace más de HORAS_VENCIMIENTO horas.
     *
     * @return bool
     */
    public function vencio()
    {
        $creada = $this->created_at;

        if (is_null($creada)) {
            return false;
        }

        return $creada->lt(Carbon::now()->subHours(self::HORAS_VENCIMIENTO));
    }

    /**
     * Mensaje del assistant que propuso la tarjeta.
     */
    public function message()
    {
        return $this->belongsTo(AiMessage::class, 'ai_message_id');
    }

    /**
     * Sin relaciones que cargar por defecto: la tarjeta ya trae su presentación armada.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}
