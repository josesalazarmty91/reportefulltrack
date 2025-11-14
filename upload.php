<?php
// Desactivar errores en la salida final y establecer cabecera JSON
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

$response = [];

try {
    // --- 1. CONFIGURACIÓN Y CONEXIÓN A BD ---

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

    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($conn->connect_error) {
        throw new Exception("Error de conexión a la BD: " . $conn->connect_error, 3);
    }
    $conn->set_charset('utf8mb4');

    // --- 2. VALIDACIÓN DEL ARCHIVO SUBIDO ---

    if (!isset($_FILES['xmlFile']) || $_FILES['xmlFile']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir el archivo. Código: ' . $_FILES['xmlFile']['error']);
    }

    $xmlFilePath = $_FILES['xmlFile']['tmp_name'];
    $fileName = basename($_FILES['xmlFile']['name']);

    // Cargar el contenido del XML
    $xmlContent = file_get_contents($xmlFilePath);
    if ($xmlContent === false) {
        throw new Exception('No se pudo leer el archivo XML.');
    }

    // Desactivar errores de libxml para manejarlos manualmente
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        throw new Exception('XML mal formado. Errores: ' . json_encode($errors));
    }

    // --- 3. EXTRACCIÓN Y PARSEO DE DATOS ---

    $reportData = [];
    $unitNumber = 'N/D';
    $rawDateStr = 'N/D';

    // Mapa de nombres de reporte (del TXT) a nombres de columnas en la BD
    $dbColumnMap = [
        "KM Recorrido" => "km_recorrido",
        "Distancia conducida" => "distancia_conducida",
        "Distancia en top gear" => "distancia_top_gear",
        "Distancia en cambio bajo" => "distancia_cambio_bajo",
        "Combustible del viaje" => "combustible_viaje",
        "Combustible manejando" => "combustible_manejando",
        "Combustible en ralentí" => "combustible_ralenti",
        "DEF usado" => "def_usado",
        "Tiempo del viaje" => "tiempo_viaje",
        "Tiempo manejando" => "tiempo_manejando",
        "Tiempo en ralentí" => "tiempo_ralenti",
        "Tiempo en top gear" => "tiempo_top_gear",
        "Tiempo en crucero" => "tiempo_crucero",
        "Tiempo en exceso de velocidad" => "tiempo_exceso_velocidad",
        "Velocidad máxima" => "velocidad_maxima",
        "RPM máxima" => "rpm_maxima",
        "Velocidad promedio" => "velocidad_promedio",
        "Rendimiento del viaje" => "rendimiento_viaje",
        "Rendimiento manejando" => "rendimiento_manejando",
        "Factor de carga" => "factor_carga",
        "Eventos de exceso de velocidad" => "eventos_exceso_velocidad",
        "Eventos de frenado" => "eventos_frenado",
        "Tiempo en neutro/coasting" => "tiempo_neutro_coasting",
        "Tiempo en PTO" => "tiempo_pto",
        "Combustible PTO" => "combustible_pto",
    ];
    
    // Nombres de las etiquetas en los XML
    $mapping = [
        "KM Recorrido" => ["Cummins" => "Trip Distance", "Detroit" => "Trip Distance"],
        "Distancia conducida" => ["Cummins" => "Drive Distance", "Detroit" => "Drive Distance"],
        "Distancia en top gear" => ["Cummins" => "Top Gear Distance", "Detroit" => "Top Gear Distance"],
        "Distancia en cambio bajo" => ["Cummins" => "Gear Down Distance", "Detroit" => "Top Gear -1 Distance"],
        "Combustible del viaje" => ["Cummins" => "Trip Fuel Used", "Detroit" => "Trip Fuel"],
        "Combustible manejando" => ["Cummins" => "Drive Fuel Used", "Detroit" => "Drive Fuel"],
        "Combustible en ralentí" => ["Cummins" => "Idle Fuel Used", "Detroit" => "Idle Fuel"],
        "DEF usado" => ["Cummins" => "Trip Diesel Exhaust Fluid Used", "Detroit" => "Trip Def H / Def Fuel"],
        "Tiempo del viaje" => ["Cummins" => "Trip Time", "Detroit" => "Trip Time"],
        "Tiempo manejando" => ["Cummins" => "Trip Drive Time", "Detroit" => "Drive Time"],
        "Tiempo en ralentí" => ["Cummins" => "Trip Idle Time", "Detroit" => "Idle Time"],
        "Tiempo en top gear" => ["Cummins" => "Trip Top Gear Time", "Detroit" => "Top Gear Time"],
        "Tiempo en crucero" => ["Cummins" => "Trip Cruise Time", "Detroit" => "Cruise Time"],
        "Tiempo en exceso de velocidad" => ["Cummins" => "Overspeed 1/2 Time", "Detroit" => "Over Speed A/B Time"],
        "Velocidad máxima" => ["Cummins" => "Maximum Vehicle Speed", "Detroit" => "Peak Road Speed"],
        "RPM máxima" => ["Cummins" => "Maximum Engine Speed", "Detroit" => "Peak Engine RPM"],
        "Velocidad promedio" => ["Cummins" => "Average Vehicle Speed", "Detroit" => "Avg Vehicle Speed"],
        "Rendimiento del viaje" => ["Cummins" => "Trip Average Fuel Economy", "Detroit" => "Trip Economy"],
        "Rendimiento manejando" => ["Cummins" => "Drive Average Fuel Economy", "Detroit" => "Driving Economy"],
        "Factor de carga" => ["Cummins" => "Average Engine Load", "Detroit" => "Drive Average Load Factor"],
        "Eventos de exceso de velocidad" => ["Cummins" => "Overspeed Events", "Detroit" => "Over Speed A/B Count"],
        "Eventos de frenado" => ["Cummins" => "Sudden Deceleration Counts", "Detroit" => "Brake Count / Firm brake count"],
        "Tiempo en neutro/coasting" => ["Cummins" => "Coast Time", "Detroit" => "Coast Time"],
        "Tiempo en PTO" => ["Cummins" => "Total PTO Time", "Detroit" => "VSG (PTO) Time"],
        "Combustible PTO" => ["Cummins" => "Total PTO Fuel Used", "Detroit" => "VSG (PTO) Fuel"],
    ];

    // Detectar tipo de archivo
    if (isset($xml->TripInfoParameters)) {
        // --- TIPO CUMMINS ---
        $unitNumber = (string) $xml->DeviceInfo['UnitNumber'];
        $rawDateStr = (string) $xml->DeviceInfo['ReportDate'];

        foreach ($mapping as $nombreReporte => $tags) {
            $tagName = $tags["Cummins"];
            $value = 'N/D';
            
            if ($tagName === "Overspeed 1/2 Time") {
                // Caso especial: Sumar Overspeed 1 y 2
                $time1 = (float) $xml->TripInfoParameters->xpath("//TripInfo[@Name='Overspeed 1 Time']/@Value")[0];
                $time2 = (float) $xml->TripInfoParameters->xpath("//TripInfo[@Name='Overspeed 2 Time']/@Value")[0];
                $value = $time1 + $time2;
            } else {
                $nodes = $xml->TripInfoParameters->xpath("//TripInfo[@Name='{$tagName}']/@Value");
                if (!empty($nodes)) {
                    $value = (string) $nodes[0];
                }
            }
            $dbColumn = $dbColumnMap[$nombreReporte];
            $reportData[$dbColumn] = $value;
        }

    } elseif (isset($xml->DataFile->TripActivity)) {
        // --- TIPO DETROIT ---
        $unitNumber = (string) $xml->DataFile['VehicleID'];
        $rawDateStr = (string) $xml->DataFile['PC_Date'];

        foreach ($mapping as $nombreReporte => $tags) {
            $tagName = $tags["Detroit"];
            $value = 'N/D';

            if ($tagName === "Over Speed A/B Time") {
                $timeA = (float) $xml->DataFile->TripActivity->xpath("//Parameter[@Name='Over Speed A Time']")[0];
                $timeB = (float) $xml->DataFile->TripActivity->xpath("//Parameter[@Name='Over Speed B Time']")[0];
                $value = $timeA + $timeB;
            } elseif (strpos($tagName, " / ") !== false) {
                // Caso especial: "Brake Count / Firm brake count"
                $parts = explode(" / ", $tagName);
                $nodes1 = $xml->DataFile->TripActivity->xpath("//Parameter[@Name='{$parts[0]}']");
                $nodes2 = $xml->DataFile->TripActivity->xpath("//Parameter[@Name='{$parts[1]}']");
                $val1 = !empty($nodes1) ? (int)$nodes1[0] : 0;
                $val2 = !empty($nodes2) ? (int)$nodes2[0] : 0;
                $value = $val1 + $val2;
            } elseif ($tagName === "Trip Def H / Def Fuel") {
                $nodes1 = $xml->DataFile->TripActivity->xpath("//Parameter[@Name='Trip Def H']");
                $nodes2 = $xml->DataFile->TripActivity->xpath("//Parameter[@Name='Def Fuel']"); // Asumiendo este nombre
                $val1 = !empty($nodes1) ? (float)$nodes1[0] : 0;
                $val2 = !empty($nodes2) ? (float)$nodes2[0] : 0;
                $value = $val1 + $val2;
            } else {
                $nodes = $xml->DataFile->TripActivity->xpath("//Parameter[@Name='{$tagName}']");
                if (!empty($nodes)) {
                    $value = (string) $nodes[0];
                }
            }
            $dbColumn = $dbColumnMap[$nombreReporte];
            $reportData[$dbColumn] = $value;
        }
    } else {
        throw new Exception('Formato de XML no reconocido.');
    }

    // --- 4. LIMPIEZA DE DATOS (UNIDAD Y FECHA/HORA) ---

    // Limpiar número de unidad
    $cleanedUnitNumber = str_replace('#', '', $unitNumber);
    
    // Función para parsear fecha y hora (Detroit y Cummins)
    function parseDateTime($dateString) {
        $date = 'N/D';
        $time = 'N/D';
        
        // Limpiar espacios extraños
        $dateString = trim($dateString);
        // Reemplazar múltiples espacios por uno solo
        $dateString = preg_replace('/\s+/', ' ', $dateString);
        
        // Reemplazar 'a. m.' y 'p. m.' por 'AM' y 'PM' para parseo
        $dateStringAmPm = str_replace(['a. m.', 'p. m.'], ['AM', 'PM'], $dateString);

        // --- INICIO DE CORRECCIÓN ---
        // Definir los formatos a intentar, priorizando el formato original
        $formatsToTry = [
            // Formatos m/d/Y (Americano - Original)
            'm/d/Y H:i:s',     // Detroit (24h)
            'm/d/Y h:i:s A',   // Cummins (12h)
            
            // Formatos d/m/Y (Latino/Europeo - Nuevo)
            'd/m/Y H:i:s',     // Detroit (24h)
            'd/m/Y h:i:s A'    // Cummins (12h)
        ];

        $dateTime = false; // Variable para guardar el objeto DateTime

        foreach ($formatsToTry as $format) {
            // Usar la cadena con AM/PM si el formato lo requiere
            $stringToParse = (strpos($format, 'A') !== false) ? $dateStringAmPm : $dateString;
            
            $dateTime = DateTime::createFromFormat($format, $stringToParse);
            
            if ($dateTime) {
                // Si el formato funcionó, salir del bucle
                break;
            }
        }

        // Si $dateTime no es falso, encontramos una fecha válida
        if ($dateTime) {
            // Éxito: Guardar SIEMPRE en formato Americano (m/d/Y)
            // Esto asegura que fetch_reports.php pueda leerlo.
            $date = $dateTime->format('m/d/Y'); 
            $time = $dateTime->format('H:i:s'); // Convertir siempre a 24h
        }
        // Si $dateTime sigue siendo false, la función devolverá los valores por defecto 'N/D'
        // --- FIN DE CORRECCIÓN ---
        
        return ['date' => $date, 'time' => $time];
    }
    
    $parsedDateTime = parseDateTime($rawDateStr);
    $reportData['report_date'] = $parsedDateTime['date'];
    $reportData['report_time'] = $parsedDateTime['time'];

    // --- 5. INSERCIÓN EN BASE DE DATOS ---

    // Añadir los campos que faltan
    $reportData['file_name'] = $fileName;
    $reportData['unit_number'] = $cleanedUnitNumber;

    // Construir la consulta de inserción
    $columns = implode(", ", array_keys($reportData));
    
    // Crear los placeholders (?)
    $placeholders = implode(", ", array_fill(0, count($reportData), "?"));
    
    // Obtener los tipos de datos (s = string)
    $types = str_repeat("s", count($reportData));
    
    // Obtener los valores
    $values = array_values($reportData);

    $sql = "INSERT INTO trip_reports ($columns) VALUES ($placeholders)";
    
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Error al preparar la consulta: " . $conn->error, 5);
    }
    
    // Vincular parámetros
    $stmt->bind_param($types, ...$values);
    
    // Ejecutar la consulta
    if (!$stmt->execute()) {
        throw new Exception("Error al ejecutar la consulta: " . $stmt->error, 6);
    }

    $stmt->close();
    $conn->close();

    // --- 6. RESPUESTA DE ÉXITO ---
    $response['status'] = 'success';
    $response['message'] = 'Archivo "' . htmlspecialchars($fileName) . '" procesado y guardado correctamente.';
    echo json_encode($response);

} catch (Exception $e) {
    // --- MANEJO DE ERRORES ---
    http_response_code(500); // Internal Server Error
    $response['status'] = 'error';
    $response['message'] = 'Error del servidor PHP: ' . $e->getMessage();
    $response['file'] = $e->getFile();
    $response['line'] = $e->getLine();
    echo json_encode($response);
}
?>