<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Cliente extends Model
{
    protected $table = 'clientes';

    protected $primaryKey = 'id_cliente';

    protected $fillable = [
        'id_tipo_cliente',
        'nombre',
        'apellido',
        'telefono',
        'correo',
        'direccion',
        'cedula',
        'ruta_uafe',
        'ruta_cedula',
    ];

    public function tipoCliente(): BelongsTo
    {
        return $this->belongsTo(TipoCliente::class, 'id_tipo_cliente', 'id_tipo_cliente');
    }

    public function vehiculosVendidos(): HasMany
    {
        return $this->hasMany(Vehiculo::class, 'id_cliente_vendedor', 'id_cliente');
    }

    public function getNombreCompletoAttribute(): string
    {
        $apellido = trim((string) ($this->apellido ?? ''));
        $nombre = trim((string) ($this->nombre ?? ''));

        if ($apellido === '' || $apellido === '-') {
            return $nombre;
        }

        return trim("{$nombre} {$apellido}");
    }

    /**
     * Crea o actualiza un cliente asignando automáticamente el tipo según su rol:
     * - Nuevo como vendedor -> Vendedor
     * - Existente Comprador que ahora vende -> Ambos
     * - Existente Vendedor que ahora compra -> Ambos
     *
     * @param  array<string, mixed>  $datos
     */
    public static function sincronizarConRol(string $cedula, array $datos, string $rolOperacion): self
    {
        $cedulaLimpia = preg_replace('/[^0-9A-Za-z]/', '', trim($cedula)) ?? '';
        $nombreCompleto = trim((string) ($datos['nombre_completo'] ?? ''));

        $partes = $nombreCompleto !== '' ? (preg_split('/\s+/', $nombreCompleto) ?: []) : [];
        $nombre = isset($datos['nombre']) && trim((string) $datos['nombre']) !== ''
            ? trim((string) $datos['nombre'])
            : (count($partes) > 2 ? implode(' ', array_slice($partes, 0, 2)) : ($partes[0] ?? ''));
        $apellido = isset($datos['apellido']) && trim((string) $datos['apellido']) !== ''
            ? trim((string) $datos['apellido'])
            : (count($partes) > 2 ? implode(' ', array_slice($partes, 2)) : (count($partes) === 2 ? $partes[1] : '-'));

        $existente = self::query()
            ->with('tipoCliente')
            ->where('cedula', $cedulaLimpia)
            ->first();

        $tieneTipoCliente = Schema::hasTable('tipo_cliente') && Schema::hasColumn('clientes', 'id_tipo_cliente');
        $idTipoNuevo = null;

        if ($tieneTipoCliente) {
            $tipoComprador = TipoCliente::firstOrCreate(
                ['nombre' => TipoCliente::COMPRADOR],
                ['descripcion' => 'Cliente que compra o reserva vehículos en el concesionario.'],
            );
            $tipoVendedor = TipoCliente::firstOrCreate(
                ['nombre' => TipoCliente::VENDEDOR],
                ['descripcion' => 'Persona natural o jurídica que vende un vehículo al concesionario.'],
            );
            $tipoAmbos = TipoCliente::firstOrCreate(
                ['nombre' => TipoCliente::AMBOS],
                ['descripcion' => 'Cliente que ha vendido y comprado vehículos en el concesionario.'],
            );

            $tipoActual = $existente?->tipoCliente?->nombre;

            if ($rolOperacion === TipoCliente::VENDEDOR) {
                $idTipoNuevo = match ($tipoActual) {
                    TipoCliente::COMPRADOR, TipoCliente::AMBOS => $tipoAmbos->id_tipo_cliente,
                    default => $tipoVendedor->id_tipo_cliente,
                };
            } else {
                $idTipoNuevo = match ($tipoActual) {
                    TipoCliente::VENDEDOR, TipoCliente::AMBOS => $tipoAmbos->id_tipo_cliente,
                    default => $tipoComprador->id_tipo_cliente,
                };
            }
        }

        $atributos = [];
        if ($nombre !== '') {
            $atributos['nombre'] = mb_substr($nombre, 0, 100);
        }
        if ($apellido !== '') {
            $atributos['apellido'] = mb_substr($apellido, 0, 100);
        }
        if ($tieneTipoCliente && $idTipoNuevo !== null) {
            $atributos['id_tipo_cliente'] = $idTipoNuevo;
        }

        foreach ([
            'telefono' => 30,
            'correo' => 120,
            'direccion' => 200,
            'ruta_cedula' => 255,
            'ruta_uafe' => 255,
        ] as $campo => $maxLen) {
            $valor = trim((string) ($datos[$campo] ?? ''));
            if ($valor !== '') {
                $atributos[$campo] = mb_substr($valor, 0, $maxLen);
            }
        }

        if ($existente !== null) {
            $existente->update($atributos);

            return $existente->fresh(['tipoCliente']);
        }

        $atributos['cedula'] = mb_substr($cedulaLimpia, 0, 30);
        $atributos['nombre'] ??= 'SIN NOMBRE';
        $atributos['apellido'] ??= '-';

        return self::create($atributos)->load('tipoCliente');
    }
}
