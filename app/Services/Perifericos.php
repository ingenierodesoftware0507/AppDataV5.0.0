<?php

namespace App\Services;

use App\Libraries\Encrypt;
use Illuminate\Support\Facades\Log;

// SPEC 08: periféricos (sensor de RPM, sonda de temperatura y captador) asociados a un equipo de gases u opacidad.
// La asociación sale de la configuración descargada del equipo (storage/app/system/<carpeta>/<tipo>_<id>.json),
// cuyas claves traen el idconf_maquina del periférico; se ofrecen solo los vigentes en lineas.json.
// Mismas reglas que asociadosVigentes() de easytecmmas2 (www/pista/perifericos/nucleo.js).
class Perifericos
{
    // Líneas de inspección por grupo de vista
    const LINEAS = [
        'livianos' => [1, 4, 7, 8, 11, 12],
        'motos' => [3, 9, 10],
    ];

    // grupo en pantalla => tipo, nombre de la prueba en lineas.json y claves de la configuración
    const TIPOS = [
        'rpm' => ['tipo' => '21', 'prueba' => 'perifericorpm', 'claves' => ['sensorBateria', 'sensorInduccion', 'sensorVibracion']],
        'temperatura' => ['tipo' => '22', 'prueba' => 'perifericotemperatura', 'claves' => ['sondaTemperatura']],
        'captador' => ['tipo' => '14', 'prueba' => 'captador', 'claves' => ['captador']],
    ];

    // Prefijo del archivo de configuración según conf_idtipo_prueba (igual que Cprincipal@getTipoPruebaNombre)
    const PREFIJOS = [
        '3' => 'gases',
        '2' => 'opacidad',
        '14' => 'captador',
        '21' => 'perifericorpm',
        '22' => 'perifericotemperatura',
    ];

    private static $lineas = null;

    // Periféricos disponibles para el equipo: ['rpm' => [], 'temperatura' => [], 'captador' => []].
    // Cada elemento: ['id', 'nombre', 'marca', 'serial', 'serie'] en mayúsculas.
    // $idcaptador (opcional): suma los asociados a ese captador a RPM y temperatura, sin repetidos.
    // Un captador que no está entre los del equipo se ignora.
    public static function disponibles($idmaquina, $grupo, $idcaptador = null)
    {
        $vacio = ['rpm' => [], 'temperatura' => [], 'captador' => []];
        $idmaquina = (string) $idmaquina;
        if (!ctype_digit($idmaquina) || !isset(self::LINEAS[$grupo])) {
            return $vacio;
        }

        $conf = self::leerConfig($idmaquina, $grupo);
        $resultado = [];
        foreach (self::TIPOS as $nombre => $t) {
            $resultado[$nombre] = self::asociadosVigentes($conf, $t, $grupo);
        }

        $idcaptador = (string) $idcaptador;
        if ($idcaptador !== '' && self::contiene($resultado['captador'], $idcaptador)) {
            $confCaptador = self::leerConfig($idcaptador, $grupo);
            foreach (['rpm', 'temperatura'] as $nombre) {
                foreach (self::asociadosVigentes($confCaptador, self::TIPOS[$nombre], $grupo) as $p) {
                    if (!self::contiene($resultado[$nombre], $p['id'])) {
                        $resultado[$nombre][] = $p;
                    }
                }
            }
        }
        return $resultado;
    }

    // Periféricos de un tipo asociados en una configuración y vigentes. Un asociado se descarta, con el
    // motivo en el log, si no está en lineas.json, está inactivo, es de otra línea, no es del tipo de su
    // clave, o su configuración descargada no existe o está inactiva.
    private static function asociadosVigentes($conf, $t, $grupo)
    {
        $lista = [];
        foreach ($conf as $c) {
            if (!is_object($c) || !isset($c->nombre) || !in_array($c->nombre, $t['claves'], true)) {
                continue;
            }
            $valor = isset($c->valor) ? trim((string) $c->valor) : '';
            if ($valor === '' || $valor === '0' || !ctype_digit($valor) || self::contiene($lista, $valor)) {
                continue;
            }

            $m = self::buscarLinea($valor);
            $motivo = null;
            if ($m === null) {
                $motivo = 'no está en lineas.json';
            } elseif ((string) $m->activo !== '1') {
                $motivo = 'inactivo en lineas.json';
            } elseif (!in_array((int) $m->idconf_linea_inspeccion, self::LINEAS[$grupo], true)) {
                $motivo = 'es de otra línea de inspección';
            } elseif ((string) $m->conf_idtipo_prueba !== $t['tipo'] || (string) $m->prueba !== $t['prueba']) {
                $motivo = 'no es de tipo ' . $t['prueba'];
            } elseif (!self::activoEnConfig($valor, $grupo)) {
                $motivo = 'sin configuración activa descargada';
            }
            if ($motivo !== null) {
                Log::debug('Periférico ' . $c->nombre . '=' . $valor . ' descartado: ' . $motivo);
                continue;
            }

            $lista[] = [
                'id' => $valor,
                'nombre' => strtoupper($m->nombre),
                'marca' => strtoupper($m->marca),
                'serial' => strtoupper($m->serie_maquina),
                'serie' => strtoupper($m->serie_banco),
            ];
        }
        return $lista;
    }

    private static function contiene($lista, $id)
    {
        foreach ($lista as $p) {
            if ($p['id'] === (string) $id) {
                return true;
            }
        }
        return false;
    }

    private static function buscarLinea($id)
    {
        foreach (self::cargarLineas() as $o) {
            if (is_object($o) && isset($o->idconf_maquina) && (string) $o->idconf_maquina === (string) $id) {
                return $o;
            }
        }
        return null;
    }

    // lineas.json descifrado; vacío si no existe o no es válido. Se lee una vez por petición.
    private static function cargarLineas()
    {
        if (self::$lineas === null) {
            self::$lineas = [];
            $ruta = storage_path('app/system/lineas.json');
            if (is_file($ruta)) {
                $json = json_decode((new Encrypt())->decrypt(file_get_contents($ruta)));
                if (is_array($json)) {
                    self::$lineas = $json;
                }
            }
        }
        return self::$lineas;
    }

    // Configuración descargada de una máquina como lista de {nombre, valor}; vacía si no existe o es inválida.
    // El archivo depende de su tipo en lineas.json; opacidad se guarda siempre en livianos (ver Cprincipal@getlineas).
    private static function leerConfig($id, $grupo)
    {
        $m = self::buscarLinea($id);
        if ($m === null || !isset(self::PREFIJOS[(string) $m->conf_idtipo_prueba])) {
            return [];
        }
        $tipo = (string) $m->conf_idtipo_prueba;
        $carpeta = $tipo === '2' ? 'livianos' : $grupo;
        $ruta = storage_path('app/system/' . $carpeta . '/' . self::PREFIJOS[$tipo] . '_' . $id . '.json');
        if (!is_file($ruta)) {
            return [];
        }
        $conf = json_decode((new Encrypt())->decrypt(file_get_contents($ruta)));
        return is_array($conf) ? $conf : [];
    }

    // La configuración descargada del periférico existe y tiene activo = 1
    private static function activoEnConfig($id, $grupo)
    {
        foreach (self::leerConfig($id, $grupo) as $c) {
            if (is_object($c) && isset($c->nombre, $c->valor) && $c->nombre === 'activo' && (string) $c->valor === '1') {
                return true;
            }
        }
        return false;
    }
}
