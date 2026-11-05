<?php

namespace App\Services\ImageAssignment;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Models\ImageAssignmentItem;
use App\Models\PruebaConfig;
use App\Services\ProductFacts\TopeSerperDemo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CupoImagenesDePrueba
{
    const TOPE_DEFAULT = 30;
    const TOPE_MAXIMO = 10000;
    const MENSAJE_AGOTADO = 'Usaste todas las búsquedas gratuitas de imágenes de tu prueba. Podés seguir cargando fotos a mano.';

    public static function estado(int $owner_id): ?array
    {
        if (!PruebaHelper::activa($owner_id)) {
            return null;
        }

        return self::saldo(PruebaConfig::where('owner_id', $owner_id)->firstOrFail());
    }

    public static function editar(int $owner_id, int $tope): ?array
    {
        if (!PruebaHelper::tiene_periodo_de_prueba($owner_id)) {
            return null;
        }

        return DB::transaction(function () use ($owner_id, $tope) {
            $config = PruebaConfig::where('owner_id', $owner_id)->lockForUpdate()->firstOrFail();
            $config->imagenes_tope = $tope;
            $config->save();

            return self::saldo($config);
        }, 3);
    }

    public static function estado_para_busqueda(int $owner_id): array
    {
        if (PruebaHelper::tiene_periodo_de_prueba($owner_id)) {
            return array_merge(['aplica' => true, 'unidad' => 'producto'], self::estado($owner_id));
        }

        return TopeSerperDemo::estado($owner_id, true);
    }

    public static function mensaje_si_agotado(int $owner_id): ?string
    {
        $estado = self::estado_para_busqueda($owner_id);

        if (!$estado['aplica'] || $estado['restantes'] > 0) {
            return null;
        }

        return isset($estado['unidad']) ? self::MENSAJE_AGOTADO : TopeSerperDemo::MENSAJE;
    }

    /**
     * Reserva el intento justo antes de buscar. Serializa el saldo del dueño entre corridas y lo
     * guarda junto a la marca del ítem para que un reintento del worker no descuente dos veces.
     * El contador sobrevive a la purga del historial.
     */
    public static function reservar(int $owner_id, ImageAssignmentItem $item): ?string
    {
        if (!PruebaHelper::activa($owner_id)) {
            return null;
        }

        return DB::transaction(function () use ($owner_id, $item) {
            $config = PruebaConfig::where('owner_id', $owner_id)->lockForUpdate()->firstOrFail();

            // Una cola longeva puede haber leído otro vencimiento antes de la contratación.
            $vence_at = DB::table('users')->where('id', $owner_id)->value('expired_at');
            if (is_null($vence_at)) {
                return null;
            }

            if (Carbon::parse($vence_at)->lte(Carbon::now())) {
                return PruebaHelper::MENSAJE_VENCIDA;
            }

            $guardado = ImageAssignmentItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($guardado->consumio_cupo_de_prueba) {
                return null;
            }

            if (self::saldo($config)['restantes'] <= 0) {
                return self::MENSAJE_AGOTADO;
            }

            $config->increment('imagenes_usadas');
            $guardado->consumio_cupo_de_prueba = true;
            $guardado->save();

            return null;
        }, 3);
    }

    private static function saldo(PruebaConfig $config): array
    {
        $tope = (int) ($config->imagenes_tope ?? self::TOPE_DEFAULT);
        $usadas = (int) $config->imagenes_usadas;

        return ['tope' => $tope, 'usadas' => $usadas, 'restantes' => max(0, $tope - $usadas)];
    }
}
