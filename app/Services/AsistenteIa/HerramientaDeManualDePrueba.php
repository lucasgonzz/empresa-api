<?php

namespace App\Services\AsistenteIa;

use App\Services\DemoChatProxy;

class HerramientaDeManualDePrueba
{
    const NOMBRE = 'leer_manual_de_la_prueba';

    private $indice;

    public static function definicion(): array
    {
        return [
            'name' => self::NOMBRE,
            'description' => 'Lee el manual de ComercioCity para explicar una pantalla o una operación. Sin ruta devuelve el índice. Después pasá una ruta exacta de ese índice, sin agregar datos del negocio.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ruta' => [
                        'type' => 'string',
                        'description' => 'Una ruta exacta del índice, por ejemplo manual_sistema/...md. Omitila para pedir el índice.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    public function ejecutar(array $entrada, DemoChatProxy $proxy): array
    {
        if (!isset($this->indice)) {
            $indice = $proxy->solicitar_prueba('GET', '/manual')->getData(true);
            if (($indice['disponible'] ?? false) !== true || !is_string($indice['indice'] ?? null)) {
                return ['content' => 'El manual no está disponible en este momento.', 'is_error' => true];
            }
            $this->indice = $indice['indice'];
        }

        if (!array_key_exists('ruta', $entrada)) {
            return ['content' => $this->indice, 'is_error' => false];
        }

        $ruta = $entrada['ruta'];
        // La entrada del modelo no sale de Empresa salvo que sea una ruta del índice.
        if (!is_string($ruta) || !in_array('- ' . $ruta, preg_split('/\R/u', $this->indice), true)) {
            return ['content' => 'Elegí una ruta exacta del índice del manual.', 'is_error' => true];
        }

        $pagina = $proxy->solicitar_prueba('GET', '/manual', ['ruta' => $ruta])->getData(true);
        if (($pagina['disponible'] ?? false) !== true || !is_string($pagina['contenido'] ?? null)) {
            return ['content' => 'No se pudo leer esa página del manual.', 'is_error' => true];
        }

        return ['content' => $pagina['contenido'], 'is_error' => false];
    }
}
