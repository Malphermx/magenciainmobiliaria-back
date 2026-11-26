<?php

use \Firebase\JWT\JWT;
include_once 'config.php';
require_once 'vendor/autoload.php';

// Mostrar errores para depuración (quitar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configurar CORS y tipo de contenido JSON
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header("Content-Type: application/json; charset=utf-8");

// Preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

include "conectar.php";
$mysqli = conectarDB();

if (!$mysqli) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión a la base de datos: ' . mysqli_connect_error()]);
    exit();
}

// Leer JSON
$JSONData   = file_get_contents("php://input");
$dataObject = json_decode($JSONData);

session_start();
$mysqli->set_charset('utf8');

// Validar datos
if (!isset($dataObject->usuario) || !isset($dataObject->clave)) {
    http_response_code(400);
    echo json_encode(['conectado' => false, 'error' => 'Datos incompletos.']);
    exit();
}

$usuario = trim($dataObject->usuario); // en admin_user el "usuario" es el email
$pas     = $dataObject->clave;

// Consulta adaptada a admin_user
$sql = "SELECT id, nombre, email, password_hash, activo
        FROM admin_user
        WHERE email = ?
        LIMIT 1";

if ($stmt = $mysqli->prepare($sql)) {
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows === 1) {
        $datos = $resultado->fetch_assoc();

        if ((int)$datos['activo'] !== 1) {
            echo json_encode(['conectado' => false, 'error' => 'Usuario deshabilitado.']);
            $stmt->close();
            $mysqli->close();
            exit();
        }

        if (password_verify($pas, $datos['password_hash'])) {
            $token = [
                "iss" => $issuer_claim,
                "aud" => $audience_claim,
                "iat" => time(),
                "exp" => time() + $jwt_expiration,
                "data" => [
                    "id"            => $datos['id'],
                    "email"         => $datos['email'],
                    "nombre"        => $datos['nombre'],
                    "idTipoUsuario" => 1, // fijo: admin
                ]
            ];
            $jwt = JWT::encode($token, $secret_key, 'HS256');

            // Actualizar último acceso (opcional pero útil)
            $upd = $mysqli->prepare("UPDATE admin_user SET ultimo_acceso = NOW() WHERE id = ?");
            $upd->bind_param('i', $datos['id']);
            $upd->execute();
            $upd->close();

            $_SESSION['usuario'] = $datos['email'];

            echo json_encode([
                'data' => [
                    'token'                => $jwt,
                    'conectado'            => true,
                    'usuario'              => $datos['email'],
                    'nombre'               => $datos['nombre'],
                    'apellidos'            => '',
                    'id'                   => $datos['id'],
                    'idTipoUsuario'        => 1,
                    'etiquetaTipoUsuario'  => 'Administrador'
                ]
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['conectado' => false, 'error' => 'La clave es incorrecta.']);
        }
    } else {
        http_response_code(401);
        echo json_encode(['conectado' => false, 'error' => 'El usuario no existe.']);
    }

    $stmt->close();
} else {
    http_response_code(500);
    echo json_encode(['conectado' => false, 'error' => 'Error en la consulta: ' . $mysqli->error]);
}

$mysqli->close();
?>