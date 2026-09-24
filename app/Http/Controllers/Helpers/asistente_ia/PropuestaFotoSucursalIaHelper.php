<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Models\Address;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;

/**
 * La foto de una sucursal, asignada por el asistente (misión foto-sucursal-y-asistente-configurable,
 * 17/9/2026).
 *
 * El dueño manda una foto por WhatsApp diciendo "esta es la foto de tal sucursal" y el asistente la
 * asigna como `addresses.image_url` de esa sucursal, que es la misma columna que carga el ABM (el
 * campo "Logo" de la sucursal, tarea 17) y la misma que imprime el logo de los comprobantes de esa
 * sucursal. La foto se guarda en `storage/app/public` como PNG, con el MISMO criterio que
 * ImageController::setImage() para `address` (FPDF solo parsea jpg/png/gif, y ese logo termina en un
 * comprobante).
 *
 * 🔴 ES LA ÚNICA CARGA QUE EL MODO "RESUELTO" AUTO-CONFIRMA. No toca plata, no borra nada y es
 * reversible desde el ABM: por eso está en HerramientasDeCarga::AUTO_CONFIRMABLES. Todo lo que mueve
 * plata (gastos, pagos, compras, combos, ofertas) sigue pidiendo la confirmación de la persona,
 * siempre.
 *
 * 🔴 LA FOTO NO SALE DEL PROMPT: SALE DE LAS IMÁGENES SIN GESTIONAR DE LA CONVERSACIÓN, igual que
 * PropuestaCompraConFacturaIaHelper. Se toma la última foto sin gestionar que mandó el dueño.
 * En la práctica esas fotos solo existen en el canal WhatsApp (ai_message_imagenes las escribe solo
 * AdminSync\AsistenteController): desde la pantalla del sistema no hay forma de adjuntar una foto, y
 * ahí la herramienta simplemente contesta que no tiene ninguna. Se declara con las de carga igual.
 */
class PropuestaFotoSucursalIaHelper
{
    /*
     * Acá vivía MENSAJES_PARA_LA_FOTO = 6. Desde la misión asistente-fotos-barras-y-compras
     * (24/9/2026) la foto la busca FotosDeLaConversacionIaHelper: por tiempo (24 horas) y sólo
     * entre las que mandó el dueño, igual que la foto de un artículo (ver ahí el porqué).
     */

    /** Lo que se le contesta a quien no es dueño/admin: la foto de la sucursal es config del negocio. */
    const MENSAJE_SIN_PERMISO = 'Solo el dueño puede cambiar la foto de una sucursal.';

    /**
     * Herramienta proponer_foto_sucursal.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  sucursal (opcional si hay una sola), reemplaza_a
     * @return array  Respuesta de la herramienta (propuesta creada o respuesta de negocio).
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input)
    {
        if (!PermisosIaHelper::es_admin($contexto->persona)) {

            return RespuestaDeCargaIa::error(self::MENSAJE_SIN_PERMISO);
        }

        $sucursal = self::resolver_sucursal($contexto, EntradaDeCargaIa::texto($input, 'sucursal'));

        if (RespuestaDeCargaIa::es_negativa($sucursal)) {

            return $sucursal;
        }

        $foto = self::ultima_foto_sin_gestionar($contexto, $mensaje);

        if (is_null($foto)) {

            return RespuestaDeCargaIa::error(
                'No tengo ninguna foto tuya sin usar de las últimas ' . FotosDeLaConversacionIaHelper::HORAS . ' horas. Mandámela y te la asigno a la sucursal.'
            );
        }

        $nombre_sucursal = self::nombre_de_sucursal($sucursal);

        $datos = [
            'address_id' => (int) $sucursal->id,
            'sucursal'   => $nombre_sucursal,
            'imagen_id'  => (int) $foto->id,
        ];

        $renglones = [
            ['etiqueta' => 'Sucursal', 'valor' => $nombre_sucursal],
            ['etiqueta' => 'Qué se hace', 'valor' => 'La foto va a quedar como foto de la sucursal'],
        ];

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_FOTO_SUCURSAL,
            self::clave($sucursal->id),
            $datos,
            ['titulo' => 'Foto de la sucursal', 'renglones' => $renglones, 'aviso' => null],
            EntradaDeCargaIa::valor($input, 'reemplaza_a')
        );

        return AccionesIaHelper::respuesta_de_propuesta($creada, 'Foto de la sucursal ' . $nombre_sucursal);
    }

    /**
     * Identidad de la carga para el reemplazo: una foto nueva para la misma sucursal reemplaza la
     * tarjeta, y la de otra sucursal no la pisa.
     *
     * @param  int  $address_id
     * @return string
     */
    public static function clave($address_id)
    {
        return 'foto_sucursal:' . (int) $address_id;
    }

    /**
     * Guarda la foto como PNG, la asigna a la sucursal y sella la foto. Corre adentro de la
     * transacción de EjecutorAccionesIaHelper y con la persona ya autenticada.
     *
     * 🔴 TODO SE RE-VERIFICA ACÁ. Entre la propuesta y la confirmación pueden pasar horas: la
     * sucursal pudo borrarse y la foto pudo usarla otra tarjeta. La foto se relee con
     * `lockForUpdate` (mismo motivo que la compra con factura: dos tarjetas no pueden llevarse la
     * misma foto).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException  422 si la carga ya no se puede hacer.
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion)
    {
        if (!PermisosIaHelper::es_admin($contexto->persona)) {

            throw new AccionIaException(422, self::MENSAJE_SIN_PERMISO);
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $sucursal = Address::where('user_id', $contexto->owner_id)
                            ->where('id', isset($datos['address_id']) ? (int) $datos['address_id'] : 0)
                            ->first();

        if (is_null($sucursal)) {

            throw new AccionIaException(422, 'Esa sucursal ya no existe entre las tuyas. Pedímelo de nuevo.');
        }

        $imagen = self::foto_bloqueada($contexto, isset($datos['imagen_id']) ? (int) $datos['imagen_id'] : 0);

        if (is_null($imagen)) {

            throw new AccionIaException(422, 'Esa foto ya se usó o no está disponible. Mandámela de nuevo.');
        }

        $binario = AsistenteImagenHelper::binario($imagen);

        if (is_null($binario)) {

            throw new AccionIaException(422, 'No pude leer esa foto. Mandámela de nuevo.');
        }

        $name = self::guardar_png($binario);

        if (is_null($name)) {

            throw new AccionIaException(422, 'No pude guardar la foto. Probá de nuevo en un momento.');
        }

        $sucursal->image_url = ApiUrlHelper::storage($name);
        $sucursal->save();

        AsistenteImagenHelper::marcar_gestionadas([(int) $imagen->id]);

        return [
            'texto' => 'Foto asignada a la sucursal ' . self::nombre_de_sucursal($sucursal),
            /*
             * El ABM, donde el dueño ve y edita las sucursales. Sin sub-vista puntual: el ruteo del
             * SPA es /abm/:view?/:sub_view? y el ABM base alcanza para llegar a las sucursales. En
             * WhatsApp esta ruta no se usa (no hay navegación); en la pantalla la foto no llega.
             */
            'ruta'  => [
                'name'   => 'abm',
                'params' => new \stdClass(),
                'texto'  => 'Ver en Sucursales',
            ],
        ];
    }

    /**
     * La sucursal que nombró el dueño, o la respuesta de negocio que pide desambiguar. Una cuenta
     * sin ninguna sucursal es un error: no hay dónde asignar la foto.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $nombre
     * @return \App\Models\Address|array
     */
    protected static function resolver_sucursal(ContextoDeCargaIa $contexto, $nombre)
    {
        $sucursales = Address::where('user_id', $contexto->owner_id)->orderBy('id')->get();

        if (count($sucursales) === 0) {

            return RespuestaDeCargaIa::error(
                'No tenés ninguna sucursal cargada. Creala desde el sistema y volvé a pedírmelo.'
            );
        }

        if (count($sucursales) === 1) {

            return $sucursales[0];
        }

        $nombre = trim((string) $nombre);

        if ($nombre !== '') {

            foreach ($sucursales as $sucursal) {

                if (mb_stripos(self::nombre_de_sucursal($sucursal), $nombre) !== false) {

                    return $sucursal;
                }
            }
        }

        $opciones = [];

        foreach ($sucursales as $sucursal) {

            $opciones[] = ['id' => (int) $sucursal->id, 'sucursal' => self::nombre_de_sucursal($sucursal)];
        }

        return RespuestaDeCargaIa::faltan(['a qué sucursal le asigno la foto'], ['sucursales' => $opciones]);
    }

    /**
     * La foto más nueva que el DUEÑO mandó en las últimas 24 horas y no se usó, o null. Se toma UNA
     * sola: la más nueva es la que acaba de mandar. La ventana y el filtro por rol viven en
     * FotosDeLaConversacionIaHelper, compartidos con la foto de un artículo y la compra con factura.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje
     * @return \App\Models\AiMessageImagen|null
     */
    protected static function ultima_foto_sin_gestionar(ContextoDeCargaIa $contexto, AiMessage $mensaje)
    {
        return FotosDeLaConversacionIaHelper::la_mas_nueva($contexto, $mensaje);
    }

    /**
     * La foto de la tarjeta si sigue sin gestionar, CON LA FILA BLOQUEADA hasta el commit. El
     * candado es lo que evita que dos tarjetas se lleven la misma foto (ver el mismo patrón en
     * PropuestaCompraConFacturaIaHelper::imagenes_por_id).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  int  $imagen_id
     * @return \App\Models\AiMessageImagen|null
     */
    protected static function foto_bloqueada(ContextoDeCargaIa $contexto, $imagen_id)
    {
        if ($imagen_id <= 0) {

            return null;
        }

        return AiMessageImagen::where('user_id', $contexto->owner_id)
                                ->where('id', $imagen_id)
                                ->sinGestionar()
                                ->lockForUpdate()
                                ->first();
    }

    /**
     * Guarda el binario como PNG en storage/app/public y devuelve el nombre del archivo, o null si
     * no se pudo. Mismo criterio que ImageController::setImage() para `address`: nombre
     * time().rand(1,100000).'.png' y guardado con Intervention (que infiere el formato de la
     * extensión).
     *
     * @param  string  $binario
     * @return string|null
     */
    protected static function guardar_png($binario)
    {
        $name = time() . rand(1, 100000) . '.png';

        try {

            $manager = new ImageManager();
            $img = $manager->make($binario);
            $img->save(storage_path() . '/app/public/' . $name);

        } catch (\Throwable $e) {

            Log::warning('PropuestaFotoSucursalIaHelper: no se pudo guardar la foto de la sucursal como PNG', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $name;
    }

    /**
     * Cómo se nombra una sucursal: lo que muestra el select del formulario (`street` con el número),
     * igual que PropuestaCompraConFacturaIaHelper::nombre_de_sucursal.
     *
     * @param  \App\Models\Address  $sucursal
     * @return string
     */
    protected static function nombre_de_sucursal(Address $sucursal)
    {
        $nombre = trim((string) $sucursal->street);

        $numero = trim((string) $sucursal->street_number);

        if ($numero !== '') {

            $nombre = trim($nombre . ' ' . $numero);
        }

        return $nombre === '' ? 'Sucursal ' . $sucursal->id : $nombre;
    }
}
