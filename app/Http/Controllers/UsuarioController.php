<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UsuarioController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(
            $request->user()?->hasRole('Administrador'),
            403,
            'Solo el Administrador puede acceder a la gestión de usuarios y roles.',
        );

        $usuarios = User::query()
            ->with(['roles:id,name', 'patio:id_patio,nombre'])
            ->orderBy('name')
            ->get()
            ->map(static fn (User $usuario): array => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'email' => $usuario->email,
                'patio_nombre' => $usuario->patio?->nombre,
                'rol_actual' => $usuario->roles->first()?->name ?? '',
                'roles' => $usuario->roles->pluck('name')->values()->all(),
                'created_at' => $usuario->created_at?->toIso8601String(),
            ])
            ->values();

        $rolesDisponibles = Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        return Inertia::render('Usuarios/Index', [
            'usuarios' => $usuarios,
            'rolesDisponibles' => $rolesDisponibles,
            'success' => session('success'),
        ]);
    }

    public function actualizarRol(Request $request, User $user): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('Administrador'),
            403,
            'Solo el Administrador puede asignar o modificar roles de usuarios.',
        );

        $validated = $request->validate([
            'role' => ['nullable', 'string', 'exists:roles,name'],
        ]);

        $nuevoRol = isset($validated['role']) && trim($validated['role']) !== ''
            ? trim($validated['role'])
            : null;

        if ($user->id === $request->user()->id && $nuevoRol !== 'Administrador') {
            throw ValidationException::withMessages([
                'role' => 'No puedes quitarte a ti mismo el rol de Administrador.',
            ]);
        }

        DB::transaction(function () use ($user, $nuevoRol): void {
            if ($nuevoRol === null) {
                $user->syncRoles([]);
                $user->update(['role_id' => null]);
            } else {
                $rolModelo = Role::query()->where('name', $nuevoRol)->firstOrFail();
                $user->syncRoles([$rolModelo->name]);
                $user->update(['role_id' => $rolModelo->id]);
            }
        });

        $mensaje = $nuevoRol === null
            ? "Se removió el rol asignado a {$user->name}."
            : "Rol «{$nuevoRol}» asignado correctamente a {$user->name}.";

        return to_route('usuarios.index')->with('success', $mensaje);
    }
}
