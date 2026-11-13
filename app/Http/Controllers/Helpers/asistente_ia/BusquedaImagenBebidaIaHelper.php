<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\GeocoderCounter;
use App\Models\PruebaConfig;
use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Services\ArticleImageValidationService;
use App\Services\ImageAssignment\CandidateImageProcessor;
use App\Services\ImageSearch\ImageSearchProviderFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Busca antes del alta: conserva una imagen de conversación, nunca crea ni modifica artículos. */
class BusquedaImagenBebidaIaHelper
{
    public static function buscar($owner_id, AiConversation $conversation, array $input, AiMessage $mensaje = null): array
    {
        $c = ContextoDeCargaIa::de_la_conversacion($conversation);
        if ((int) $owner_id !== $c->owner_id || !AltaBebidasIaHelper::activa($c->owner)
            || !PermisosIaHelper::puede($c->persona, 'article.store') || !$mensaje
            || (int) $mensaje->ai_conversation_id !== (int) $conversation->id) {
            return ['error' => 'El alta completa de bebidas no está habilitada para tu usuario en este negocio.'];
        }
        $nombre = trim((string) ($input['nombre'] ?? ''));
        $identificacion = trim((string) ($input['identificacion'] ?? ''));
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 200 || mb_strlen($identificacion) > 300) {
            return ['error' => 'Necesito el nombre real de la bebida y su identificación, sin inventar códigos de barras.'];
        }
        $consulta = trim($nombre.' '.$identificacion);
        $palabras = preg_split('/[^a-z0-9]+/', \Illuminate\Support\Str::ascii(mb_strtolower($consulta)), -1, PREG_SPLIT_NO_EMPTY);
        $especificas = array_diff($palabras, ['vino', 'vinos', 'cerveza', 'cervezas', 'bebida', 'bebidas', 'tinto', 'blanco', 'rosado', 'espumante',
            'botella', 'botellas', 'caja', 'cajas', 'licor', 'whisky', 'gin', 'vodka', 'agua', 'gaseosa', 'ron', 'tequila', 'ml', 'litro', 'litros']);
        $especificas = array_filter($especificas, function ($palabra) { return strlen($palabra) >= 3 && !is_numeric($palabra); });
        if (!count($especificas)) return ['found' => false, 'motivo' => 'Necesito identificar la bebida concreta (nombre y bodega o variedad); no voy a usar una foto genérica.'];
        $clave = 'alta_bebida_busqueda:'.$c->owner_id.':'.$conversation->id.':'.sha1($consulta);
        if ($previa = Cache::get($clave)) return $previa;
        try {
            $encontradas = self::buscar_con_cupo($c, $consulta);
            if (empty($encontradas['ok'])) return ['found' => false, 'motivo' => $encontradas['error'] ?? 'No pude completar la búsqueda de imágenes.'];
            // La descarga existente aplica límites de tamaño, SSRF y redirecciones.
            $preparadas = app(CandidateImageProcessor::class)->preparar(array_slice($encontradas['resultados'], 0, 8));
            $candidatas = [];
            foreach (array_slice($preparadas['listas'], 0, 4) as $lista) {
                $lista['indice'] = $lista['clave'];
                $candidatas[] = $lista;
            }
            $articulo = new Article(['name' => $consulta, 'user_id' => $c->owner_id]);
            // evaluar_candidatas rechaza ante duda: una foto dudosa o sin evaluar nunca se ofrece como exacta.
            $evaluacion = app(ArticleImageValidationService::class)->evaluar_candidatas($candidatas, $articulo, $c->owner_id);
            foreach ($candidatas as $candidata) {
                $veredicto = $evaluacion['resultados'][$candidata['indice']] ?? [];
                if (($veredicto['es_el_producto'] ?? '') !== 'si' || ($veredicto['confianza'] ?? '') !== 'high' || !empty($veredicto['problemas'])) continue;
                $fila = AsistenteImagenHelper::guardar_binario($mensaje, $candidata['binario'], $c->owner_id);
                if (!$fila) continue;
                $fuente = $preparadas['candidatas'][$candidata['clave']]['pagina'] ?? '';
                $resultado = ['found' => true, 'nombre' => $nombre, 'imagen_id' => (int) $fila->id, 'fuente' => $fuente,
                    'siguiente' => 'Mostrá esta foto en la única propuesta de alta, con todos los datos. No se asignó a ningún artículo.'];
                Cache::put('alta_bebida_imagen:'.$fila->id, ['owner_id' => $c->owner_id, 'conversation_id' => $conversation->id, 'nombre' => $nombre], now()->addHours(24));
                Cache::put($clave, $resultado, now()->addMinutes(30));
                return $resultado;
            }
        } catch (\Throwable $e) {
            return ['found' => false, 'motivo' => 'No pude verificar una imagen exacta en este momento. No se asignó ninguna foto ni se inventó un código.'];
        }
        return ['found' => false, 'motivo' => 'No encontré una imagen exacta verificada. Informalo y proponé el alta sin foto, o pedí una foto del producto.'];
    }

    protected static function buscar_con_cupo(ContextoDeCargaIa $c, string $consulta): array
    {
        // El proveedor Google no consume cuota por sí mismo: el motor de asignaciones lo hace.
        // Para un artículo que todavía no existe reservamos acá, con el mismo cupo del dueño.
        $reserva = DB::transaction(function () use ($c) {
            \App\Models\User::whereKey($c->owner_id)->lockForUpdate()->firstOrFail();
            if ((int) ImagenesAutomaticasHelper::cuota_de($c->owner)['disponibles'] <= 0) return null;
            if (PruebaHelper::activa($c->owner_id)) {
                $prueba = PruebaConfig::where('owner_id', $c->owner_id)->lockForUpdate()->firstOrFail();
                $prueba->increment('imagenes_usadas');
            }
            $contador = GeocoderCounter::where('user_id', $c->owner_id)->whereDate('created_at', today())->lockForUpdate()->first();
            if (!$contador) $contador = GeocoderCounter::create(['user_id' => $c->owner_id, 'counter' => 0]);
            $contador->increment('counter');
            return $contador->id;
        });
        if (is_null($reserva)) return ['ok' => false, 'error' => 'Se agotó el cupo de búsquedas de imágenes del negocio. Podés cargar una foto propia.'];
        try {
            $resultado = ImageSearchProviderFactory::para($c->owner)->buscar($consulta);
        } catch (\Throwable $e) {
            GeocoderCounter::whereKey($reserva)->where('counter', '>', 0)->decrement('counter');
            throw $e;
        }
        // Una búsqueda válida sin coincidencias sí consume cupo; un error de conexión no.
        if (empty($resultado['ok'])) GeocoderCounter::whereKey($reserva)->where('counter', '>', 0)->decrement('counter');
        return $resultado;
    }
}
