<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\Sale;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Todos los datos son sintéticos. Sin lectores ni proveedores externos.
 * El importe elegido requiere afirmación humana; negar otra opción no inventa la elegida.
 * PHP 7.4 y herramientas locales. No se invocan lectores ni proveedores.
 */
class Planilla_importe_afirmado_Test extends PlanillaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function definiciones_inequivocas()
    {
        return [
            'total con unidad negada' => ['El importe es el total, no por unidad.', 'total'],
            'unidad con total negado' => ['El importe es por unidad, no el total.', 'unidad'],
            'preservar otro precio no redefine esta planilla' => ['El importe es el total. No cambies el precio por unidad del catálogo ni las cantidades.', 'total'],
            'unidad de medida es otro campo' => ['No cambies la unidad de medida; el importe es el total.', 'total'],
            'un veto de descuentos no niega total' => ['El importe es el total. No apliques descuentos.', 'total'],
            'un veto de descuentos no niega unidad' => ['El importe es por unidad. No apliques descuentos.', 'unidad'],
        ];
    }

    /** @dataProvider definiciones_inequivocas */
    public function test_la_definicion_afirmada_permite_una_tarjeta_sin_crear_ventas($declaracion, $importe)
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation);
        $clientes = Client::count();
        $ventas = Sale::count();
        $stock = Article::where('user_id', $this->dueno->id)->orderBy('id')->pluck('stock', 'id')->all();
        $this->del_dueno($conversation, $declaracion . ' Se cobraron en Efectivo, Caja Efectivo. Prepará la propuesta.');
        $assistant = $this->assistant($conversation);
        $definida = $this->herramienta('definir_planilla', [
            'importe_es' => $importe,
            'cobro' => 'contado',
            'metodo_de_pago' => TestingFerreteriaSeeder::PAGO_EFECTIVO,
            'caja' => TestingFerreteriaSeeder::CAJA_EFECTIVO,
        ], $conversation, $assistant);
        $this->assertTrue($definida['ok'], json_encode($definida));
        $this->assertSame($importe, $planilla->fresh()->importe_es);
        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);
        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $this->assertSame(1, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
        $this->assertSame($clientes, Client::count());
        $this->assertSame($ventas, Sale::count());
        $this->assertSame($stock, Article::where('user_id', $this->dueno->id)->orderBy('id')->pluck('stock', 'id')->all());
        Http::assertNothingSent();
    }

    public function definiciones_no_autorizadas()
    {
        return [
            'ambiguedad genuina' => ['El importe es el total o por unidad.', 'total', 'importe_ambiguo'],
            'modalidades de filas distintas' => ['En la fila 1 el importe es el total. En la fila 2 es por unidad.', 'total', 'importe_ambiguo'],
            'negar unidad no afirma total' => ['No es por unidad.', 'total', 'sin_ancla'],
            'negar total no afirma unidad' => ['No es el total.', 'unidad', 'sin_ancla'],
            'argumento sin dato humano' => ['Prepará la propuesta sin tocar las cantidades.', 'total', 'sin_ancla'],
            'accion citada no es definicion' => ['"El importe es el total".', 'total', 'sin_ancla'],
            'negar la declaracion de total no lo afirma al quitar unidad' => ['No dije que el importe es el total, no el precio unitario.', 'total', 'sin_ancla'],
            'negar la declaracion de unidad no la afirma al quitar total' => ['No dije que el importe es por unidad, no el total.', 'unidad', 'sin_ancla'],
            'la forma por cada uno tampoco autoriza desde una cita' => ['"Cada uno a 500".', 'unidad', 'sin_ancla'],
            'la forma por cada uno tampoco autoriza desde un ejemplo' => ['Como ejemplo: cada uno a 500.', 'unidad', 'sin_ancla'],
            'una envoltura vetada no autoriza la definicion de importe' => ['No hagas lo siguiente. El importe es por unidad.', 'unidad', 'sin_ancla'],
        ];
    }

    /** @dataProvider definiciones_no_autorizadas */
    public function test_la_falta_de_afirmacion_no_escribe_definiciones_ni_tarjetas($mensaje, $importe, $codigo)
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation);
        $antes = $planilla->fresh()->getAttributes();
        $this->del_dueno($conversation, $mensaje);
        $assistant = $this->assistant($conversation);
        $resultado = $this->herramienta('definir_planilla', ['importe_es' => $importe], $conversation, $assistant);
        $this->assertFalse($resultado['ok'], json_encode($resultado));
        $this->assertSame($codigo, $resultado['codigo']);
        $this->assertSame($antes, $planilla->fresh()->getAttributes());
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        Http::assertNothingSent();
    }

    public function evidencia_actual_y_ventana()
    {
        return [
            'negar total tras pregunta no lo recupera del historial' => [['El importe es el total.'], 'No es el total.', 'total', false],
            'negar unidad tras pregunta no la recupera del historial' => [['El importe es por unidad.'], 'No es por unidad.', 'unidad', false],
            'citar total no reutiliza una afirmacion anterior' => [['El importe es el total.'], '"El importe es el total".', 'total', false],
            'una hipotesis no reutiliza una afirmacion anterior' => [['El importe es el total.'], 'Si dijera que el importe es el total, no lo tomes como pedido.', 'total', false],
            'la ausencia real conserva el historial autorizado' => [['El importe es el total.'], 'Prepará la propuesta sin tocar las cantidades.', 'total', true],
            'la ventana se detiene en el veto posterior a la afirmacion' => [['El importe es el total.', 'No es el total.'], 'Prepará la propuesta sin tocar las cantidades.', 'total', false],
            'negar el deseo de total corta la ventana afirmativa' => [['El importe es el total.'], 'No quiero que el importe sea el total.', 'total', false],
            'negar el deseo de unidad corta la ventana afirmativa' => [['El importe es por unidad.'], 'No quiero que el importe sea por unidad.', 'unidad', false],
        ];
    }

    /** @dataProvider evidencia_actual_y_ventana */
    public function test_una_declaracion_no_autorizante_no_es_ausencia_de_informacion($historial, $mensaje, $importe, $acepta)
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation);

        foreach ($historial as $anterior) {
            $this->del_dueno($conversation, $anterior);
        }

        $pregunta = $this->assistant($conversation);
        $pregunta->contenido = '¿El importe es el total o el precio por unidad?';
        $pregunta->estado = 'listo';
        $pregunta->save();
        $antes = $planilla->fresh()->getAttributes();
        $this->del_dueno($conversation, $mensaje);
        $assistant = $this->assistant($conversation);
        $resultado = $this->herramienta('definir_planilla', ['importe_es' => $importe], $conversation, $assistant);
        $this->assertSame($acepta, $resultado['ok'], json_encode($resultado));

        if ($acepta) {
            $this->assertSame($importe, $planilla->fresh()->importe_es);
        } else {
            $this->assertSame('sin_ancla', $resultado['codigo']);
            $this->assertSame($antes, $planilla->fresh()->getAttributes());
        }

        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        Http::assertNothingSent();
    }
}
