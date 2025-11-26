<?php
// Activar el reporte de errores para depuración (solo al inicio)
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// Desactivar errores en la salida final y establecer cabecera JSON
ini_set('display_errors', 0);
header('Content-Type: application/json');

$response = [];

try {
    // 1. REQUERIR LA CONFIGURACIÓN DE BD EXISTENTE
    $configFile = __DIR__ . '/db_config.php';

    if (!file_exists($configFile) || !is_readable($configFile)) {
        throw new Exception("El archivo 'db_config.php' no existe o no se pudo leer.", 1);
    }
    
    require $configFile;

    // Verificar que las variables de configuración base se cargaron
    if (!isset($DB_HOST) || !isset($DB_USER) || !isset($DB_PASS)) {
        throw new Exception("Las variables de configuración de la base de datos no están definidas en db_config.php.", 2);
    }

    // 2. DEFINIR EL NOMBRE DE LA NUEVA BASE DE DATOS
    $DB_NAME_TABLET = 'grupoam6_diesel';

    // 3. CREAR LA NUEVA CONEXIÓN
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME_TABLET);

    // Verificar conexión
    if ($conn->connect_error) {
        throw new Exception("Error de conexión a la BD 'grupoam6_diesel': " . $conn->connect_error, 3);
    }

    // Establecer charset
    $conn->set_charset('utf8mb4');

    // --- LÓGICA DE FILTROS (MODIFICADA) ---
    $month = $_GET['month'] ?? null; // ej. '2025-11'
    $unit = $_GET['unit'] ?? null;

    $whereClauses = [];

    // El input de fecha HTML envía 'YYYY-MM'.
    // El campo 'timestamp' es DATETIME.
    // Usamos DATE_FORMAT para formatearlo como 'YYYY-MM' y poder comparar.
    
    if (!empty($month)) {
        $whereClauses[] = "DATE_FORMAT(r.timestamp, '%Y-%m') = '{$conn->real_escape_string($month)}'";
    }
    if (!empty($unit)) {
        // Filtramos por la columna 'unit_number' de la tabla 'units' (alias 'u')
        $whereClauses[] = "u.unit_number LIKE '%{$conn->real_escape_string($unit)}%'";
    }
    // --- FIN LÓGICA DE FILTROS ---


    // 4. CONSULTA SQL ACTUALIZADA CON JOINS Y CAMPOS REQUERIDOS
    $sqlBase = "
        SELECT 
            r.id,
            IFNULL(c.name, 'N/D') as company_name,
            IFNULL(u.unit_number, 'N/D') as unit_number,
            DATE_FORMAT(r.timestamp, '%d/%m/%Y %H:%i:%s') as timestamp,
            IFNULL(o.name, 'N/D') as operator_name,
            r.bitacora_number,
            r.km_inicio,
            r.km_fin,
            r.km_recorridos,
            r.litros_diesel,
            r.litros_urea,
            r.litros_totalizador
        FROM 
            registros_entrada as r
        LEFT JOIN 
            companies as c ON r.company_id = c.id
        LEFT JOIN 
            units as u ON r.unit_id = u.id
        LEFT JOIN 
            operators as o ON r.operator_id = o.id
    ";
    
    $sqlOrder = " ORDER BY r.id DESC ";

    // Construir la consulta final con los filtros
    if (!empty($whereClauses)) {
        $sql = $sqlBase . " WHERE " . implode(" AND ", $whereClauses) . $sqlOrder;
    } else {
        $sql = $sqlBase . $sqlOrder;
    }


    $result = $conn->query($sql);

    if (!$result) {
        // Error en la consulta SQL
        throw new Exception("Error en la consulta SQL (registros_entrada con JOINs): " . $conn->error, 4);
    }

    $reports = [];
    if ($result->num_rows > 0) {
        // Obtener datos de cada fila
        while($row = $result->fetch_assoc()) {
            $reports[] = $row;
        }
    }
    
    // Enviar los reportes (incluso si está vacío)
    echo json_encode($reports);

    $conn->close();

} catch (Exception $e) {
    // Si algo falla, enviar un JSON de error claro
    http_response_code(500); // Internal Server Error
    $response['status'] = 'error';
    $response['message'] = 'Error del servidor PHP: ' . $e.getMessage();
    $response['file'] = $e.getFile();
    $response['line'] = $e.getLine();
    echo json_encode($response);
}
?>