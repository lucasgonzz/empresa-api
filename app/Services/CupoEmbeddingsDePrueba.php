<?php

namespace App\Services;

use App\Exceptions\CupoEmbeddingsDePruebaException;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Models\AiTokenUsage;
use App\Models\PruebaConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CupoEmbeddingsDePrueba
{
    // USD 1 a USD 0,02 por millón de tokens con text-embedding-3-small.
    private const TOPE_TOKENS = 50000000;
    private const MAX_TOKENS_POR_LLAMADA = 8192;

    /**
     * El scheduler consulta antes de encolar. La reserva efectiva se hace justo
     * antes del HTTP y queda registrada aunque muera el worker.
     *
     * Misión embeddings-lote-cola: un pedido por LOTE lleva varios textos y cada uno puede costar hasta
     * el tope por llamada, así que reserva `$llamadas × tope` y verifica el presupuesto contra esa
     * reserva entera. Con el default (1) es exactamente el comportamiento de siempre.
     *
     * @param int  $owner_id Dueño de la instancia.
     * @param bool $reservar true para dejar la reserva hecha; false sólo consulta.
     * @param int  $llamadas Cantidad de textos del pedido (1 = un solo texto).
     *
     * @throws CupoEmbeddingsDePruebaException
     */
    public static function comprobar(int $owner_id, bool $reservar = false, int $llamadas = 1): ?int
    {
        // Tokens a reservar para este pedido: el tope por llamada de cada texto.
        $a_reservar = self::MAX_TOKENS_POR_LLAMADA * max(1, $llamadas);

        if (!PruebaHelper::puede_haber_prueba()) {
            return null;
        }

        $resultado = DB::transaction(function () use ($owner_id, $reservar, $a_reservar) {
            $prueba = PruebaConfig::where('owner_id', $owner_id)->lockForUpdate()->first();
            if ($prueba === null) {
                return null;
            }

            // Un worker puede seguir vivo después de que el admin cambie el vencimiento.
            $vence_at = self::vencimiento($owner_id);
            if ($vence_at === null) {
                return null;
            }
            if ($vence_at->lte(Carbon::now())) {
                throw new CupoEmbeddingsDePruebaException('La prueba gratuita ya terminó.');
            }

            // En las pruebas que ya estaban abiertas, recuperar el consumo previo una sola vez.
            // El valor guardado incluye también reservas pendientes y cobros inciertos.
            if ($prueba->embeddings_tokens_usados === null) {
                $prueba->embeddings_tokens_usados = AiTokenUsage::where('user_id', $owner_id)
                    ->where('proveedor', ArticleEmbeddingService::PROVEEDOR)
                    ->where('modelo', ArticleEmbeddingService::EMBEDDING_MODEL)
                    ->where('proceso', ArticleEmbeddingService::PROCESO_ARTICULOS)
                    ->where('created_at', '>=', $prueba->created_at)
                    ->sum('input_tokens');
                $prueba->save();
            }

            if ((int) $prueba->embeddings_tokens_usados + $a_reservar > self::TOPE_TOKENS) {
                // Devolver la excepción permite commitear el consumo histórico antes de propagarla.
                return new CupoEmbeddingsDePruebaException('Se alcanzó el presupuesto de embeddings de la prueba gratuita.');
            }

            if ($reservar) {
                $prueba->increment('embeddings_tokens_usados', $a_reservar);
            }

            return $reservar ? (int) $prueba->id : null;
        }, 3);

        if ($resultado instanceof CupoEmbeddingsDePruebaException) {
            throw $resultado;
        }

        return $resultado;
    }

    /**
     * Ajusta la reserva hecha por comprobar() contra lo que OpenAI cobró de verdad (0 si rechazó el
     * pedido y no cobró nada). Con `$llamadas` > 1 (pedido por lote) la reserva fue de
     * `$llamadas × tope`, y es contra eso que se concilia.
     *
     * @param int|null $prueba_id       Id de la prueba que devolvió comprobar() (null = sin reserva).
     * @param int      $tokens_cobrados Tokens cobrados por el pedido entero.
     * @param int      $llamadas        Cantidad de textos que se reservaron (1 = un solo texto).
     *
     * @return void
     */
    public static function conciliar(?int $prueba_id, int $tokens_cobrados, int $llamadas = 1): void
    {
        // Lo que se había reservado para este pedido.
        $reservado = self::MAX_TOKENS_POR_LLAMADA * max(1, $llamadas);

        if ($prueba_id === null || $tokens_cobrados === $reservado) {
            return;
        }

        // El ajuste aritmético se hace en SQL para no pisar las reservas de otros workers.
        try {
            PruebaConfig::whereKey($prueba_id)->increment(
                'embeddings_tokens_usados',
                $tokens_cobrados - $reservado
            );
        } catch (\Throwable $exception) {
            // La reserva ya quedó guardada. Un fallo al ajustarla no amerita otra llamada paga.
            Log::channel('daily')->warning('No se pudo conciliar el consumo de embeddings de la prueba.', [
                'prueba_id' => $prueba_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public static function omitir_importacion(int $owner_id): bool
    {
        if (!config('services.openai.embeddings_omitir_importacion')) {
            return false;
        }

        // Una instancia de prueba puede conservar el interruptor al contratar. Si venció,
        // comprobar() bloquea la indexación tanto en el comando como en los jobs encolados.
        return !PruebaHelper::activa($owner_id);
    }

    private static function vencimiento(int $owner_id): ?Carbon
    {
        $vence_at = DB::table('users')->where('id', $owner_id)->value('expired_at');

        return $vence_at === null ? null : Carbon::parse($vence_at);
    }
}
