<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LlamaAlSistema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class VentasRegistrarSeguimientoTool extends Tool
{
    use LlamaAlSistema;

    protected string $name = 'ventas_registrar_seguimiento';

    protected string $title = 'Registrar seguimiento de ventas';

    protected string $description = 'Registra UN seguimiento comercial (llamada, visita, etc.) a nombre del usuario conectado, igual que la pantalla "Registro de llamadas". ANTES de llamarla: (1) obtén el id del cliente con ventas_buscar_clientes y revisa que "puede_registrar_seguimiento" sea true (si no, explica el "motivo_bloqueo" al usuario); (2) elige medio y motivo válidos con ventas_catalogos_seguimiento; (3) revisa los contactos con ventas_contactos_cliente; (4) muestra al usuario un resumen de lo que vas a registrar y pide su confirmación explícita. No inventes datos: si falta algo, pregúntalo. Se aplican las mismas reglas del sistema: un cliente asignado a otro asesor solo se puede registrar si está vencido (última factura de hace más de 2 meses), bloqueo de 7 días si otro asesor lo atendió, y un solo seguimiento por cliente y fecha. Al registrar, el cliente queda marcado "en atención" a nombre del usuario. Solo pueden registrar los roles Asesor de Ventas y Jefe de Ventas.';


    public function schema(JsonSchema $schema): array
    {
        return [
            'cliente_id' => $schema->integer()->description('Id del cliente (de ventas_buscar_clientes).')->required(),
            'medio_comunicacion' => $schema->string()->description('Medio, tal cual aparece en ventas_catalogos_seguimiento.')->required(),
            'motivo' => $schema->string()->description('Motivo, tal cual aparece en ventas_catalogos_seguimiento.')->required(),
            'fecha_comunicacion' => $schema->string()->description('Fecha del contacto AAAA-MM-DD: hoy o hasta 7 días atrás.')->required(),
            'contacto_id' => $schema->integer()->description('Id del contacto del cliente (de ventas_contactos_cliente). Si lo envías, nombre, cargo y celular se completan solos.'),
            'contacto' => $schema->string()->description('Nombre del contacto. Obligatorio si no envías contacto_id.'),
            'cargo' => $schema->string()->description('Cargo del contacto. Obligatorio si no hay contacto_id con cargo registrado.'),
            'num_contacto' => $schema->string()->description('Celular o teléfono del contacto. Obligatorio si no hay contacto_id con teléfono registrado.'),
            'descripcion' => $schema->string()->description('Qué se habló (máximo 2000 caracteres).'),
            'conclusion_seg' => $schema->string()->description('Conclusión del seguimiento (máximo 2000 caracteres).'),
            'agendar_fecha' => $schema->string()->description('Si hay próximo seguimiento: fecha AAAA-MM-DD (hoy o futura).'),
            'agendar_hora' => $schema->string()->description('Hora del próximo seguimiento en formato HH:MM.'),
            'agendar_descripcion' => $schema->string()->description('Descripción del próximo seguimiento (máximo 500 caracteres).'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->enviar($request, 'ventas/seguimientos', [
            'cliente_id' => $request->get('cliente_id'),
            'medio_comunicacion' => $request->get('medio_comunicacion'),
            'motivo' => $request->get('motivo'),
            'fecha_comunicacion' => $request->get('fecha_comunicacion'),
            'contacto_id' => $request->get('contacto_id'),
            'contacto' => $request->get('contacto'),
            'cargo' => $request->get('cargo'),
            'num_contacto' => $request->get('num_contacto'),
            'descripcion' => $request->get('descripcion'),
            'conclusion_seg' => $request->get('conclusion_seg'),
            'agendar_fecha' => $request->get('agendar_fecha'),
            'agendar_hora' => $request->get('agendar_hora'),
            'agendar_descripcion' => $request->get('agendar_descripcion'),
        ]);
    }
}


