<?php

namespace App\Http\Controllers\Mcp;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class McpCertificacionesEscrituraController extends McpCertificacionesController
{
    protected function escritura(Request $r, string $accion): void
    {
        $this->acceso($r);
        abort_unless($this->puede($r, $accion), 403, 'Tu rol no puede realizar esta acción en certificaciones.');
    }

    protected function confirmar(Request $r): array
    {
        return $r->validate(['confirmado' => ['required', 'accepted'], 'version_actual' => ['required', 'string', 'size:64']]);
    }

    protected function bloquear(int $id, ?string $version = null)
    {
        $row = DB::table('tb_certificado')->where('idCertificado', $id)->where('estado_soli', 1)->lockForUpdate()->first();
        abort_unless($row, 404, 'Solicitud no encontrada.');
        if ($version !== null && ! hash_equals($this->version($id), $version)) {
            $this->fallo('version_actual', 'La solicitud cambió. Consulta nuevamente el detalle y confirma los cambios.');
        }

        return $row;
    }

    protected function fallo(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }

    private function catalogoValido(string $catalogo, string $valor, ?int $tipo = null): void
    {
        [$tabla, $id, $nombre] = self::CATALOGOS[$catalogo];
        $q = DB::table($tabla)->where($nombre, $valor);
        if ($tipo !== null) {
            $q->where(self::CATALOGOS[$catalogo][3], $tipo);
        }
        if (! $q->exists()) {
            $this->fallo($catalogo, 'El valor no existe en '.$catalogo.'. Consulta los catálogos y pide confirmación para añadirlo.');
        }
    }

    private function instrumentosValidados(array $items): array
    {
        $result = [];
        foreach ($items as $n => $i) {
            $tipo = $i['instrumento'];
            $campos = match ($tipo) {
                'Balanza' => ['modelo', 'cap_maxima', 'escalas'],
                'Pesa' => ['valor_nominal', 'forma', 'material', 'clase_exactitud'],
                'Termometro' => ['modelo', 'desde_medicion', 'hasta_medicion', 'resolucion', 'elemento_sensor'],
            };
            foreach ($campos as $campo) {
                if (! isset($i[$campo]) || trim($i[$campo]) === '') {
                    $this->fallo("instrumentos.$n.$campo", "El campo $campo es obligatorio para $tipo.");
                }
            }
            if (count($i['series']) !== (int) $i['cantidad']) {
                $this->fallo("instrumentos.$n.series", 'La cantidad debe coincidir con el número de series.');
            }
            foreach ($i['series'] as $serie) {
                if ($tipo === 'Balanza' && empty($serie['ubicacion'])) {
                    $this->fallo("instrumentos.$n.series", 'La ubicación es obligatoria en cada balanza.');
                }
            }
            if ($tipo === 'Pesa') {
                if ($i['marca'] !== 'NO INDICA') {
                    $this->fallo('marca', 'El formulario de pesas utiliza marca NO INDICA.');
                }
                if (! in_array($i['forma'], config('mcp.certificaciones_formas', []), true)) {
                    $this->fallo('forma', 'Consulta las formas válidas en certificaciones_filtros.');
                }
                if (! in_array($i['material'], config('mcp.certificaciones_materiales', []), true)) {
                    $this->fallo('material', 'Consulta los materiales válidos en certificaciones_filtros.');
                }
                if ($i['valor_nominal'] !== 'NO INDICA') {
                    $this->catalogoValido('valores_nominales', $i['valor_nominal']);
                }
                $this->catalogoValido('clases_exactitud', $i['clase_exactitud']);
            } else {
                $codigo = $tipo === 'Balanza' ? 1 : 2; // Create.vue tipoDeInstrumento, no comentario obsoleto del controlador.
                if ($i['marca'] !== 'NO INDICA') {
                    $this->catalogoValido('marcas', $i['marca'], $codigo);
                }
                $this->catalogoValido('modelos', $i['modelo'], $codigo);
                if ($i['procedencia'] !== 'NO INDICA') {
                    $this->catalogoValido($codigo === 1 ? 'procedencias_balanza' : 'procedencias_termometro', $i['procedencia']);
                }
                if ($codigo === 1) {
                    $this->catalogoValido('capacidad_escala', $i['cap_maxima']);
                    $this->catalogoValido('escalas', $i['escalas']);
                } else {
                    $this->catalogoValido('elementos_sensor', $i['elemento_sensor']);
                }
            }
            $row = ['tipoInstrumento' => $tipo, 'cantidad' => $i['cantidad'], 'marca' => $i['marca'], 'procedencia' => $i['procedencia'],
                'serie' => json_encode($i['series'], JSON_UNESCAPED_UNICODE),
                'puntosCalibracion' => json_encode($i['puntos_calibrar'] ?? [], JSON_UNESCAPED_UNICODE)];
            foreach (['modelo' => 'modelo', 'valor_nominal' => 'valorNominal', 'cap_maxima' => 'capacidadMax', 'forma' => 'forma', 'material' => 'material',
                'clase_exactitud' => 'clase_exactitud', 'desde_medicion' => 'interbaloDesde', 'hasta_medicion' => 'interbaloHasta', 'elemento_sensor' => 'elementoSensor'] as $k => $v) {
                $row[$v] = $i[$k] ?? null;
            }
            $row['resolucion'] = $tipo === 'Balanza' ? $i['escalas'] : ($tipo === 'Termometro' ? $i['resolucion'] : null);
            $result[] = $row;
        }

        return $result;
    }

    private function unaVez(Request $r, array $data, string $accion, callable $callback)
    {
        $huella = hash('sha256', json_encode([$accion, $data], JSON_UNESCAPED_UNICODE));
        $resultado = DB::transaction(function () use ($r, $data, $huella, $callback) {
            DB::table('mcp_certificacion_operaciones')->insertOrIgnore(['user_id' => $r->user()->id, 'clave' => $data['clave_operacion'],
                'huella' => $huella, 'created_at' => now(), 'updated_at' => now()]);
            $op = DB::table('mcp_certificacion_operaciones')->where('user_id', $r->user()->id)->where('clave', $data['clave_operacion'])->lockForUpdate()->first();
            if (! $op || ! hash_equals($op->huella, $huella)) {
                $this->fallo('clave_operacion', 'La clave ya corresponde a otra operación.');
            }
            if ($op->respuesta !== null) {
                return json_decode($op->respuesta, true) + ['repetida' => true];
            }
            $res = $callback();
            DB::table('mcp_certificacion_operaciones')->where('id', $op->id)->update(['respuesta' => json_encode($res), 'updated_at' => now()]);

            return $res;
        });
        $this->auditar($r, $accion, ['id' => $resultado['id'] ?? null]);

        return response()->json($resultado, 201);
    }

    public function registrar(Request $r)
    {
        $this->escritura($r, 'registrar');
        $rules = ['confirmado' => ['required', 'accepted'], 'clave_operacion' => ['required', 'uuid'],
            'cliente_id' => ['required', 'integer', 'min:1'], 'direccion' => ['required', 'string', 'max:500'],
            'fecha_emision' => ['required', 'date_format:Y-m-d'], 'meses_vigencia' => ['required', 'integer', 'min:1', 'max:120'],
            'contacto_id' => ['nullable', 'integer', 'min:1'], 'contacto' => ['nullable', 'string', 'max:255'], 'telefono' => ['nullable', 'string', 'max:255'],
            'instrumentos' => ['required', 'array', 'min:1', 'max:30'],
            'instrumentos.*' => ['required', 'array:instrumento,cantidad,marca,modelo,procedencia,valor_nominal,cap_maxima,forma,material,clase_exactitud,desde_medicion,hasta_medicion,escalas,resolucion,elemento_sensor,series,puntos_calibrar'],
            'instrumentos.*.instrumento' => ['required', Rule::in(['Balanza', 'Pesa', 'Termometro'])],
            'instrumentos.*.cantidad' => ['required', 'integer', 'min:1', 'max:100'],
            'instrumentos.*.marca' => ['required', 'string', 'max:255'], 'instrumentos.*.procedencia' => ['required', 'string', 'max:255'],
            'instrumentos.*.series' => ['required', 'array', 'min:1', 'max:100'],
            'instrumentos.*.series.*' => ['required', 'array:num_serie,identificacion,ubicacion'],
            'instrumentos.*.series.*.num_serie' => ['required', 'string', 'max:255'],
            'instrumentos.*.series.*.identificacion' => ['required', 'string', 'max:255'],
            'instrumentos.*.series.*.ubicacion' => ['nullable', 'string', 'max:255'],
            'instrumentos.*.puntos_calibrar' => ['nullable', 'array', 'max:30'],
            'instrumentos.*.puntos_calibrar.*' => ['required', 'string', 'max:100']];
        foreach (['modelo', 'valor_nominal', 'cap_maxima', 'forma', 'material', 'clase_exactitud', 'desde_medicion', 'hasta_medicion', 'escalas', 'resolucion', 'elemento_sensor'] as $c) {
            $rules['instrumentos.*.'.$c] = ['nullable', 'string', 'max:255'];
        }
        $data = $r->validate($rules);

        return $this->unaVez($r, $data, 'registrar', function () use ($data) {
            abort_unless(DB::table('clientes')->where('id', $data['cliente_id'])->exists(), 404, 'Cliente no encontrado.');
            $contacto = null;
            if (! empty($data['contacto_id'])) {
                $contacto = DB::table('datos')->where('id', $data['contacto_id'])->where('cliente_id', $data['cliente_id'])->whereNull('deleted_at')->first();
                abort_unless($contacto, 404, 'Contacto no encontrado para ese cliente.');
            }
            $items = $this->instrumentosValidados($data['instrumentos']);
            $fecha = CarbonImmutable::parse($data['fecha_emision'], 'America/Lima');
            $id = DB::table('tb_certificado')->insertGetId([
                'idCliente' => $data['cliente_id'], 'direccionCertificado' => $data['direccion'],
                'fechaSolicitud' => $fecha->format('d/m/Y'), 'tiempoCaducidad' => $data['meses_vigencia'].' meses',
                'fechaCaducidad' => $fecha->addMonthsWithOverflow((int) $data['meses_vigencia'])->format('d/m/Y'),
                'contactoCertificado' => $contacto->nombre ?? $data['contacto'] ?? null,
                'telefonoContactoCertificado' => $contacto->telefono ?? $data['telefono'] ?? null,
                'dato_contacto_id' => $data['contacto_id'] ?? null, 'estado_soli' => 1,
                'created_at' => now(), 'updated_at' => now()], 'idCertificado');
            foreach ($items as $item) {
                DB::table('tb_instrumento')->insert($item + ['id_certificado' => $id]);
            }

            return ['registrado' => true, 'id' => $id, 'version_actual' => $this->version($id)];
        });
    }

    public function editarFechas(Request $r, int $id)
    {
        $this->escritura($r, 'editar_fechas');
        $v = $this->confirmar($r);
        $data = $r->validate(['fecha_emision' => ['required', 'date_format:Y-m-d'], 'fecha_caducidad' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_emision']]);
        $res = DB::transaction(function () use ($id, $v, $data) {
            $old = $this->bloquear($id, $v['version_actual']);
            DB::table('tb_certificado')->where('idCertificado', $id)->update([
                'fechaSolicitud' => CarbonImmutable::parse($data['fecha_emision'])->format('d/m/Y'),
                'fechaCaducidad' => CarbonImmutable::parse($data['fecha_caducidad'])->format('d/m/Y'), 'updated_at' => now()]);

            return ['actualizado' => true, 'id' => $id, 'anterior' => ['fecha_emision' => $old->fechaSolicitud, 'fecha_caducidad' => $old->fechaCaducidad],
                'nuevo' => $data, 'version_actual' => $this->version($id)];
        });
        $this->auditar($r, 'editar_fechas', ['id' => $id]);

        return response()->json($res);
    }

    public function renovar(Request $r, int $id)
    {
        $this->escritura($r, 'renovar');
        $v = $this->confirmar($r);
        $res = DB::transaction(function () use ($id, $v) {
            $old = $this->bloquear($id, $v['version_actual']);
            if (DB::table('tb_certificado')->where('parent_solicitud_id', $id)->exists()) {
                $this->fallo('id', 'Ya existe una renovación. Consulta la solicitud hija.');
            }
            if (! DB::table('tb_certificado_archivo')->where('id_certificado', $id)->exists()) {
                $this->fallo('id', 'La solicitud original debe tener certificados subidos.');
            }
            $meses = (int) preg_replace('/\D+/', '', (string) $old->tiempoCaducidad) ?: 12;
            if ($meses > 120) {
                $this->fallo('tiempo_caducidad', 'La vigencia supera 120 meses. Revísala en el sistema.');
            }
            $fecha = CarbonImmutable::now('America/Lima');
            $nueva = Arr::only((array) $old, ['idCliente', 'direccionCertificado', 'tiempoCaducidad', 'contactoCertificado', 'telefonoContactoCertificado', 'dato_contacto_id']);
            $nuevoId = DB::table('tb_certificado')->insertGetId($nueva + ['parent_solicitud_id' => $id, 'estadoRenovacion' => 'Pendiente', 'estado_soli' => 1,
                'fechaSolicitud' => $fecha->format('d/m/Y'), 'fechaCaducidad' => $fecha->addMonthsWithOverflow($meses)->format('Y-m-d'),
                'created_at' => now(), 'updated_at' => now()], 'idCertificado');
            $items = DB::table('tb_instrumento')->where('id_certificado', $id)->get();
            if ($items->isEmpty()) {
                $this->fallo('instrumentos', 'La solicitud no tiene instrumentos.');
            }
            foreach ($items as $item) {
                $copy = Arr::only((array) $item, ['tipoInstrumento', 'cantidad', 'marca', 'modelo', 'valorNominal', 'procedencia', 'capacidadMax', 'forma', 'interbaloDesde', 'interbaloHasta', 'resolucion', 'elementoSensor', 'puntosCalibracion', 'serie', 'material', 'clase_exactitud']);
                DB::table('tb_instrumento')->insert($copy + ['id_certificado' => $nuevoId]);
            }

            return ['registrado' => true, 'id' => $nuevoId, 'solicitud_origen_id' => $id, 'version_actual' => $this->version($nuevoId)];
        });
        $this->auditar($r, 'renovar', ['id' => $id]);

        return response()->json($res, 201);
    }

    public function eliminar(Request $r, int $id)
    {
        $this->escritura($r, 'eliminar');
        $v = $this->confirmar($r);
        DB::transaction(function () use ($id, $v) {
            $this->bloquear($id, $v['version_actual']);
            DB::table('tb_certificado')->where('idCertificado', $id)->update(['estado_soli' => 0, 'updated_at' => now()]);
        });
        $this->auditar($r, 'eliminar', ['id' => $id]);

        return response()->json(['actualizado' => true, 'id' => $id, 'estado_soli' => 0, 'nota' => 'Baja lógica. No se borraron instrumentos ni PDF.']);
    }

    public function contactos(Request $r, int $id)
    {
        $this->acceso($r);
        abort_unless(DB::table('clientes')->where('id', $id)->exists(), 404, 'Cliente no encontrado.');
        $data = $r->validate($this->paginacion());

        return $this->lista(DB::table('datos')->where('cliente_id', $id)->whereNull('deleted_at')->select('id', 'nombre', 'telefono', 'cargo', 'correo')->orderBy('id'), $data, $r, 'contactos');
    }

    public function registrarContacto(Request $r, int $id)
    {
        $this->escritura($r, 'registrar');
        $data = $r->validate(['confirmado' => ['required', 'accepted'], 'clave_operacion' => ['required', 'uuid'],
            'nombre' => ['required', 'string', 'max:255'], 'telefono' => ['nullable', 'string', 'max:255'], 'cargo' => ['nullable', 'string', 'max:255'], 'correo' => ['nullable', 'email', 'max:255']]);
        $data['cliente_id'] = $id;

        return $this->unaVez($r, $data, 'registrar_contacto', function () use ($data, $id) {
            abort_unless(DB::table('clientes')->where('id', $id)->exists(), 404, 'Cliente no encontrado.');
            $idContacto = DB::table('datos')->insertGetId(Arr::only($data, ['cliente_id', 'nombre', 'telefono', 'cargo', 'correo']) + ['created_at' => now(), 'updated_at' => now()]);

            return ['registrado' => true, 'id' => $idContacto, 'cliente_id' => $id];
        });
    }

    public function agregarCatalogo(Request $r)
    {
        $this->escritura($r, 'registrar');
        $data = $r->validate(['confirmado' => ['required', 'accepted'], 'clave_operacion' => ['required', 'uuid'],
            'catalogo' => ['required', Rule::in(array_keys(self::CATALOGOS))], 'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['nullable', 'integer', Rule::in([1, 2])], 'division' => ['nullable', 'string', 'max:50']]);

        return $this->unaVez($r, $data, 'agregar_catalogo', function () use ($data) {
            [$tabla,$id,$nombre] = self::CATALOGOS[$data['catalogo']];
            $values = [$nombre => $data['nombre']];
            if (isset(self::CATALOGOS[$data['catalogo']][3])) {
                if (! isset($data['tipo'])) {
                    $this->fallo('tipo', 'Marca y modelo requieren tipo 1 Balanza o 2 Termometro.');
                }
                $values[self::CATALOGOS[$data['catalogo']][3]] = $data['tipo'];
            }
            if ($data['catalogo'] === 'capacidad_escala') {
                if (empty($data['division']) || mb_strlen($data['nombre']) > 50) {
                    $this->fallo('division', 'Capacidad y división son obligatorias, máximo 50 caracteres.');
                }
                $values['division'] = $data['division'];
            }
            // Serializa altas de catálogo MCP para evitar duplicados entre claves distintas.
            DB::table('mcp_certificacion_catalogo_lock')->where('id', 1)->lockForUpdate()->first();
            $existing = DB::table($tabla)->where($values)->first();
            $nuevo = $existing ? $existing->$id : DB::table($tabla)->insertGetId($values, $id);

            return ['registrado' => ! $existing, 'id' => $nuevo, 'catalogo' => $data['catalogo'], 'nota' => 'El catálogo de lectura se actualiza en un máximo de 10 minutos.'];
        });
    }
}
