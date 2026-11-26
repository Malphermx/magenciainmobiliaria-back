<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Authorization, Content-Type, Access-Control-Allow-Headers, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'auth_helpers.php';
require 'decode.php';

$headers = apache_request_headers();
$jwt = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : "";

if ($jwt && validateToken($jwt)) {
    $user_id      = extractToken($jwt);
    $tipo_usuario = extractUserType($jwt);

    include "conectar.php";
    $conn = conectarDB();

    if ($conn->connect_error) {
        echo json_encode(["error" => "Error de conexión: " . $conn->connect_error]);
        exit();
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // ---------------------------------------------------------
    // Method override: permite enviar PUT/DELETE vía POST
    // (necesario para multipart/form-data con archivos)
    // ---------------------------------------------------------
    if ($method === 'POST' && isset($_POST['_method'])) {
        $override = strtoupper($_POST['_method']);
        if (in_array($override, ['PUT', 'DELETE', 'PATCH'])) {
            $method = $override;
        }
    }

    switch ($method) {
        case 'GET':
            verificarAcceso('admin', $tipo_usuario);
            if (isset($_GET['id']) && $_GET['id'] !== '') {
                obtenerPropiedadPorId($conn, intval($_GET['id']));
            } else {
                obtenerPropiedades($conn);
            }
            break;
        case 'POST':
            verificarAcceso('admin', $tipo_usuario);
            crearPropiedad($conn);
            break;
        case 'PUT':
            verificarAcceso('admin', $tipo_usuario);
            actualizarPropiedad($conn);
            break;
        case 'DELETE':
            verificarAcceso('admin', $tipo_usuario);
            eliminarPropiedad($conn);
            break;
        default:
            echo json_encode(["error" => "Método no soportado"]);
            break;
    }

    $conn->close();
} else {
    http_response_code(401);
    echo json_encode(["message" => "Unauthorized"]);
}

/* =========================================================
   HELPERS GENERALES
   ========================================================= */

function verificarAcceso($rolRequerido, $tipoUsuarioActual) {
    switch ($tipoUsuarioActual) {
        case "2":
            $tipoUsuarioActual = 'Usuario';
            break;
        default:
            $tipoUsuarioActual = 'admin';
    }

    if ($tipoUsuarioActual !== $rolRequerido) {
        http_response_code(403);
        echo json_encode(["error" => "Acceso denegado"]);
        exit();
    }
}

function extractUserType($jwt) {
    $decoded = decodeToken($jwt);
    return $decoded['data']->idTipoUsuario ?? null;
}

function getUploadDirs() {
    $base       = __DIR__ . "/uploads/propiedades/";
    $fotosDir   = $base . "fotos/";
    $planosDir  = $base . "planos/";

    foreach ([$fotosDir, $planosDir] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    return ["fotos" => $fotosDir, "planos" => $planosDir];
}

function esImagenValida($tmpName) {
    $info = @getimagesize($tmpName);
    if (!$info) return false;
    return in_array($info['mime'], ['image/jpeg', 'image/png']);
}

function procesarArchivos($files, $destDir, $max = 10, $prefijo = "file") {
    $guardados = [];

    if (!isset($files['name']) || !is_array($files['name'])) {
        return $guardados;
    }

    $validos = [];
    foreach ($files['name'] as $i => $name) {
        if (!empty($name) && $files['error'][$i] === UPLOAD_ERR_OK) {
            $validos[$i] = $name;
        }
    }

    if (count($validos) > $max) {
        throw new Exception("Máximo {$max} archivos por grupo ({$prefijo})");
    }

    foreach ($validos as $i => $name) {
        $tmp = $files['tmp_name'][$i];

        if (!esImagenValida($tmp)) {
            throw new Exception("El archivo '{$name}' no es JPG/JPEG/PNG válido");
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            throw new Exception("Extensión no permitida: {$name}");
        }

        $nombreFinal = uniqid($prefijo . "_", true) . "." . $ext;
        $rutaCompleta = $destDir . $nombreFinal;

        if (!move_uploaded_file($tmp, $rutaCompleta)) {
            throw new Exception("Error al mover archivo: {$name}");
        }

        $guardados[] = [
            "ruta"            => "uploads/propiedades/" . basename($destDir) . "/" . $nombreFinal,
            "nombre_original" => $name,
            "mime"            => mime_content_type($rutaCompleta),
            "tamano"          => filesize($rutaCompleta),
        ];
    }

    return $guardados;
}

/**
 * Obtiene los datos de entrada según el método real.
 * IMPORTANTE: si llega por POST (incluso con _method=PUT), los datos
 * ya están parseados en $_POST por PHP (multipart/form-data).
 */
function obtenerDatosEntrada() {
    if (!empty($_POST)) {
        return $_POST;
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $datos  = [];

    if ($method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $datos = json_decode(file_get_contents("php://input"), true) ?? [];
        } else {
            parse_str(file_get_contents("php://input"), $datos);
        }
    }

    return $datos;
}

function validarDatosPropiedad($d, &$errores) {
    if (empty($d['titulo']))         $errores[] = "El campo 'titulo' es obligatorio";
    if (empty($d['direccion']))      $errores[] = "El campo 'direccion' es obligatorio";
    if (empty($d['descripcion']))    $errores[] = "El campo 'descripcion' es obligatorio";
    if (empty($d['operacion']))      $errores[] = "El campo 'operacion' es obligatorio";
    if (empty($d['tipo_propiedad_id'])) $errores[] = "El campo 'tipo_propiedad_id' es obligatorio";

    if (!in_array($d['operacion'] ?? '', ['venta', 'renta'])) {
        $errores[] = "'operacion' debe ser 'venta' o 'renta'";
    }
    if (!in_array($d['moneda'] ?? 'MXN', ['MXN', 'USD'])) {
        $errores[] = "'moneda' debe ser 'MXN' o 'USD'";
    }
    if (($d['operacion'] ?? '') === 'venta' && empty($d['precio_venta'])) {
        $errores[] = "'precio_venta' es obligatorio para operación 'venta'";
    }
    if (($d['operacion'] ?? '') === 'renta' && empty($d['precio_renta'])) {
        $errores[] = "'precio_renta' es obligatorio para operación 'renta'";
    }

    $emails     = $d['emails']     ?? [];
    $telefonos  = $d['telefonos']  ?? [];
    $whatsapps  = $d['whatsapps']  ?? [];

    if (!is_array($emails) || count(array_filter($emails)) === 0) {
        $errores[] = "Debe proporcionar al menos un email de contacto";
    }
    if (!is_array($telefonos) || count(array_filter($telefonos)) === 0) {
        $errores[] = "Debe proporcionar al menos un teléfono de contacto";
    }
    if (!is_array($whatsapps) || count(array_filter($whatsapps)) === 0) {
        $errores[] = "Debe proporcionar al menos un whatsapp de contacto";
    }
}

/**
 * Infiere el tipo de bind_param a partir del valor PHP.
 */
function inferirTipoBind($v) {
    if (is_int($v))   return 'i';
    if (is_float($v)) return 'd';
    return 's';
}

/**
 * Genera la cadena de tipos para bind_param a partir del arreglo de valores.
 */
function generarTiposBind(array $valores) {
    $tipos = '';
    foreach ($valores as $v) {
        $tipos .= inferirTipoBind($v);
    }
    return $tipos;
}

/**
 * NUEVO: elimina fotos y planos de una propiedad según sus IDs.
 * Valida que cada ID pertenezca a la propiedad antes de borrar.
 * Devuelve la lista de rutas físicas que deben eliminarse del disco.
 */
function recolectarYMarcarEliminacion($conn, $propiedadId, $fotosIds, $planosIds) {
    $rutasFisicas = [];

    // --- FOTOS ---
    if (!empty($fotosIds) && is_array($fotosIds)) {
        $idsLimpios = array_values(array_filter(array_map('intval', $fotosIds), fn($v) => $v > 0));
        if (!empty($idsLimpios)) {
            $placeholders = implode(',', array_fill(0, count($idsLimpios), '?'));
            $tipos        = str_repeat('i', count($idsLimpios) + 1);

            $sql = "SELECT id, ruta_archivo FROM propiedad_fotografia
                    WHERE propiedad_id = ? AND id IN ($placeholders)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Error preparando SELECT fotos: " . $conn->error);

            $params = array_merge([$propiedadId], $idsLimpios);
            $stmt->bind_param($tipos, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (!empty($row['ruta_archivo'])) {
                    $rutasFisicas[] = __DIR__ . "/" . $row['ruta_archivo'];
                }
            }
            $stmt->close();

            // Borrar de la BD (solo las que pertenecen a esta propiedad)
            $del = $conn->prepare("DELETE FROM propiedad_fotografia WHERE propiedad_id = ? AND id IN ($placeholders)");
            if (!$del) throw new Exception("Error preparando DELETE fotos: " . $conn->error);
            $del->bind_param($tipos, ...$params);
            if (!$del->execute()) throw new Exception("Error al eliminar fotos: " . $del->error);
            $del->close();
        }
    }

    // --- PLANOS ---
    if (!empty($planosIds) && is_array($planosIds)) {
        $idsLimpios = array_values(array_filter(array_map('intval', $planosIds), fn($v) => $v > 0));
        if (!empty($idsLimpios)) {
            $placeholders = implode(',', array_fill(0, count($idsLimpios), '?'));
            $tipos        = str_repeat('i', count($idsLimpios) + 1);

            $sql = "SELECT id, ruta_archivo FROM propiedad_plano
                    WHERE propiedad_id = ? AND id IN ($placeholders)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Error preparando SELECT planos: " . $conn->error);

            $params = array_merge([$propiedadId], $idsLimpios);
            $stmt->bind_param($tipos, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (!empty($row['ruta_archivo'])) {
                    $rutasFisicas[] = __DIR__ . "/" . $row['ruta_archivo'];
                }
            }
            $stmt->close();

            $del = $conn->prepare("DELETE FROM propiedad_plano WHERE propiedad_id = ? AND id IN ($placeholders)");
            if (!$del) throw new Exception("Error preparando DELETE planos: " . $conn->error);
            $del->bind_param($tipos, ...$params);
            if (!$del->execute()) throw new Exception("Error al eliminar planos: " . $del->error);
            $del->close();
        }
    }

    return $rutasFisicas;
}

/* =========================================================
   GET - LISTAR PROPIEDADES
   ========================================================= */
function obtenerPropiedades($conn) {
    $sql = "
        SELECT
            p.*,
            t.nombre AS tipo_propiedad_nombre,
            (SELECT GROUP_CONCAT(ruta_archivo ORDER BY orden SEPARATOR '||')
                FROM propiedad_fotografia WHERE propiedad_id = p.id) AS fotos,
            (SELECT GROUP_CONCAT(ruta_archivo ORDER BY orden SEPARATOR '||')
                FROM propiedad_plano WHERE propiedad_id = p.id) AS planos,
            (SELECT GROUP_CONCAT(email SEPARATOR '||')
                FROM propiedad_contacto WHERE propiedad_id = p.id) AS emails,
            (SELECT GROUP_CONCAT(telefono SEPARATOR '||')
                FROM propiedad_contacto WHERE propiedad_id = p.id) AS telefonos,
            (SELECT GROUP_CONCAT(whatsapp SEPARATOR '||')
                FROM propiedad_contacto WHERE propiedad_id = p.id) AS whatsapps
        FROM propiedad p
        JOIN tipo_propiedad t ON t.id = p.tipo_propiedad_id
        ORDER BY p.id DESC
    ";

    $result = $conn->query($sql);
    if (!$result) {
        echo json_encode(["error" => "Error al obtener propiedades: " . $conn->error]);
        return;
    }

    $propiedades = [];
    while ($row = $result->fetch_assoc()) {
        $row['fotos']     = $row['fotos']     ? explode('||', $row['fotos'])     : [];
        $row['planos']    = $row['planos']    ? explode('||', $row['planos'])    : [];
        $row['emails']    = $row['emails']    ? explode('||', $row['emails'])    : [];
        $row['telefonos'] = $row['telefonos'] ? explode('||', $row['telefonos']) : [];
        $row['whatsapps'] = $row['whatsapps'] ? explode('||', $row['whatsapps']) : [];
        $propiedades[] = $row;
    }

    echo json_encode(["data" => $propiedades]);
}

/* =========================================================
   GET - POR ID
   ========================================================= */
function obtenerPropiedadPorId($conn, $id) {
    $stmt = $conn->prepare("SELECT p.*, t.nombre AS tipo_propiedad_nombre
                            FROM propiedad p
                            JOIN tipo_propiedad t ON t.id = p.tipo_propiedad_id
                            WHERE p.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        echo json_encode(["error" => "Propiedad no encontrada"]);
        return;
    }

    $propiedad = $res->fetch_assoc();
    $stmt->close();

    $propiedad['fotos'] = [];
    $stmtF = $conn->prepare("SELECT id, ruta_archivo, orden, es_principal FROM propiedad_fotografia WHERE propiedad_id = ? ORDER BY orden ASC");
    $stmtF->bind_param("i", $id);
    $stmtF->execute();
    $resF = $stmtF->get_result();
    while ($f = $resF->fetch_assoc()) $propiedad['fotos'][] = $f;
    $stmtF->close();

    $propiedad['planos'] = [];
    $stmtP = $conn->prepare("SELECT id, ruta_archivo, orden FROM propiedad_plano WHERE propiedad_id = ? ORDER BY orden ASC");
    $stmtP->bind_param("i", $id);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    while ($p = $resP->fetch_assoc()) $propiedad['planos'][] = $p;
    $stmtP->close();

    $propiedad['contactos'] = [];
    $stmtC = $conn->prepare("SELECT id, email, telefono, whatsapp FROM propiedad_contacto WHERE propiedad_id = ?");
    $stmtC->bind_param("i", $id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while ($c = $resC->fetch_assoc()) $propiedad['contactos'][] = $c;
    $stmtC->close();

    echo json_encode(["data" => $propiedad]);
}

/* =========================================================
   POST - CREAR PROPIEDAD
   ========================================================= */
function crearPropiedad($conn) {
    $datos   = $_POST;
    $errores = [];
    validarDatosPropiedad($datos, $errores);

    if (!empty($errores)) {
        echo json_encode(["error" => implode(" | ", $errores)]);
        return;
    }

    $fotosCount  = isset($_FILES['fotos']['name'])  ? count(array_filter($_FILES['fotos']['name']))  : 0;
    $planosCount = isset($_FILES['planos']['name']) ? count(array_filter($_FILES['planos']['name'])) : 0;

    if ($fotosCount === 0) {
        echo json_encode(["error" => "Debe subir al menos 1 fotografía"]);
        return;
    }
    if ($fotosCount > 10 || $planosCount > 10) {
        echo json_encode(["error" => "Máximo 10 fotografías y 10 planos"]);
        return;
    }

    $dirs = getUploadDirs();
    $conn->begin_transaction();

    $rutasGuardadas = [];

    try {
        $sql = "INSERT INTO propiedad (
                    admin_user_id, titulo, numero_referencia,
                    direccion_tipo, direccion, latitud, longitud,
                    operacion, precio_venta, precio_renta, moneda,
                    tipo_propiedad_id, banos, medios_banos,
                    superficie_total, superficie_construida, superficie_terreno,
                    plazas_estacionamiento, anio_construccion, gastos_comunes,
                    descripcion, url_youtube, url_tour_virtual, estatus
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Error preparando INSERT: " . $conn->error);

        $valores = [
            (int)($GLOBALS['user_id'] ?? 1),
            trim($datos['titulo']),
            !empty($datos['numero_referencia']) ? $datos['numero_referencia'] : null,
            $datos['direccion_tipo'] ?? 'exacta',
            trim($datos['direccion']),
            !empty($datos['latitud'])  ? (float)$datos['latitud']  : null,
            !empty($datos['longitud']) ? (float)$datos['longitud'] : null,
            $datos['operacion'],
            !empty($datos['precio_venta']) ? (float)$datos['precio_venta'] : null,
            !empty($datos['precio_renta']) ? (float)$datos['precio_renta'] : null,
            $datos['moneda'] ?? 'MXN',
            (int)$datos['tipo_propiedad_id'],
            !empty($datos['banos']) ? (int)$datos['banos'] : null,
            !empty($datos['medios_banos']) ? (int)$datos['medios_banos'] : null,
            !empty($datos['superficie_total']) ? (float)$datos['superficie_total'] : null,
            !empty($datos['superficie_construida']) ? (float)$datos['superficie_construida'] : null,
            !empty($datos['superficie_terreno']) ? (float)$datos['superficie_terreno'] : null,
            !empty($datos['plazas_estacionamiento']) ? (int)$datos['plazas_estacionamiento'] : null,
            !empty($datos['anio_construccion']) ? (int)$datos['anio_construccion'] : null,
            !empty($datos['gastos_comunes']) ? (float)$datos['gastos_comunes'] : null,
            trim($datos['descripcion']),
            !empty($datos['url_youtube']) ? $datos['url_youtube'] : null,
            !empty($datos['url_tour_virtual']) ? $datos['url_tour_virtual'] : null,
            $datos['estatus'] ?? 'borrador'
        ];

        $tipos = generarTiposBind($valores);
        $stmt->bind_param($tipos, ...$valores);

        if (!$stmt->execute()) throw new Exception("Error al registrar propiedad: " . $stmt->error);
        $propiedadId = $conn->insert_id;
        $stmt->close();

        // Fotos
        $fotos = procesarArchivos($_FILES['fotos'] ?? [], $dirs['fotos'], 10, "foto");
        if (empty($fotos)) throw new Exception("No se recibieron fotografías válidas");

        $stmtFoto = $conn->prepare("INSERT INTO propiedad_fotografia
                                    (propiedad_id, ruta_archivo, nombre_original, mime_type, tamano_bytes, orden, es_principal)
                                    VALUES (?,?,?,?,?,?,?)");
        if (!$stmtFoto) throw new Exception("Error preparando INSERT fotos: " . $conn->error);

        $orden = 1;
        foreach ($fotos as $f) {
            $esPrincipal = $orden === 1 ? 1 : 0;
            $stmtFoto->bind_param("issssii", $propiedadId, $f['ruta'], $f['nombre_original'], $f['mime'], $f['tamano'], $orden, $esPrincipal);
            if (!$stmtFoto->execute()) throw new Exception("Error al guardar foto: " . $stmtFoto->error);
            $rutasGuardadas[] = $dirs['fotos'] . basename($f['ruta']);
            $orden++;
        }
        $stmtFoto->close();

        // Planos
        $planos = procesarArchivos($_FILES['planos'] ?? [], $dirs['planos'], 10, "plano");
        if (!empty($planos)) {
            $stmtPlano = $conn->prepare("INSERT INTO propiedad_plano (propiedad_id, ruta_archivo, nombre_original, mime_type, tamano_bytes, orden) VALUES (?,?,?,?,?,?)");
            if (!$stmtPlano) throw new Exception("Error preparando INSERT planos: " . $conn->error);

            $orden = 1;
            foreach ($planos as $p) {
                $stmtPlano->bind_param("isssii", $propiedadId, $p['ruta'], $p['nombre_original'], $p['mime'], $p['tamano'], $orden);
                if (!$stmtPlano->execute()) throw new Exception("Error al guardar plano: " . $stmtPlano->error);
                $rutasGuardadas[] = $dirs['planos'] . basename($p['ruta']);
                $orden++;
            }
            $stmtPlano->close();
        }

        // Contactos
        $emails    = $datos['emails'] ?? [];
        $telefonos = $datos['telefonos'] ?? [];
        $whatsapps = $datos['whatsapps'] ?? [];

        $stmtC = $conn->prepare("INSERT INTO propiedad_contacto (propiedad_id, email, telefono, whatsapp) VALUES (?,?,?,?)");
        if (!$stmtC) throw new Exception("Error preparando INSERT contacto: " . $conn->error);

        $total = max(count($emails), count($telefonos), count($whatsapps));
        for ($i = 0; $i < $total; $i++) {
            $email    = trim($emails[$i] ?? '');
            $telefono = trim($telefonos[$i] ?? '');
            $whatsapp = trim($whatsapps[$i] ?? '');
            if ($email === '' && $telefono === '' && $whatsapp === '') continue;
            $stmtC->bind_param("isss", $propiedadId, $email, $telefono, $whatsapp);
            if (!$stmtC->execute()) throw new Exception("Error al guardar contacto: " . $stmtC->error);
        }
        $stmtC->close();

        $conn->commit();
        echo json_encode(["registrado" => true, "id" => $propiedadId]);

    } catch (Exception $e) {
        $conn->rollback();
        foreach ($rutasGuardadas as $r) {
            if (file_exists($r)) @unlink($r);
        }
        echo json_encode(["error" => $e->getMessage()]);
    }
}

/* =========================================================
   PUT - ACTUALIZAR PROPIEDAD (con eliminación de archivos)
   ========================================================= */
function actualizarPropiedad($conn) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        echo json_encode(["error" => "El parámetro 'id' es obligatorio"]);
        return;
    }

    $datos = !empty($_POST) ? $_POST : obtenerDatosEntrada();

    $errores = [];
    validarDatosPropiedad($datos, $errores);

    if (!empty($errores)) {
        echo json_encode(["error" => implode(" | ", $errores)]);
        return;
    }

    $chk = $conn->prepare("SELECT id FROM propiedad WHERE id = ?");
    $chk->bind_param("i", $id);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        echo json_encode(["error" => "Propiedad no encontrada"]);
        return;
    }
    $chk->close();

    $fotosNuevas  = isset($_FILES['fotos']['name'])  ? count(array_filter($_FILES['fotos']['name']))  : 0;
    $planosNuevos = isset($_FILES['planos']['name']) ? count(array_filter($_FILES['planos']['name'])) : 0;

    if ($fotosNuevas > 10 || $planosNuevos > 10) {
        echo json_encode(["error" => "Máximo 10 fotografías y 10 planos por propiedad"]);
        return;
    }

    // NUEVO: IDs de fotos/planos a eliminar
    $fotosAEliminar  = $datos['eliminar_fotos']  ?? [];
    $planosAEliminar = $datos['eliminar_planos'] ?? [];

    if (!is_array($fotosAEliminar))  $fotosAEliminar  = [$fotosAEliminar];
    if (!is_array($planosAEliminar)) $planosAEliminar = [$planosAEliminar];

    $dirs = getUploadDirs();
    $conn->begin_transaction();
    $rutasGuardadas      = [];
    $rutasAEliminarFisico = [];

    try {
        $sql = "UPDATE propiedad SET
                    titulo = ?, numero_referencia = ?,
                    direccion_tipo = ?, direccion = ?, latitud = ?, longitud = ?,
                    operacion = ?, precio_venta = ?, precio_renta = ?, moneda = ?,
                    tipo_propiedad_id = ?, banos = ?, medios_banos = ?,
                    superficie_total = ?, superficie_construida = ?, superficie_terreno = ?,
                    plazas_estacionamiento = ?, anio_construccion = ?, gastos_comunes = ?,
                    descripcion = ?, url_youtube = ?, url_tour_virtual = ?, estatus = ?
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Error preparando UPDATE: " . $conn->error);

        $valores = [
            trim($datos['titulo']),
            !empty($datos['numero_referencia']) ? $datos['numero_referencia'] : null,
            $datos['direccion_tipo'] ?? 'exacta',
            trim($datos['direccion']),
            !empty($datos['latitud'])  ? (float)$datos['latitud']  : null,
            !empty($datos['longitud']) ? (float)$datos['longitud'] : null,
            $datos['operacion'],
            !empty($datos['precio_venta']) ? (float)$datos['precio_venta'] : null,
            !empty($datos['precio_renta']) ? (float)$datos['precio_renta'] : null,
            $datos['moneda'] ?? 'MXN',
            (int)$datos['tipo_propiedad_id'],
            !empty($datos['banos']) ? (int)$datos['banos'] : null,
            !empty($datos['medios_banos']) ? (int)$datos['medios_banos'] : null,
            !empty($datos['superficie_total']) ? (float)$datos['superficie_total'] : null,
            !empty($datos['superficie_construida']) ? (float)$datos['superficie_construida'] : null,
            !empty($datos['superficie_terreno']) ? (float)$datos['superficie_terreno'] : null,
            !empty($datos['plazas_estacionamiento']) ? (int)$datos['plazas_estacionamiento'] : null,
            !empty($datos['anio_construccion']) ? (int)$datos['anio_construccion'] : null,
            !empty($datos['gastos_comunes']) ? (float)$datos['gastos_comunes'] : null,
            trim($datos['descripcion']),
            !empty($datos['url_youtube']) ? $datos['url_youtube'] : null,
            !empty($datos['url_tour_virtual']) ? $datos['url_tour_virtual'] : null,
            $datos['estatus'] ?? 'borrador',
            $id
        ];

        $tipos = generarTiposBind($valores);
        $stmt->bind_param($tipos, ...$valores);

        if (!$stmt->execute()) throw new Exception("Error al actualizar propiedad: " . $stmt->error);
        $stmt->close();

        // NUEVO: procesar eliminación de fotos y planos marcados
        // Se hace ANTES de insertar las nuevas para liberar espacio
        if (!empty($fotosAEliminar) || !empty($planosAEliminar)) {
            $rutasAEliminarFisico = recolectarYMarcarEliminacion(
                $conn,
                $id,
                $fotosAEliminar,
                $planosAEliminar
            );
        }

        // Fotos nuevas
        if ($fotosNuevas > 0) {
            $actuales = $conn->query("SELECT COUNT(*) AS c FROM propiedad_fotografia WHERE propiedad_id = {$id}")->fetch_assoc()['c'];
            if ($actuales + $fotosNuevas > 10) {
                throw new Exception("La propiedad ya tiene {$actuales} fotos. Máximo total permitido: 10");
            }

            $fotos = procesarArchivos($_FILES['fotos'], $dirs['fotos'], 10, "foto");
            $stmtFoto = $conn->prepare("INSERT INTO propiedad_fotografia (propiedad_id, ruta_archivo, nombre_original, mime_type, tamano_bytes, orden) VALUES (?,?,?,?,?,?)");
            $orden = intval($actuales) + 1;
            foreach ($fotos as $f) {
                $stmtFoto->bind_param("isssii", $id, $f['ruta'], $f['nombre_original'], $f['mime'], $f['tamano'], $orden);
                if (!$stmtFoto->execute()) throw new Exception("Error foto: " . $stmtFoto->error);
                $rutasGuardadas[] = $dirs['fotos'] . basename($f['ruta']);
                $orden++;
            }
            $stmtFoto->close();
        }

        // Planos nuevos
        if ($planosNuevos > 0) {
            $actualesP = $conn->query("SELECT COUNT(*) AS c FROM propiedad_plano WHERE propiedad_id = {$id}")->fetch_assoc()['c'];
            if ($actualesP + $planosNuevos > 10) {
                throw new Exception("La propiedad ya tiene {$actualesP} planos. Máximo total permitido: 10");
            }

            $planos = procesarArchivos($_FILES['planos'], $dirs['planos'], 10, "plano");
            $stmtPlano = $conn->prepare("INSERT INTO propiedad_plano (propiedad_id, ruta_archivo, nombre_original, mime_type, tamano_bytes, orden) VALUES (?,?,?,?,?,?)");
            $ordenP = intval($actualesP) + 1;
            foreach ($planos as $p) {
                $stmtPlano->bind_param("isssii", $id, $p['ruta'], $p['nombre_original'], $p['mime'], $p['tamano'], $ordenP);
                if (!$stmtPlano->execute()) throw new Exception("Error plano: " . $stmtPlano->error);
                $rutasGuardadas[] = $dirs['planos'] . basename($p['ruta']);
                $ordenP++;
            }
            $stmtPlano->close();
        }

        // Contactos
        if (isset($datos['emails']) || isset($datos['telefonos']) || isset($datos['whatsapps'])) {
            $conn->query("DELETE FROM propiedad_contacto WHERE propiedad_id = {$id}");

            $emails    = $datos['emails'] ?? [];
            $telefonos = $datos['telefonos'] ?? [];
            $whatsapps = $datos['whatsapps'] ?? [];

            $stmtC = $conn->prepare("INSERT INTO propiedad_contacto (propiedad_id, email, telefono, whatsapp) VALUES (?,?,?,?)");
            $total = max(count($emails), count($telefonos), count($whatsapps));
            for ($i = 0; $i < $total; $i++) {
                $email    = trim($emails[$i] ?? '');
                $telefono = trim($telefonos[$i] ?? '');
                $whatsapp = trim($whatsapps[$i] ?? '');
                if ($email === '' && $telefono === '' && $whatsapp === '') continue;
                $stmtC->bind_param("isss", $id, $email, $telefono, $whatsapp);
                if (!$stmtC->execute()) throw new Exception("Error contacto: " . $stmtC->error);
            }
            $stmtC->close();
        }

        $conn->commit();

        // Borrar físicamente los archivos SOLO tras commit exitoso
        foreach ($rutasAEliminarFisico as $ruta) {
            if (file_exists($ruta)) @unlink($ruta);
        }

        echo json_encode(["actualizado" => true, "id" => $id]);

    } catch (Exception $e) {
        $conn->rollback();
        // Revertir archivos nuevos que se alcanzaron a guardar
        foreach ($rutasGuardadas as $r) {
            if (file_exists($r)) @unlink($r);
        }
        echo json_encode(["error" => $e->getMessage()]);
    }
}

/* =========================================================
   DELETE - ELIMINAR PROPIEDAD (con archivos físicos)
   ========================================================= */
function eliminarPropiedad($conn) {
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        echo json_encode(["error" => "El parámetro 'id' es obligatorio"]);
        return;
    }

    $id = intval($_GET['id']);

    $chk = $conn->prepare("SELECT id FROM propiedad WHERE id = ?");
    $chk->bind_param("i", $id);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        echo json_encode(["error" => "Propiedad no encontrada"]);
        return;
    }
    $chk->close();

    $conn->begin_transaction();

    try {
        $rutas = [];

        $stmtF = $conn->prepare("SELECT ruta_archivo FROM propiedad_fotografia WHERE propiedad_id = ?");
        $stmtF->bind_param("i", $id);
        $stmtF->execute();
        $resF = $stmtF->get_result();
        while ($r = $resF->fetch_assoc()) $rutas[] = __DIR__ . "/" . $r['ruta_archivo'];
        $stmtF->close();

        $stmtP = $conn->prepare("SELECT ruta_archivo FROM propiedad_plano WHERE propiedad_id = ?");
        $stmtP->bind_param("i", $id);
        $stmtP->execute();
        $resP = $stmtP->get_result();
        while ($r = $resP->fetch_assoc()) $rutas[] = __DIR__ . "/" . $r['ruta_archivo'];
        $stmtP->close();

        $conn->query("DELETE FROM propiedad_fotografia WHERE propiedad_id = {$id}");
        $conn->query("DELETE FROM propiedad_plano       WHERE propiedad_id = {$id}");
        $conn->query("DELETE FROM propiedad_contacto    WHERE propiedad_id = {$id}");

        $stmt = $conn->prepare("DELETE FROM propiedad WHERE id = ?");
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) throw new Exception("Error al eliminar propiedad: " . $stmt->error);
        $stmt->close();

        foreach ($rutas as $ruta) {
            if (file_exists($ruta)) @unlink($ruta);
        }

        $conn->commit();
        echo json_encode(["eliminado" => true]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["error" => $e->getMessage()]);
    }
}
?>