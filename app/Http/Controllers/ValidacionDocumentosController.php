<?php

namespace App\Http\Controllers;

use App\Models\ValidacionDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ValidacionDocumentosController extends Controller
{
    public function index(): Response
    {
        $validaciones = ValidacionDocumento::query()
            ->with('subidor:id,name')
            ->latest()
            ->paginate(15)
            ->through(fn (ValidacionDocumento $validacion): array => [
                'id' => $validacion->id,
                'placa' => $validacion->placa,
                'marca' => $validacion->datos_documento['marca'] ?? 'No detectada',
                'modelo' => $validacion->datos_documento['modelo'] ?? 'No detectado',
                'estado_revision' => $validacion->estado_revision,
                'alertas_criticas' => $validacion->alertas_criticas,
                'subido_por' => $validacion->subidor?->name ?? 'Usuario eliminado',
                'created_at' => $validacion->created_at?->toIso8601String(),
            ]);

        return Inertia::render('ValidacionDocumentos/Index', [
            'validaciones' => $validaciones,
            'pendientes' => ValidacionDocumento::query()->where('estado_revision', 'pendiente')->count(),
        ]);
    }

    public function show(ValidacionDocumento $validacion): Response
    {
        $validacion->load(['subidor:id,name', 'revisor:id,name']);

        return Inertia::render('ValidacionDocumentos/Show', [
            'validacion' => [
                'id' => $validacion->id,
                'placa' => $validacion->placa,
                'estado_revision' => $validacion->estado_revision,
                'datos_documento' => $validacion->datos_documento,
                'datos_oficiales' => $validacion->datos_oficiales,
                'comparacion' => $validacion->comparacion,
                'alertas_criticas' => $validacion->alertas_criticas,
                'comentario_revision' => $validacion->comentario_revision,
                'documento_url' => route('validacion-documentos.documento', $validacion),
                'subido_por' => $validacion->subidor?->name ?? 'Usuario eliminado',
                'revisado_por' => $validacion->revisor?->name,
                'created_at' => $validacion->created_at?->toIso8601String(),
                'revisado_en' => $validacion->revisado_en?->toIso8601String(),
            ],
            'success' => session('success'),
        ]);
    }

    public function documento(ValidacionDocumento $validacion): StreamedResponse
    {
        $disk = Storage::disk('local');

        abort_unless($disk->exists($validacion->ruta_documento), 404);

        $extension = pathinfo($validacion->ruta_documento, PATHINFO_EXTENSION) ?: 'bin';

        return $disk->download(
            $validacion->ruta_documento,
            "matricula_{$validacion->placa}.{$extension}",
            [
                'Content-Type' => $validacion->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function decidir(Request $request, ValidacionDocumento $validacion): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['aprobada', 'rechazada'])],
            'comentario_revision' => ['nullable', 'string', 'max:2000'],
        ]);
        $comentario = trim($validated['comentario_revision'] ?? '');

        $actualizadas = ValidacionDocumento::query()
            ->whereKey($validacion->getKey())
            ->where('estado_revision', 'pendiente')
            ->update([
                'estado_revision' => $validated['decision'],
                'comentario_revision' => $comentario === '' ? null : $comentario,
                'revisado_por' => $request->user()->id,
                'revisado_en' => now(),
                'updated_at' => now(),
            ]);

        abort_unless($actualizadas === 1, 409, 'Este expediente ya fue revisado.');

        $mensaje = $validated['decision'] === 'aprobada'
            ? 'Documento aprobado.'
            : 'Documento rechazado.';

        return to_route('validacion-documentos.show', $validacion)->with('success', $mensaje);
    }
}
