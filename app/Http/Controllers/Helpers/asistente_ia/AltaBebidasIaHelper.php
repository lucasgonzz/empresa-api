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
        return 'Alta completa de bebidas activa: para BEBIDAS mandá dentro de datos la clave bebida como objeto {es_vino: booleano, botellas_por_caja: entero, cajas: entero opcional, cajas_confirmadas: booleano, sin_imagen: booleano opcional} de proponer_alta. Los accesorios, insumos y otros artículos siguen por el alta genérica SIN bebida. Reuní nombre, proveedor y bodega EXISTENTES, costo, IVA y margen según listas. '
            .'El tipo de bebida se guarda en categoría/subcategoría de Crear; nunca en marca ni en un campo inventado. '
            .'Mandá bebida.es_vino booleano; solo vinos llevan cepa EXISTENTE. Nunca crees proveedor, bodega o cepa sin consentimiento. '
            .'Presentación es la cantidad de unidades de VENTA y puede cambiar el precio según la configuración: usa el mismo cálculo de Crear. Preguntá si corresponde vender por botella (1) o por presentación. '
            .'Botellas por caja logística va en bebida.botellas_por_caja y no implica presentación de venta. '
            .'Si el stock se dio en cajas, mandá bebida.cajas y bebida.cajas_confirmadas=true solo después de que el dueño haya dicho que son cajas; '
            .'el sistema convierte una sola vez. No mandes además stock_inicial. Elegí explícitamente el depósito para ese ingreso. '
            .'Buscá automáticamente la foto con buscar_imagen_de_bebida por la identificación real, sin inventar código de barras. '
            .'Si no hay coincidencia verificada, informalo y mandá bebida.sin_imagen=true para mostrar ese límite. '
            .'Una única propuesta muestra datos, imagen, conversión y depósito; SIEMPRE requiere confirmación, incluso en modo directo.';
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
        foreach (['name' => 'nombre', 'provider_id' => 'proveedor existente', 'bodega_id' => 'bodega existente',
            'cost' => 'costo', 'category_id' => 'tipo de bebida (categoría)', 'presentacion' => 'presentación de venta'] as $k => $nombre) {
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

        $renglones = [
            ['etiqueta' => 'Tipo de bebida', 'valor' => $bebida['es_vino'] ? 'Vino' : 'Otra bebida (sin cepa)'],
            ['etiqueta' => 'Botellas por caja logística', 'valor' => (string) (int) $botellas],
            ['etiqueta' => 'Caja logística', 'valor' => 'Se usa para convertir este ingreso de stock; no modifica la presentación de venta.'],
            ['etiqueta' => 'Precio por presentación', 'valor' => 'La presentación de venta es '.(float) $p['presentacion'].' y puede cambiar el precio según la configuración; se usa el mismo cálculo de Crear.'],
        ];
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
            if (!empty($depositos['depositos']) && empty($extras['deposito'])) return RespuestaDeCargaIa::faltan(['depósito donde entran las cajas']);
            $extras['stock_inicial'] = $unidades;
            $bebida['stock_unidades'] = $unidades;
            $renglones[] = ['etiqueta' => 'Conversión del stock', 'valor' => (int) $cajas.' cajas × '.(int) $botellas.' botellas = '.$unidades.' unidades sueltas'];
        } else {
            unset($bebida['stock_unidades']);
        }
        if (empty($extras['imagen_id']) && empty($extras['con_foto_de_la_conversacion'])) {
            $renglones[] = ['etiqueta' => 'Imagen', 'valor' => 'Sin imagen: no se asignará ninguna foto.'];
        }
        $bebida['botellas_por_caja'] = (int) $botellas;
        return ['extras' => $extras, 'bebida' => $bebida, 'renglones' => $renglones];
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
