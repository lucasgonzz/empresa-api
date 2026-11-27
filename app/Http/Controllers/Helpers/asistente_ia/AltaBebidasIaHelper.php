<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessageAction;

/** Alta de bebidas optativa: la presentación de venta y la caja logística son decisiones distintas. */
class AltaBebidasIaHelper
{
    const CLAVE = 'bebida';

    public static function disponible($owner): bool
    {
        return PermisosIaHelper::tiene_extencion($owner, 'vinoteca');
    }

    public static function activa($owner): bool
    {
        return !is_null($owner) && (bool) $owner->agente_alta_bebidas && self::disponible($owner);
    }

    public static function instrucciones(): string
    {
        /*
         * Misión asistente-truvari-pedidos-fallidos (13/11/2026, B7): este bloque viaja con las
         * instrucciones de carga y su «cajas × botellas» se filtró a un presupuesto («una caja» salió
         * como 6 botellas). La primera oración lo acota: solo el alta y su stock inicial.
         *
         * Misión asistente-diario-20261117 (B2, revisión independiente): este bloque va DESPUÉS del bloque de
         * carga y su «Buscá automáticamente la foto» le ganaba a la regla de la foto anunciada que no llegó. La
         * excepción va acá, en la misma oración.
         */
        return 'Esto aplica SOLO al alta de bebidas (proponer_alta) y a su stock inicial; NUNCA a ventas, presupuestos ni pedidos: ahí la cantidad son unidades de venta del artículo y "una caja" es 1 (con presentación, el precio ya es el de la caja). '
            .'Alta completa de bebidas activa: para BEBIDAS mandá dentro de datos la clave bebida como objeto {es_vino: booleano, botellas_por_caja: entero, cajas: entero opcional, cajas_confirmadas: booleano, sin_imagen: booleano opcional, sin_bodega: booleano opcional, sin_proveedor: booleano opcional} de proponer_alta. Los accesorios, insumos y otros artículos siguen por el alta genérica SIN bebida. Reuní nombre, proveedor y bodega EXISTENTES, costo, IVA y margen según listas. '
            .'Si el dueño dice «sin bodega» o «sin proveedor», mandá bebida.sin_bodega=true / bebida.sin_proveedor=true (booleanos opcionales) y NO vuelvas a preguntar por eso ni crees una bodega o un proveedor para sortearlo; sin_bodega vale solo para bebidas que NO son vino (un vino siempre lleva bodega y cepa), sin_proveedor vale también para vinos. Con esas claves no mandes provider_id ni bodega_id. Una sola tarjeta de confirmación (que muestra «Sin bodega» / «Sin proveedor»); si el dueño dice «no me preguntes más», armá la tarjeta en ese mismo turno con lo que hay. '
            .'El tipo de bebida se guarda en categoría/subcategoría de Crear; nunca en marca ni en un campo inventado. '
            .'Mandá bebida.es_vino booleano; solo vinos llevan cepa EXISTENTE. Nunca crees proveedor, bodega o cepa sin consentimiento. '
            .'Presentación es la cantidad de unidades de VENTA y puede cambiar el precio según la configuración: usa el mismo cálculo de Crear. Preguntá si corresponde vender por botella (1) o por presentación. '
            .'Botellas por caja logística va en bebida.botellas_por_caja y no implica presentación de venta. '
            .'Si el stock se dio en cajas, mandá bebida.cajas y bebida.cajas_confirmadas=true solo después de que el dueño haya dicho que son cajas; '
            .'el sistema convierte una sola vez. No mandes además stock_inicial. Si el negocio tiene más de una sucursal, elegí explícitamente el depósito para ese ingreso; con una sola, no lo mandes ni lo preguntes. '
            .'Buscá automáticamente la foto con buscar_imagen_de_bebida por la identificación real, sin inventar código de barras, '
            .'salvo que la persona te haya dicho que te manda la foto («te paso la foto», «esta es la foto») y todavía no llegó ninguna imagen: esperala, decile en una línea que la esperás y en ese turno no busques en internet. '
            .'Si no hay coincidencia verificada, informalo y mandá bebida.sin_imagen=true para mostrar ese límite. '
            .'Una única propuesta muestra datos, conversión y depósito; SIEMPRE requiere confirmación, incluso en modo directo. '
            .'La foto encontrada en INTERNET nunca va dentro del alta (el artículo se crea sin ella): cuando la persona confirme el alta, proponé la foto APARTE con proponer_foto_articulo (articulo_id del alta e imagen_id de foto_aparte), que la muestra y espera su propio sí. Nunca la pongas sin preguntar.';
    }

    /**
     * Misión asistente-diario-20261121 (A1): reglas para cargar el pedido a un proveedor en un negocio con la
     * extensión Vinoteca (precio por botella = costo base, pedido entero tras una confirmación, listado de
     * lo que subió o bajó). Es texto de prompt: no cambia ninguna cuenta del sistema.
     *
     * @return string
     */
    public static function instrucciones_de_pedido_a_proveedor(): string
    {
        return 'Pedido a proveedor en una vinoteca: el «precio por botella» o «por unidad» que da el dueño de un pedido es el COSTO BASE por unidad: se edita `cost` (proponer_edicion) y el stock que llega en cajas va con proponer_stock_en_deposito. Si dijo «el costo base», NO preguntes si es costo o precio de venta. '
            .'Un pedido con varios renglones cuyo detalle ya está claro se carga entero tras UNA sola confirmación de los casos ambiguos: no vuelvas a preguntar lo que el dueño ya contestó. '
            .'Si pide el listado de lo que bajó o subió de precio y en qué porcentaje, armalo AL TERMINAR (después de aplicar costo y margen) con el PRECIO DE VENTA final por unidad de cada artículo, no con el costo, comparándolo contra el precio anterior que leíste ANTES de editar.';
    }

    /**
     * true si la foto del alta la encontró el asistente en INTERNET (no la mandó el dueño). P6 (16/11/2026).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $imagen_id
     * @return bool
     */
    public static function es_foto_de_internet(ContextoDeCargaIa $contexto, $imagen_id): bool
    {
        if (is_array(\Illuminate\Support\Facades\Cache::get('alta_bebida_imagen:'.(int) $imagen_id))) {
            return true;
        }
        $foto = FotosDeLaConversacionIaHelper::por_id($contexto, $imagen_id);
        return !is_null($foto) && !FotosDeLaConversacionIaHelper::la_mando_el_dueno($foto);
    }

    /**
     * true si la bebida trae la clave booleana indicada en true (p. ej. sin_bodega, sin_proveedor).
     * Solo el booleano true cuenta: un texto o un número no declaran nada (conservador).
     *
     * @param  array  $bebida  El objeto bebida de la propuesta.
     * @param  string  $clave  La clave a mirar.
     * @return bool
     */
    public static function declarado(array $bebida, string $clave): bool
    {
        return isset($bebida[$clave]) && $bebida[$clave] === true;
    }

    public static function heredar(ContextoDeCargaIa $contexto, $reemplaza_a, $bebida): array
    {
        $anterior = is_numeric($reemplaza_a) ? AiMessageAction::where('id', (int) $reemplaza_a)
            ->where('ai_conversation_id', $contexto->conversation->id)->where('tipo', AiMessageAction::TIPO_ALTA)
            ->whereIn('estado', [AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::ESTADO_REEMPLAZADA])->first() : null;
        $datos = $anterior && is_array($anterior->datos) ? $anterior->datos : [];
        $base = isset($datos[self::CLAVE]) && is_array($datos[self::CLAVE]) ? $datos[self::CLAVE] : [];
        return array_replace($base, is_array($bebida) ? $bebida : []);
    }

    public static function preparar(ContextoDeCargaIa $contexto, array $validado, array $extras, array $bebida, bool $stock_heredado = false): array
    {
        if (!self::activa($contexto->owner)) {
            return RespuestaDeCargaIa::error('El alta completa de bebidas está desactivada. No se cargó nada.');
        }
        $p = $validado['payload'];
        $faltan = [];
        /*
         * Misión asistente-diario-20261121 (A2): el dueño puede decir «sin bodega» / «sin proveedor». ArticleController
         * no exige ninguno de los dos, así que una bebida que NO es vino puede ir sin bodega, y cualquier bebida (vino
         * incluido) sin proveedor. Se declara con bebida.sin_bodega / bebida.sin_proveedor (booleanos opcionales).
         * Sin esas claves todo se comporta como antes. Un vino conserva la exigencia de bodega.
         */
        $sin_proveedor = self::declarado($bebida, 'sin_proveedor');
        $sin_bodega = self::declarado($bebida, 'sin_bodega') && isset($bebida['es_vino']) && $bebida['es_vino'] === false;
        if ($sin_proveedor && !empty($p['provider_id'])) {
            return RespuestaDeCargaIa::error('Dijiste «sin proveedor» pero mandaste un proveedor. Reenviá sin una de las dos cosas. No se cargó nada.');
        }
        if ($sin_bodega && !empty($p['bodega_id'])) {
            return RespuestaDeCargaIa::error('Dijiste «sin bodega» pero mandaste una bodega. Reenviá sin una de las dos cosas. No se cargó nada.');
        }
        $requeridos = ['name' => 'nombre', 'provider_id' => 'proveedor existente', 'bodega_id' => 'bodega existente',
            'cost' => 'costo', 'category_id' => 'tipo de bebida (categoría)', 'presentacion' => 'presentación de venta'];
        if ($sin_proveedor) unset($requeridos['provider_id']);
        if ($sin_bodega) unset($requeridos['bodega_id']);
        foreach ($requeridos as $k => $nombre) {
            if (!array_key_exists($k, $p) || PropuestaGenericaIaHelper::vacio($p[$k])) {
                $faltan[] = $nombre;
            }
        }
        if (!isset($bebida['es_vino']) || !is_bool($bebida['es_vino'])) {
            $faltan[] = 'si es vino o es otra bebida';
        } elseif ($bebida['es_vino'] && empty($p['cepa_id'])) {
            $faltan[] = 'cepa existente del vino';
        } elseif (!$bebida['es_vino'] && !empty($p['cepa_id'])) {
            return RespuestaDeCargaIa::error('Las bebidas que no son vino van sin cepa. Reenviá cepa_id vacío. No se cargó nada.');
        }
        $botellas = isset($bebida['botellas_por_caja']) ? $bebida['botellas_por_caja'] : null;
        if (!is_numeric($botellas) || (float) $botellas < 1 || (float) $botellas != (int) $botellas || (float) $botellas > 10000) {
            $faltan[] = 'cantidad entera de botellas por caja logística';
        }
        if (isset($p['presentacion']) && (!is_numeric($p['presentacion']) || (float) $p['presentacion'] <= 0)) {
            $faltan[] = 'presentación de venta mayor a cero';
        }
        if (MargenesPorListaIaHelper::aplica($contexto->owner)) {
            if (empty($extras[MargenesPorListaIaHelper::CLAVE])) $faltan[] = 'margen y lista de precios (o todas)';
        } elseif (!isset($p['percentage_gain'])) {
            $faltan[] = 'margen de ganancia';
        }
        if (empty($extras['imagen_id']) && empty($extras['con_foto_de_la_conversacion']) && empty($bebida['sin_imagen'])) {
            $faltan[] = 'búsqueda de imagen verificada (o informar sin_imagen)';
        }
        if (!empty($extras['imagen_id'])) {
            $evidencia = \Illuminate\Support\Facades\Cache::get('alta_bebida_imagen:'.(int) $extras['imagen_id']);
            if (is_array($evidencia) && ((int) $evidencia['owner_id'] !== $contexto->owner_id
                || (int) $evidencia['conversation_id'] !== (int) $contexto->conversation->id
                || mb_strtolower(trim((string) ($p['name'] ?? ''))) !== mb_strtolower(trim($evidencia['nombre'])))) {
                return RespuestaDeCargaIa::error('La imagen buscada corresponde a otra identificación. Buscá nuevamente la foto del producto real.');
            }
        }
        if (count($faltan)) return RespuestaDeCargaIa::faltan($faltan, ['instrucciones' => self::instrucciones()]);

        /*
         * M3 (misión asistente-diario-20261117): el renglón «Caja logística» decía siempre que la caja «se usa
         * para convertir este ingreso de stock», también en un alta sin cajas, donde no hay ingreso. Con cajas
         * queda ese texto; sin cajas, que se vende por botella (texto_sin_cajas()). El resultado del alta
         * (EjecutorGenericoIaHelper) sigue el mismo criterio. Con la propiedad de distribuidora las botellas
         * por caja SÍ se guardan, como unidades por bulto (PropuestaGenericaIaHelper las pone en el payload con
         * la misma condición): el texto lo dice, para no contradecir el renglón «Unidades por bulto guardadas».
         */
        $texto_de_la_caja = isset($bebida['cajas'])
            ? 'Se usa para convertir este ingreso de stock; no modifica la presentación de venta.'
            : self::texto_sin_cajas((int) $botellas, false, PermisosIaHelper::tiene_extencion($contexto->owner, 'articulos_con_propiedades_de_distribuidora'));
        $renglones = [
            ['etiqueta' => 'Tipo de bebida', 'valor' => $bebida['es_vino'] ? 'Vino' : 'Otra bebida (sin cepa)'],
        ];
        // A2: la tarjeta deja explícito lo que va vacío por decisión del dueño.
        if ($sin_proveedor) $renglones[] = ['etiqueta' => 'Proveedor', 'valor' => 'Sin proveedor'];
        if ($sin_bodega) $renglones[] = ['etiqueta' => 'Bodega', 'valor' => 'Sin bodega'];
        $renglones = array_merge($renglones, [
            ['etiqueta' => 'Botellas por caja logística', 'valor' => (string) (int) $botellas],
            ['etiqueta' => 'Caja logística', 'valor' => $texto_de_la_caja],
            ['etiqueta' => 'Precio por presentación', 'valor' => 'La presentación de venta es '.(float) $p['presentacion'].' y puede cambiar el precio según la configuración; se usa el mismo cálculo de Crear.'],
        ]);
        if (isset($bebida['cajas'])) {
            $cajas = $bebida['cajas'];
            if (!is_numeric($cajas) || (float) $cajas <= 0 || (float) $cajas != (int) $cajas || (float) $cajas > 1000000
                || !isset($bebida['cajas_confirmadas']) || $bebida['cajas_confirmadas'] !== true) {
                return RespuestaDeCargaIa::faltan(['cantidad entera de cajas y confirmación de que el stock se indicó en cajas']);
            }
            $unidades = (int) $cajas * (int) $botellas;
            // La metadata se hereda pero el stock normalizado NO se vuelve a multiplicar.
            if (!$stock_heredado && isset($extras['stock_inicial']) && (float) $extras['stock_inicial'] !== (float) $unidades) {
                return RespuestaDeCargaIa::error('No mezcles stock en cajas con otro stock en unidades. No se cargó nada.');
            }
            $depositos = PropuestaStockIaHelper::para_que_puedo_cargar($contexto);
            // B2 (13/11/2026): con una sola sucursal no hay depósito que preguntar (va a la única, D12).
            if (isset($depositos['depositos']) && count($depositos['depositos']) > 1 && empty($extras['deposito'])) return RespuestaDeCargaIa::faltan(['depósito donde entran las cajas']);
            $extras['stock_inicial'] = $unidades;
            $bebida['stock_unidades'] = $unidades;
            $renglones[] = ['etiqueta' => 'Conversión del stock', 'valor' => (int) $cajas.' cajas × '.(int) $botellas.' botellas = '.$unidades.' unidades sueltas'];
        } else {
            unset($bebida['stock_unidades']);
        }
        /*
         * 🔴 P6 (misión asistente-mejoras-4-dias, 16/11/2026, V13). Truvari, conv 19 y 24: el alta le puso al
         * Malbec la foto de un vino BLANCO encontrada en internet («agregaste una imagen de un vino blanco y
         * es un vino tinto… preguntame antes»). La foto de INTERNET ya no viaja dentro del alta: el artículo
         * se crea sin ella y la foto va APARTE, en su propia tarjeta (proponer_foto_articulo con este
         * imagen_id), que la muestra y espera su propio sí. La que mandó el dueño sigue en el alta.
         */
        if (!empty($extras['imagen_id']) && self::es_foto_de_internet($contexto, $extras['imagen_id'])) {
            $bebida['foto_web_aparte'] = (int) $extras['imagen_id'];
            unset($extras['imagen_id']);
            $renglones[] = ['etiqueta' => 'Foto', 'valor' => 'La que encontré en internet NO va en esta alta: te la muestro aparte, para que confirmes si es la correcta.'];
        } else {
            unset($bebida['foto_web_aparte']);
            if (empty($extras['imagen_id']) && empty($extras['con_foto_de_la_conversacion'])) {
                $renglones[] = ['etiqueta' => 'Imagen', 'valor' => 'Sin imagen: no se asignará ninguna foto.'];
            }
        }
        $bebida['botellas_por_caja'] = (int) $botellas;
        return ['extras' => $extras, 'bebida' => $bebida, 'renglones' => $renglones];
    }

    /**
     * Lo que se le dice a la persona de la caja logística en un alta SIN cajas (sin ingreso de stock): el
     * artículo se vende por botella y las botellas por caja no son la presentación de venta. Lo usan la
     * tarjeta (antes de confirmar, en presente) y el resultado del alta (ya hecho, en pasado).
     *
     * Con la propiedad de distribuidora las botellas por caja se guardan como unidades por bulto: el texto
     * lo dice («quedan como unidades por bulto, no como presentación de venta») en vez de decir que no se
     * guardan, que contradiría el renglón «Unidades por bulto guardadas» de la misma tarjeta.
     *
     * @param  int  $botellas  Las botellas por caja logística que dio la persona.
     * @param  bool  $ya_hecho  true para el resultado (en pasado), false para la tarjeta (en presente).
     * @param  bool  $como_unidades_por_bulto  true si se guardan como unidades_por_bulto (propiedad de distribuidora).
     * @return string
     */
    public static function texto_sin_cajas(int $botellas, bool $ya_hecho, bool $como_unidades_por_bulto = false): string
    {
        if ($como_unidades_por_bulto) {
            return 'Se vende por botella; las '.$botellas.' botellas por caja '
                .($ya_hecho ? 'quedaron' : 'quedan')
                .' como unidades por bulto, no como presentación de venta.';
        }

        return 'Se vende por botella: las '.$botellas.' botellas por caja '
            .($ya_hecho ? 'no quedaron guardadas' : 'no se guardan')
            .' como presentación de venta.';
    }

    public static function verificar(ContextoDeCargaIa $contexto, array $datos): void
    {
        if (!isset($datos[self::CLAVE])) return;
        if (!self::activa($contexto->owner)) throw new AccionIaException(422, 'Se desactivó el alta completa de bebidas. Pedí una propuesta nueva.');
        $extras = isset($datos['extras']) ? $datos['extras'] : [];
        if (!empty($extras['imagen_id']) && !FotosDeLaConversacionIaHelper::por_id($contexto, $extras['imagen_id'])) {
            throw new AccionIaException(422, 'La imagen de la propuesta ya no está disponible. No se creó el artículo.');
        }
        $payload = $datos['payload'] ?? [];
        if (isset($payload['unidades_por_bulto']) && !PermisosIaHelper::tiene_extencion($contexto->owner, 'articulos_con_propiedades_de_distribuidora')) {
            throw new AccionIaException(422, 'La propiedad de unidades por bulto ya no está habilitada. Pedí una propuesta nueva.');
        }
        if (isset($datos[self::CLAVE]['es_vino']) && !$datos[self::CLAVE]['es_vino'] && !empty($payload['cepa_id'])) {
            throw new AccionIaException(422, 'Una bebida que no es vino no puede tener cepa. No se creó el artículo.');
        }
        // La propuesta ya contiene UNIDADES resueltas: jamás pasarlas otra vez por la conversión.
        if (isset($extras['stock_inicial'])) {
            $stock = isset($extras['stock_inicial']) && is_array($extras['stock_inicial']) ? $extras['stock_inicial'] : [];
            if (!isset($stock['cantidad']) || (isset($datos[self::CLAVE]['stock_unidades']) && (float) $stock['cantidad'] !== (float) $datos[self::CLAVE]['stock_unidades'])) {
                throw new AccionIaException(422, 'El stock de la propuesta no coincide con las cajas confirmadas. No se creó el artículo.');
            }
            $revalidado = PropuestaStockIaHelper::resolver_stock_inicial($contexto, $stock['cantidad'], $stock['deposito'] ?? '');
            if (RespuestaDeCargaIa::es_negativa($revalidado) || ($revalidado['address_id'] ?? null) !== ($stock['address_id'] ?? null)) {
                throw new AccionIaException(422, 'El depósito o sus permisos cambiaron. Pedí una propuesta nueva. No se creó el artículo.');
            }
        }
    }
}
