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
 * - 'en_curso': se está ejecutando en este momento; no se puede confirmar ni cancelar (ver abajo).
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

    /**
     * La tarjeta se está ejecutando AHORA (misión asistente-omnisciente, 21/9/2026).
     *
     * Es el candado contra el segundo clic de las cargas que se ejecutan con la transacción del
     * ejecutor ya cerrada —el ABM genérico y la venta, ver EjecutorAccionesIaHelper::
     * ejecutar_en_dos_etapas()—. Para las demás no existe: ahí el candado sigue siendo el
     * `lockForUpdate` sostenido por la transacción que envuelve toda la ejecución.
     *
     * Es un estado de paso, de los segundos que tarda la carga: si sale bien queda 'confirmada' y
     * si falla vuelve a 'propuesta' con su `error_mensaje`, igual que antes. La SPA no lo conoce y
     * no necesita conocerlo: AccionCard.vue pinta como "cerrada" —sin botones— cualquier estado
     * que no sea 'propuesta' ni 'confirmada', que es exactamente lo que corresponde mientras la
     * carga corre.
     */
    const ESTADO_EN_CURSO    = 'en_curso';

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

    /**
     * Unificar los bancos de los cheques (misión cheques-endoso-y-bancos, 21/9/2026): crear los
     * bancos del catálogo a partir de los textos libres y asignárselos a los cheques. Es masiva
     * (toca N cheques) y NO entra en HerramientasDeCarga::AUTO_CONFIRMABLES: siempre la confirma la
     * persona.
     */
    const TIPO_UNIFICAR_BANCOS = 'unificar_bancos_cheques';

    /**
     * Colgarle a un ARTÍCULO una foto que el dueño mandó (misión asistente-ventas-y-fotos,
     * 21/9/2026). Es la hermana de TIPO_FOTO_SUCURSAL y la mecánica de la foto es la misma, pero
     * 🔴 NO ENTRA en HerramientasDeCarga::AUTO_CONFIRMABLES, ni siquiera con el dueño en "resuelto"
     * (decisión de Lucas, 21/9/2026): acá el destino se INFIERE de un nombre que puede venir
     * inexacto, y una foto puesta en el artículo equivocado se publica en la tienda online (dispara
     * Tienda Nube y Mercado Libre). La de sucursal se auto-confirma justamente porque no puede
     * equivocarse de destino: hay pocas y no se publican en ningún lado.
     */
    const TIPO_FOTO_ARTICULO = 'foto_articulo';

    /*
     * Las cuatro capacidades nuevas de la misión asistente-capacidades-y-hilos (22/9/2026), que
     * tapan cuatro de los diez "no puedo" que el agente contestó el 22/9 en demo3. Van al FINAL de
     * la lista por la misma razón que las tools: cada misión suma lo suyo atrás de lo que había.
     */

    /**
     * Mover stock de un depósito a otro (mensaje #24 del diagnóstico). Es el mismo movimiento que
     * hace el modal "Movimiento de depósitos" del Listado, por `POST api/stock-movement`.
     */
    const TIPO_MOVIMIENTO_STOCK = 'movimiento_stock';

    /**
     * Dejar el stock de UN depósito en un número (mensaje #42). Es la única vía que además puede
     * ABRIR un depósito para un artículo que todavía no lo tenía.
     */
    const TIPO_STOCK_DEPOSITO = 'stock_deposito';

    /**
     * Crear un presupuesto (mensaje #56), por el mismo `POST api/budget` que usa la pantalla de
     * Vender con "guardar como presupuesto". 🔴 No toca stock, ni caja, ni cuenta corriente: eso
     * pasa recién al confirmarlo desde la pantalla de Presupuestos.
     */
    const TIPO_PRESUPUESTO = 'presupuesto';

    /**
     * Darle o sacarle un permiso a un empleado (mensaje #44), por `PUT api/employee/{id}`.
     *
     * 🔴 ESTE NO SE AUTO-EJECUTA EN NINGÚN MODO, ni siquiera en "directo": está en
     * HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES. El endpoint reemplaza la lista ENTERA de
     * permisos y reescribe la contraseña en cada llamada, así que una tarjeta mal armada deja a un
     * empleado sin permisos o sin poder entrar. Lo confirma siempre una persona, mirando qué queda.
     */
    const TIPO_PERMISO_EMPLEADO = 'permiso_empleado';

    /*
     * Las acciones de pantalla de la misión asistente-mcp (22/9/2026): "literalmente todo lo que se
     * hace desde la interfaz", llamando a la misma ruta y al mismo controller que llama la pantalla
     * (CatalogoDeAccionesDePantallaIaHelper decide cuáles; EjecutorAccionDePantallaIaHelper las
     * corre). Van al FINAL de la lista por la misma razón que las tools: cada misión suma lo suyo
     * atrás de lo que había.
     */

    /**
     * Una acción POST o PUT de una pantalla (abrir o cerrar una caja, confirmar o anular un
     * presupuesto, editar una venta, facturar...). `datos` guarda {metodo, ruta con sus {param},
     * parametros, cuerpo}. Entra en HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO: con el dueño en
     * "directo" se ejecuta en el acto, como el resto de lo que hace la pantalla; en los otros dos
     * modos deja tarjeta.
     */
    const TIPO_ACCION_PANTALLA = 'accion_pantalla';

    /**
     * Un DELETE de una pantalla. 🔴 Está en HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES: deja
     * tarjeta en los TRES modos, igual que proponer_baja y por el mismo motivo: si el modelo detrás
     * de esa ruta no usa SoftDeletes, el borrado no se deshace, y acá ni siquiera se conoce la
     * tabla como para avisar qué queda colgado.
     */
    const TIPO_BORRADO_PANTALLA = 'borrado_pantalla';

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
