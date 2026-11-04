<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RespaldoDeepSeekTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.deepseek.api_key' => 'prueba-local',
            'services.deepseek.base_url' => 'https://deepseek.test/anthropic',
        ]);
    }

    private function payload(string $modelo): array
    {
        return [
            'model' => $modelo,
            'max_tokens' => 1000,
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
            'messages' => [['role' => 'user', 'content' => 'Devolvé un JSON']],
            'tools' => [['name' => 'consultar', 'input_schema' => ['type' => 'object']]],
        ];
    }

    public function modelosCompatibles(): array
    {
        return [
            ['deepseek-v4-pro', 'deepseek-flash'],
            ['deepseek-flash', 'deepseek-v4-pro'],
            ['deepseek-v4-flash', 'deepseek-v4-pro'],
            ['deepseek-v4-flash-vision-exp', 'deepseek-v4-pro'],
        ];
    }

    /** @dataProvider modelosCompatibles */
    public function test_envia_la_otra_variante_con_el_mismo_contrato(string $inicial, string $alternativo): void
    {
        $solicitudes = [];
        Http::fake(function ($request) use (&$solicitudes) {
            $solicitudes[] = $request->data();
            return count($solicitudes) === 1
                ? Http::response(['error' => ['type' => 'overloaded_error']], 503)
                : Http::response(['content' => [['type' => 'text', 'text' => '{}']]], 200);
        });

        $payload = $this->payload($inicial);
        [$respuesta, $modelo] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $payload);

        $this->assertSame($alternativo, $modelo);
        $this->assertTrue($respuesta->successful());
        $this->assertSame([$payload, array_replace($payload, ['model' => $alternativo])], $solicitudes);
    }

    public function test_cambia_de_modelo_despues_de_un_timeout(): void
    {
        $modelos = [];
        Http::fake(function ($request) use (&$modelos) {
            $modelos[] = $request['model'];
            if (count($modelos) === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }
            return Http::response(['content' => [['type' => 'text', 'text' => '{}']]], 200);
        });

        [$respuesta, $modelo] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $this->payload('deepseek-v4-pro'));

        $this->assertTrue($respuesta->successful());
        $this->assertSame('deepseek-flash', $modelo);
        $this->assertSame(['deepseek-v4-pro', 'deepseek-flash'], $modelos);
    }

    public function test_dos_fallas_conservan_el_error_inicial(): void
    {
        Http::fakeSequence()->push([], 503)->push([], 401);

        [$respuesta, $modelo] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $this->payload('deepseek-v4-pro'));

        Http::assertSentCount(2);
        $this->assertSame(503, $respuesta->status());
        $this->assertSame('deepseek-v4-pro', $modelo);
    }

    public function erroresPermanentes(): array
    {
        return [[400], [401], [402], [403], [422]];
    }

    /** @dataProvider erroresPermanentes */
    public function test_errores_permanentes_se_devuelven_sin_reintento(int $status): void
    {
        Http::fakeSequence()->push(['error' => ['type' => 'api_error']], $status);

        [$respuesta] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $this->payload('deepseek-flash'));

        Http::assertSentCount(1);
        $this->assertSame($status, $respuesta->status());
    }

    public function test_tls_no_se_trata_como_conexion_transitoria(): void
    {
        $llamadas = 0;
        Http::fake(function () use (&$llamadas) {
            $llamadas++;
            throw new ConnectionException('cURL error 60: SSL certificate problem');
        });

        try {
            ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $this->payload('deepseek-flash'));
            $this->fail('La falla de certificado debía conservarse.');
        } catch (ConnectionException $e) {
            $this->assertSame(1, $llamadas);
        }
    }

    public function contratosIncompatibles(): array
    {
        return [
            [['messages' => [['role' => 'user', 'content' => [['type' => 'tool_result', 'content' => [['type' => 'image']]]]]]]],
            [['tools' => [['type' => 'web_search_20250305', 'name' => 'web_search']]]],
            [['stream' => true]],
        ];
    }

    /** @dataProvider contratosIncompatibles */
    public function test_una_entrada_incompatible_conserva_la_salida_original(array $cambios): void
    {
        Http::fakeSequence()->push([], 503);
        $payload = array_replace($this->payload('deepseek-flash'), $cambios);

        [$respuesta, $modelo] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $payload);

        Http::assertSentCount(1);
        $this->assertSame('deepseek-flash', $modelo);
        $this->assertSame(503, $respuesta->status());
    }

    public function test_si_se_agota_la_ventana_no_sale_el_respaldo(): void
    {
        Http::fake(function () {
            usleep(1100000);
            return Http::response([], 503);
        });

        [$respuesta] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 2, $this->payload('deepseek-flash'));

        Http::assertSentCount(1);
        $this->assertSame(503, $respuesta->status());
    }
}
