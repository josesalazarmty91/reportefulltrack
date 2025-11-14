<?php
// Activar el reporte de errores para depuración (solo al inicio)
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// Desactivar errores en la salida final y establecer cabecera JSON
ini_set('display_errors', 0);
header('Content-Type: application/json');

$response = [];

try {
    // Usar __DIR__ para asegurar que la ruta al config es correcta
    // Asume que db_config.php está EN LA MISMA CARPETA que este archivo.
    $configFile = __DIR__ . '/db_config.php';

    if (!file_exists($configFile) || !is_readable($configFile)) {
        throw new Exception("El archivo 'db_config.php' no existe, no se pudo leer o no está configurado.", 1);
    }
    
    require $configFile;

    // Verificar que las variables de configuración realmente se cargaron
    if (!isset($DB_HOST) || !isset($DB_USER) || !isset($DB_PASS) || !isset($DB_NAME)) {
        throw new Exception("Las variables de configuración de la base de datos no están definidas en db_config.php.", 2);
    }

    // Crear conexión
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

    // Verificar conexión
    if ($conn->connect_error) {
        throw new Exception("Error de conexión a la BD: " . $conn->connect_error, 3);
    }

    // Establecer charset
    $conn->set_charset('utf8mb4');

    // Consulta SQL para obtener los reportes
    // *** CORRECCIÓN DE FECHA AÑADIDA AQUÍ ***
    // 1. STR_TO_DATE convierte tu texto (ej. '10/27/2025') a una fecha real de MySQL.
    // 2. DATE_FORMAT le da el formato de salida (ej. '27/10/2025').
    $sql = "
        SELECT 
            id,
            file_name,
            unit_number,
            
            -- Manejar fechas almacenadas como VARCHAR 'm/d/Y' y formatearlas a 'd/m/Y'
            CASE 
                WHEN report_date IS NULL OR report_date = 'N/D' OR report_date = '' THEN 'N/D'
                ELSE DATE_FORMAT(STR_TO_DATE(report_date, '%m/%d/%Y'), '%d/%m/%Y') 
            END AS report_date,
            
            report_time,
            km_recorrido,
            distancia_conducida,
            distancia_top_gear,
            distancia_cambio_bajo,
            combustible_viaje,
            combustible_manejando,
            combustible_ralenti,
            def_usado,
            tiempo_viaje,
            tiempo_manejando,
            tiempo_ralenti,
            tiempo_top_gear,
            tiempo_crucero,
            tiempo_exceso_velocidad,
            velocidad_maxima,
            rpm_maxima,
            velocidad_promedio,
            rendimiento_viaje,
            rendimiento_manejando,
            factor_carga,
            eventos_exceso_velocidad,
            eventos_frenado,
            tiempo_neutro_coasting,
            tiempo_pto,
            combustible_pto
        FROM 
            trip_reports
        ORDER BY 
            id ASC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        // Error en la consulta SQL
        throw new Exception("Error en la consulta SQL: " . $conn->error, 4);
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
    $response['message'] = 'Error del servidor PHP: ' . $e->getMessage();
    $response['file'] = $e->getFile();
    $response['line'] = $e->getLine();
    echo json_encode($response);
}
?>