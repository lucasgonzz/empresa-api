<?php

namespace App\Services\ProductFacts;

use Illuminate\Support\Facades\Http;

/** Canal estricto hacia el admin; una versión vieja degrada al alta manual. */
class CentralProductFactsClient
{
    public function request($method, $path, array $data = [])
    {
        $url = rtrim((string) config('services.admin_api.url'), '/');
        $key = trim((string) config('services.admin_api.inbound_key'));
        if ($url === '' || $key === '') {
            throw new ProductFactsException('El catálogo compartido todavía no está configurado. Podés continuar manualmente.');
        }
        if (substr($url, -4) !== '/api') {
            $url .= '/api';
        }
        try {
            $response = Http::acceptJson()->withHeaders(['X-Admin-Api-Key' => $key])
                ->withOptions(['connect_timeout' => 3])->timeout(8)->send($method, $url . '/inbound/' . $path, ['json' => $data]);
        } catch (\Throwable $e) {
            throw new ProductFactsException('El catálogo compartido no respondió. Podés continuar manualmente.');
        }
        if (!$response->successful() || !is_array($response->json())) {
            throw new ProductFactsException('No se pudo consultar el catálogo compartido. Podés continuar manualmente.');
        }
        return $response->json();
    }

    public function claim($gtin, array $person)
    {
        return $this->request('POST', 'product-facts/' . $gtin . '/claim', $person);
    }

    public function complete($gtin, $token, $status, $product = null)
    {
        return $this->request('PUT', 'product-facts/' . $gtin, ['lease_token' => $token, 'status' => $status, 'product' => $product]);
    }
}
