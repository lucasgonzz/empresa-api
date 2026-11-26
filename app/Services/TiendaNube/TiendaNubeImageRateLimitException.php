<?php

namespace App\Services\TiendaNube;

use Carbon\CarbonImmutable;

class TiendaNubeImageRateLimitException extends \RuntimeException
{
    private $retryAfter;

    public function __construct(?string $retryAfter)
    {
        parent::__construct('No se pudo consultar las imágenes remotas (HTTP 429).');
        $this->retryAfter = trim((string) $retryAfter);
    }

    public function retry_at(int $fallbackSeconds): CarbonImmutable
    {
        $now = CarbonImmutable::instance(now());
        // DATETIME guarda segundos enteros. Redondear hacia arriba respeta el
        // plazo indicado aun cuando el comando se ejecute a mitad de un segundo.
        if ($now->microsecond > 0) {
            $now = $now->addSecond()->startOfSecond();
        }
        $fallback = $now->addSeconds($fallbackSeconds);

        if (ctype_digit($this->retryAfter)) {
            // DATETIME termina en 9999. Una espera imposible sólo puede ser terminal.
            $maxSeconds = $now->diffInSeconds($now->setDate(9999, 12, 31)->setTime(23, 59, 59));
            return $now->addSeconds((int) min($maxSeconds, max($fallbackSeconds, (float) $this->retryAfter)));
        }

        $httpDates = [
            '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/D',
            '/^[A-Z][a-z]+, \d{2}-[A-Z][a-z]{2}-\d{2} \d{2}:\d{2}:\d{2} GMT$/D',
            '/^[A-Z][a-z]{2} [A-Z][a-z]{2} [ \d]\d \d{2}:\d{2}:\d{2} \d{4}$/D',
        ];
        foreach ($httpDates as $format) {
            if (preg_match($format, $this->retryAfter)) {
                $date = $this->retryAfter;
                if (substr($date, -3) !== 'GMT') {
                    $date .= ' GMT';
                }
                $timestamp = strtotime($date);
                if ($timestamp !== false && $timestamp > $fallback->timestamp) {
                    return $now->setTimestamp($timestamp);
                }
            }
        }

        return $fallback;
    }
}
