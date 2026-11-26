<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Authorization, Content-Type, Access-Control-Allow-Headers, X-Requested-With");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Solo GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido"]);
    exit();
}

include "conectar.php";
$conn = conectarDB();

if ($conn->connect_error) {
    echo json_encode(["error" => "Error de conexión: " . $conn->connect_error]);
    exit();
}

if (isset($_GET['id']) && $_GET['id'] !== '') {
    obtenerPropiedadPublicaPorId($conn, intval($_GET['id']));
} else {
    obtenerPropiedadesPublicas($conn);
}

$conn->close();

/* =========================================================
   LISTAR PROPIEDADES PÚBLICAS
   ========================================================= */
function obtenerPropiedadesPublicas($conn) {
    // Filtros opcionales por query string
    $where  = ["p.estatus = 'publicada'"];
    $params = [];
    $types  = "";

    if (!empty($_GET['operacion']) && in_array($_GET['operacion'], ['venta', 'renta'])) {
        $where[]  = "p.operacion = ?";
        $params[] = $_GET['operacion'];
        $types   .= "s";
    }

    if (!empty($_GET['tipo_propiedad_id'])) {
        $where[]  = "p.tipo_propiedad_id = ?";
        $params[] = intval($_GET['tipo_propiedad_id']);
        $types   .= "i";
    }

    if (!empty($_GET['moneda']) && in_array($_GET['moneda'], ['MXN', 'USD'])) {
        $where[]  = "p.moneda = ?";
        $params[] = $_GET['moneda'];
        $types   .= "s";
    }

    if (!empty($_GET['precio_min']) && is_numeric($_GET['precio_min'])) {
        $where[]  = "(COALESCE(p.precio_venta, p.precio_renta) >= ?)";
        $params[] = $_GET['precio_min'];
        $types   .= "d";
    }

    if (!empty($_GET['precio_max']) && is_numeric($_GET['precio_max'])) {
        $where[]  = "(COALESCE(p.precio_venta, p.precio_renta) <= ?)";
        $params[] = $_GET['precio_max'];
        $types   .= "d";
    }

    if (!empty($_GET['q'])) {
        $where[]  = "(p.titulo LIKE ? OR p.descripcion LIKE ? OR p.direccion LIKE ?)";
        $like     = "%" . $_GET['q'] . "%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types   .= "sss";
    }

    // Paginación
    $limit  = isset($_GET['limit'])  ? max(1, min(50, intval($_GET['limit']))) : 20;
    $offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;

    $whereSql = "WHERE " . implode(" AND ", $where);

    $sql = "
        SELECT
            p.id, p.titulo, p.numero_referencia,
            p.direccion_tipo, p.direccion, p.latitud, p.longitud,
            p.operacion, p.precio_venta, p.precio_renta, p.moneda,
            t.nombre AS tipo_propiedad,
            p.banos, p.medios_banos,
            p.superficie_total, p.superficie_construida, p.superficie_terreno,
            p.plazas_estacionamiento, p.anio_construccion, p.gastos_comunes,
            p.descripcion, p.url_youtube, p.url_tour_virtual,
            p.created_at,
            (SELECT ruta_archivo FROM propiedad_fotografia f
              WHERE f.propiedad_id = p.id
              ORDER BY f.es_principal DESC, f.orden ASC LIMIT 1) AS foto_principal,
            (SELECT COUNT(*) FROM propiedad_fotografia f WHERE f.propiedad_id = p.id) AS total_fotos,
            (SELECT email     FROM propiedad_contacto c WHERE c.propiedad_id = p.id LIMIT 1) AS email_contacto,
            (SELECT telefono  FROM propiedad_contacto c WHERE c.propiedad_id = p.id LIMIT 1) AS telefono_contacto,
            (SELECT whatsapp  FROM propiedad_contacto c WHERE c.propiedad_id = p.id LIMIT 1) AS whatsapp_contacto
        FROM propiedad p
        JOIN tipo_propiedad t ON t.id = p.tipo_propiedad_id
        {$whereSql}
        ORDER BY p.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(["error" => "Error preparando consulta: " . $conn->error]);
            return;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
        if (!$result) {
            echo json_encode(["error" => "Error al obtener propiedades: " . $conn->error]);
            return;
        }
    }

    $propiedades = [];
    while ($row = $result->fetch_assoc()) {
        $propiedades[] = $row;
    }

    // Total (para paginación)
    $countSql = "SELECT COUNT(*) AS total FROM propiedad p {$whereSql}";
    if (!empty($params)) {
        $stmtC = $conn->prepare($countSql);
        $stmtC->bind_param($types, ...$params);
        $stmtC->execute();
        $total = (int)$stmtC->get_result()->fetch_assoc()['total'];
        $stmtC->close();
    } else {
        $total = (int)$conn->query($countSql)->fetch_assoc()['total'];
    }

    echo json_encode([
        "data"   => $propiedades,
        "total"  => $total,
        "limit"  => $limit,
        "offset" => $offset
    ]);

    if (isset($stmt)) $stmt->close();
}

/* =========================================================
   DETALLE PÚBLICO POR ID
   ========================================================= */
function obtenerPropiedadPublicaPorId($conn, $id) {
    $stmt = $conn->prepare("
        SELECT
            p.id, p.titulo, p.numero_referencia,
            p.direccion_tipo, p.direccion, p.latitud, p.longitud,
            p.operacion, p.precio_venta, p.precio_renta, p.moneda,
            t.nombre AS tipo_propiedad,
            p.banos, p.medios_banos,
            p.superficie_total, p.superficie_construida, p.superficie_terreno,
            p.plazas_estacionamiento, p.anio_construccion, p.gastos_comunes,
            p.descripcion, p.url_youtube, p.url_tour_virtual,
            p.created_at
        FROM propiedad p
        JOIN tipo_propiedad t ON t.id = p.tipo_propiedad_id
        WHERE p.id = ? AND p.estatus = 'publicada'
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Propiedad no encontrada o no publicada"]);
        $stmt->close();
        return;
    }

    $propiedad = $res->fetch_assoc();
    $stmt->close();

    // Fotos
    $propiedad['fotos'] = [];
    $stmtF = $conn->prepare("SELECT ruta_archivo, orden, es_principal
                             FROM propiedad_fotografia
                             WHERE propiedad_id = ?
                             ORDER BY es_principal DESC, orden ASC");
    $stmtF->bind_param("i", $id);
    $stmtF->execute();
    $resF = $stmtF->get_result();
    while ($f = $resF->fetch_assoc()) $propiedad['fotos'][] = $f;
    $stmtF->close();

    // Planos
    $propiedad['planos'] = [];
    $stmtP = $conn->prepare("SELECT ruta_archivo, orden
                             FROM propiedad_plano
                             WHERE propiedad_id = ?
                             ORDER BY orden ASC");
    $stmtP->bind_param("i", $id);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    while ($p = $resP->fetch_assoc()) $propiedad['planos'][] = $p;
    $stmtP->close();

    // Contactos (públicos)
    $propiedad['contactos'] = [];
    $stmtC = $conn->prepare("SELECT email, telefono, whatsapp
                             FROM propiedad_contacto
                             WHERE propiedad_id = ?");
    $stmtC->bind_param("i", $id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while ($c = $resC->fetch_assoc()) $propiedad['contactos'][] = $c;
    $stmtC->close();

    echo json_encode(["data" => $propiedad]);
}
?>