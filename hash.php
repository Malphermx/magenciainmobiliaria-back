<?php
// Contraseña a hashear
$password = "admin2026!";

// Generar hash con bcrypt
$hash = password_hash($password, PASSWORD_BCRYPT);

// Verificar que el hash se generó correctamente
if ($hash === false) {
    die("Error al generar el hash.");
}

echo "Hash generado: " . $hash . PHP_EOL;



?>