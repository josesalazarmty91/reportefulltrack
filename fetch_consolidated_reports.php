<?php
// Desactivar errores en la salida final y establecer cabecera JSON
ini_set('display_errors', 0);
header('Content-Type: application/json');

$response = [];

try {
    // 1. REQUERIR LA CONFIGURACIÓN DE BD EXISTENTE (Se conectará a 'grupoam6_repfull')
    $configFile = __DIR__ . '/db_config.php';

    if (!file_exists($configFile) || !is_readable($configFile)) {
        throw new Exception("El archivo 'db_config.php' no existe o no se pudo leer.", 1);
    }
    
    require $configFile;

    // Verificar que las variables de configuración base se cargaron
    if (!isset($DB_HOST) || !isset($DB_USER) || !isset($DB_PASS) || !isset($DB_NAME)) {
        throw new Exception("Las variables de configuración de la base de datos no están definidas en db_config.php.", 2);
    }

    // 2. CREAR LA CONEXIÓN (a 'grupoam6_repfull')
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

    // Verificar conexión
    if ($conn->connect_error) {
        throw new Exception("Error de conexión a la BD 'grupoam6_repfull': " . $conn->connect_error, 3);
    }
    $conn->set_charset('utf8mb4');

    // 3. LÓGICA DE FILTROS (Aplicados a la tabla principal 'trip_reports')
    $month = $_GET['month'] ?? null; // ej. '2025-11'
    $unit = $_GET['unit'] ?? null;

    $whereClauses = [];
    
    // Filtro de Mes (para trip_reports)
    if (!empty($month)) {
        $whereClauses[] = "DATE_FORMAT(STR_TO_DATE(t1.report_date, '%m/%d/%Y'), '%Y-%m') = '{$conn->real_escape_string($month)}'";
    }
    // Filtro de Unidad (para trip_reports)
    if (!empty($unit)) {
        $whereClauses[] = "t1.unit_number LIKE '%{$conn->real_escape_string($unit)}%'";
    }

    // 4. LA GRAN CONSULTA "CROSS-DATABASE"
    // Usamos t1 para 'trip_reports' (Reporte Diesel)
    // Usamos t2 para 'registros_entrada' (Reporte Tableta)
    
    $sqlBase = "
        SELECT 
            -- Columna de conciliación
            CASE WHEN t2.id IS NOT NULL THEN 1 ELSE 0 END as conciliado,

            -- Todos los campos de t1 (Reporte Diesel)
            t1.id as t1_id,
            t1.file_name,
            t1.unit_number,
            
            CASE 
                WHEN t1.report_date IS NULL OR t1.report_date = 'N/D' OR t1.report_date = '' THEN 'N/D'
                ELSE DATE_FORMAT(STR_TO_DATE(t1.report_date, '%m/%d/%Y'), '%d/%m/%Y') 
            END AS t1_report_date,
            
            t1.report_time as t1_report_time,
            t1.km_recorrido,
            t1.distancia_conducida,
            t1.distancia_top_gear,
            t1.distancia_cambio_bajo,
            t1.combustible_viaje,
            t1.combustible_manejando,
            t1.combustible_ralenti,
            t1.def_usado,
            t1.tiempo_viaje,
            t1.tiempo_manejando,
            t1.tiempo_ralenti,
            t1.tiempo_top_gear,
            t1.tiempo_crucero,
            t1.tiempo_exceso_velocidad,
            t1.velocidad_maxima,
            t1.rpm_maxima,
            t1.velocidad_promedio,
            t1.rendimiento_viaje,
            t1.rendimiento_manejando,
            t1.factor_carga,
            t1.eventos_exceso_velocidad,
            t1.eventos_frenado,
            t1.tiempo_neutro_coasting,
            t1.tiempo_pto,
            t1.combustible_pto,

            -- Todos los campos de t2 (Reporte Tableta)
            t2.id as t2_id,
            IFNULL(c.name, 'N/D') as t2_company_name,
            -- CORRECCIÓN: Cambiar 'u.unit_number' por 't2.unit_number'
            IFNULL(t2.unit_number, 'N/D') as t2_unit_number,
            DATE_FORMAT(t2.timestamp, '%d/%m/%Y %H:%i:%s') as t2_timestamp,
            IFNULL(o.name, 'N/D') as t2_operator_name,
            t2.bitacora_number,
            t2.km_inicio,
            t2.km_fin,
            t2.km_recorridos,
            t2.litros_diesel,
            t2.litros_urea,
            t2.litros_totalizador
            
        FROM 
            trip_reports as t1

        -- Usamos un subquery para unir 'registros_entrada' con 'units' ANTES de hacer el JOIN principal
        -- Esto nos permite obtener el 'unit_number' de la tableta para la condición de cruce.
        LEFT JOIN (
            SELECT 
                r_sub.*,
                u_sub.unit_number
            FROM 
                grupoam6_diesel.registros_entrada as r_sub
            LEFT JOIN 
                grupoam6_diesel.units as u_sub ON r_sub.unit_id = u_sub.id
        ) as t2 ON 
            -- Condición 1: El número de unidad debe ser el mismo (CORRECCIÓN: Añadir COLLATE)
            t1.unit_number = t2.unit_number COLLATE utf8mb4_unicode_ci
            AND 
            -- Condición 2: La fecha (ignorando la hora) debe ser la misma
            STR_TO_DATE(t1.report_date, '%m/%d/%Y') = CAST(t2.timestamp AS DATE)

        -- Joins adicionales para los datos de la tableta (compañía, operador)
        LEFT JOIN 
            grupoam6_diesel.companies as c ON t2.company_id = c.id
        LEFT JOIN 
            grupoam6_diesel.operators as o ON t2.operator_id = o.id
    ";

    $sqlOrder = " ORDER BY t1.id DESC ";

    // Construir la consulta final con los filtros
    if (!empty($whereClauses)) {
        $sql = $sqlBase . " WHERE " . implode(" AND ", $whereClauses) . $sqlOrder;
    } else {
        $sql = $sqlBase . $sqlOrder;
    }

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception("Error en la consulta SQL (Consolidado): " . $conn->error, 4);
    }

    $reports = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $reports[] = $row;
        }
    }
    
    echo json_encode($reports);

    $conn->close();

} catch (Exception $e) {
    http_response_code(500); 
    $response['status'] = 'error';
    $response['message'] = 'Error del servidor PHP: ' . $e->getMessage();
    $response['file'] = $e->getFile();
    $response['line'] = $e->getLine();
    echo json_encode($response);
}
?>