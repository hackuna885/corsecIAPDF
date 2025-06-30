<?php
// 1. Configuración inicial
// Desactivar la visualización de errores en producción y activar el registro
ini_set('display_errors', 0); // No mostrar errores en la salida
ini_set('log_errors', 1); // Registrar errores en el archivo de log
ini_set('error_log', __DIR__ . '/php_errors.log'); // Ruta al archivo de log de errores (ajusta según tu servidor)
error_reporting(E_ALL); // Reportar todos los errores (pero no mostrarlos al cliente)

header("Content-Type: application/json; Charset=UTF-8");
date_default_timezone_set('America/Mexico_City'); // Ajusta a tu zona horaria si es diferente

// Incluir el archivo de configuración con la clave API
if (file_exists('config.php')) {
    include 'config.php';
    if (!isset($apiKey) || empty($apiKey) || $apiKey === 'TU_CLAVE_API_DE_GEMINI_AQUI') {
        http_response_code(500);
        echo json_encode(['error' => 'Error de configuración: La clave API de Gemini no está definida o es la predeterminada.']);
        error_log("Error: La clave API de Gemini no está configurada en config.php.");
        exit();
    }
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Error de configuración: El archivo "config.php" no se encuentra.']);
    error_log("Error: El archivo de configuración 'config.php' no se encontró.");
    exit();
}

// Define la URL de la API de Gemini (usando la clave de config.php)
// Usamos gemini-1.5-flash-latest por ser más reciente y con mejor soporte para documentos.
// Si tienes problemas, puedes volver a gemini-2.5-flash.
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=' . $apiKey;

// 2. Validación y Sanitización de Entradas
// Ahora esperamos 'documento' en lugar de 'imagen'
if (!isset($_POST['consulta']) || !isset($_FILES['documento'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Se requieren la consulta y un documento (imagen o PDF).']);
    exit;
}

// Sanitizar la consulta de texto
$consulta = htmlspecialchars(trim($_POST['consulta']), ENT_QUOTES, 'UTF-8');

// Validar la subida del documento
if ($_FILES['documento']['error'] !== UPLOAD_ERR_OK) {
    $errorMessage = 'Error desconocido al subir el documento.';
    switch ($_FILES['documento']['error']) {
        case UPLOAD_ERR_INI_SIZE:
            $errorMessage = 'El archivo subido excede la directiva upload_max_filesize en php.ini.';
            break;
        case UPLOAD_ERR_FORM_SIZE:
            $errorMessage = 'El archivo subido excede la directiva MAX_FILE_SIZE que se especificó en el formulario HTML.';
            break;
        case UPLOAD_ERR_PARTIAL:
            $errorMessage = 'El archivo se subió solo parcialmente.';
            break;
        case UPLOAD_ERR_NO_FILE:
            $errorMessage = 'No se seleccionó ningún archivo.';
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            $errorMessage = 'Falta una carpeta temporal en el servidor.';
            break;
        case UPLOAD_ERR_CANT_WRITE:
            $errorMessage = 'No se pudo escribir el archivo en el disco.';
            break;
        case UPLOAD_ERR_EXTENSION:
            $errorMessage = 'Una extensión de PHP detuvo la carga del archivo.';
            break;
    }
    http_response_code(500);
    echo json_encode(['error' => 'Error al subir el documento: ' . $errorMessage]);
    error_log("Error de subida de archivo: " . $errorMessage . " (Código: " . $_FILES['documento']['error'] . ")");
    exit;
}

// Verificar el tipo de archivo (MIME type) por seguridad
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $finfo->file($_FILES['documento']['tmp_name']);

// Solo se aceptan JPEG y PDF ahora
if ($mime_type !== 'image/jpeg' && $mime_type !== 'application/pdf') {
    http_response_code(400);
    echo json_encode(['error' => 'Tipo de archivo no permitido. Solo se aceptan imágenes JPG/JPEG y documentos PDF.']);
    error_log("Intento de subida de archivo con tipo MIME no permitido: " . $mime_type);
    exit;
}

// 3. Manejo del Archivo Temporal
$documentoTemporal = null; // Inicializar a null

try {
    // Crear un nombre de archivo temporal único en el directorio temporal del sistema
    $documentoTemporal = tempnam(sys_get_temp_dir(), 'gemini_doc_');
    if ($documentoTemporal === false) {
        throw new Exception('No se pudo crear el archivo temporal.');
    }

    // Mover el archivo subido desde su ubicación temporal a nuestra ubicación temporal controlada
    if (!move_uploaded_file($_FILES['documento']['tmp_name'], $documentoTemporal)) {
        throw new Exception('No se pudo mover el archivo subido al directorio temporal.');
    }

    // Leer el contenido del archivo temporal y codificarlo en Base64
    $documentData = base64_encode(file_get_contents($documentoTemporal));
    if ($documentData === false) {
        throw new Exception('No se pudo leer el contenido del documento para codificar.');
    }

} catch (Exception $e) {
    // Limpiar el archivo temporal si se creó pero hubo un error
    if ($documentoTemporal && file_exists($documentoTemporal)) {
        unlink($documentoTemporal);
    }
    http_response_code(500);
    echo json_encode(['error' => 'Error interno al procesar el documento. Por favor, inténtelo de nuevo.']);
    error_log("Error en el manejo del archivo temporal: " . $e->getMessage());
    exit;
}


// 4. Preparar los Datos de Solicitud para la API de Gemini
$datos = [
    'contents' => [
        [
            'parts' => [
                [
                    'text' => $consulta // La consulta de texto del usuario
                ],
                [
                    'inline_data' => [
                        'mime_type' => $mime_type, // Usar el tipo MIME detectado real (image/jpeg o application/pdf)
                        'data' => $documentData // El documento codificado en Base64
                    ]
                ]
            ]
        ]
    ]
];

$datosJSON = json_encode($datos);

if ($datosJSON === false) {
    if ($documentoTemporal && file_exists($documentoTemporal)) {
        unlink($documentoTemporal);
    }
    http_response_code(500);
    echo json_encode(['error' => 'Error interno al preparar los datos para la API.']);
    error_log("Error al codificar los datos JSON para la API: " . json_last_error_msg());
    exit;
}

// 5. Configuración y Ejecución de cURL
$curl = curl_init();

if ($curl === false) {
    if ($documentoTemporal && file_exists($documentoTemporal)) {
        unlink($documentoTemporal);
    }
    http_response_code(500);
    echo json_encode(['error' => 'Error interno: No se pudo inicializar cURL.']);
    error_log("Error: No se pudo inicializar cURL.");
    exit;
}

$opciones = array(
    CURLOPT_URL => $url, // La URL de la API de Gemini con tu clave
    CURLOPT_RETURNTRANSFER => true, // Devolver la transferencia como string
    CURLOPT_HEADER => false, // No incluir los encabezados en la salida
    CURLOPT_FOLLOWLOCATION => true, // Seguir cualquier encabezado Location
    CURLOPT_ENCODING => '', // Manejar cualquier codificación de respuesta
    CURLOPT_CUSTOMREQUEST => 'POST', // Establecer el método de solicitud a POST
    CURLOPT_POSTFIELDS => $datosJSON, // Los datos JSON a enviar
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json', // Indicar que el cuerpo es JSON
        'Content-Length: ' . strlen($datosJSON) // Establecer la longitud del contenido
    ),
    CURLOPT_TIMEOUT => 300, // Aumentar tiempo de espera para PDFs grandes (5 minutos)
    CURLOPT_CONNECTTIMEOUT => 10, // Tiempo máximo en segundos para establecer la conexión
    // CURLOPT_VERBOSE => true, // Descomentar para salida detallada de cURL (depuración)
);

curl_setopt_array($curl, $opciones);

$respGemini = curl_exec($curl);
$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curl_error = curl_error($curl);

curl_close($curl); // Cerrar la sesión cURL

// 6. Manejo de Errores de cURL y de la Respuesta de la API
if ($respGemini === false) {
    if ($documentoTemporal && file_exists($documentoTemporal)) {
        unlink($documentoTemporal);
    }
    http_response_code(500);
    echo json_encode(['error' => 'Error de comunicación con la API de Gemini. Por favor, inténtelo de nuevo.']);
    error_log("Error al conectar con la API de Gemini: " . $curl_error);
    exit;
}

// Procesar la respuesta de la API incluso si no es 200 OK
$respuesta = json_decode($respGemini, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    if ($documentoTemporal && file_exists($documentoTemporal)) {
        unlink($documentoTemporal);
    }
    http_response_code(500);
    echo json_encode(['error' => 'Error interno al decodificar la respuesta de la API.']);
    error_log("Error al decodificar la respuesta JSON de la API: " . json_last_error_msg() . " | Respuesta cruda: " . $respGemini);
    exit;
}

if ($http_code !== 200) {
    if ($documentoTemporal && file_exists($documentoTemporal)) {
        unlink($documentoTemporal);
    }
    $apiErrorMessage = "La API de Gemini devolvió un error (HTTP " . $http_code . ").";
    if (isset($respuesta['error']['message'])) {
        $apiErrorMessage .= " Mensaje: " . $respuesta['error']['message'];
    } elseif (isset($respuesta['message'])) { // Algunas APIs usan 'message' directamente
        $apiErrorMessage .= " Mensaje: " . $respuesta['message'];
    }

    http_response_code($http_code);
    echo json_encode(['error' => 'Error de la API de Gemini. Código: ' . $http_code . '.']);
    error_log("Error de la API de Gemini: " . $apiErrorMessage . " | Respuesta completa: " . $respGemini);
    exit;
}

// 7. Extraer y Enviar la Respuesta
// Verificar que la estructura de la respuesta es la esperada
if (isset($respuesta['candidates'][0]['content']['parts'][0]['text'])) {
    $geminiResponseText = $respuesta['candidates'][0]['content']['parts'][0]['text'];
    echo json_encode(['mensaje' => $geminiResponseText]);
} else {
    // Si la respuesta no tiene el formato esperado, registrar el error y enviar un mensaje genérico
    if ($documentoTemporal && file_exists($documentoTemporal)) {
        unlink($documentoTemporal);
    }
    http_response_code(500);
    echo json_encode(['error' => 'La respuesta de Gemini no contiene el formato esperado.']);
    error_log("La respuesta de Gemini no tiene el formato esperado. Respuesta completa: " . print_r($respuesta, true));
}

// 8. Limpiar el Archivo Temporal (muy importante para la seguridad y el espacio en disco)
// Este bloque se ejecutará si el script llega a este punto (ej. en caso de éxito)
if ($documentoTemporal && file_exists($documentoTemporal)) {
    unlink($documentoTemporal);
}

?>