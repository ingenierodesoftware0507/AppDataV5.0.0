<?php

namespace App\Http\Middleware;

use App\Services\Licencia;
use Closure;
use Illuminate\Http\Request;

// Solo deja pasar si el login se validó en el servidor (Clogin@login creó sesionUser) y la
// licencia del dispositivo sigue válida (Licencia::validar revalida cada pocos minutos).
// Las llamadas AJAX reciben JSON 401/403; la navegación normal vuelve al login.
class VerificarSesion
{
    public function handle(Request $request, Closure $next)
    {
        if (!session('sesionUser')) {
            return $this->rechazar($request, 401, 'Sesión no iniciada o vencida');
        }

        $sesionLicencia = session('licencia');
        if (empty($sesionLicencia['dominio']) || empty($sesionLicencia['mac'])) {
            session()->forget('sesionUser');
            return $this->rechazar($request, 401, 'Sesión no iniciada o vencida');
        }

        $licencia = Licencia::validar($sesionLicencia['dominio'], $sesionLicencia['mac']);
        session(['licencia' => $licencia + ['dominio' => $sesionLicencia['dominio'], 'mac' => $sesionLicencia['mac']]]);

        if (!$licencia['permitido']) {
            // Sin conexión se rechaza la petición pero se conserva la sesión (es un fallo temporal)
            if ($licencia['motivo'] !== 'sin_conexion') {
                session()->forget('sesionUser');
            }
            return $this->rechazar($request, 403, $licencia['mensaje']);
        }

        return $next($request);
    }

    private function rechazar(Request $request, int $codigo, string $mensaje)
    {
        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['message' => $mensaje], $codigo);
        }

        // Los bloqueos de licencia (403) ya los muestra el login al validarla: no se repite el mensaje
        $redireccion = redirect('/');
        return $codigo === 403 ? $redireccion : $redireccion->with('error', $mensaje);
    }
}
