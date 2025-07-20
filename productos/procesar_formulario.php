<?php
session_start();

// Detectar si es petición AJAX
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Configurar cabeceras solo si es AJAX
if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
}

// Función para enviar respuesta (JSON para AJAX, redirección para formularios normales)
function enviarRespuesta($success, $message, $data = [], $redirect_url = null) {
    global $is_ajax;
    
    if ($is_ajax) {
        $response = array_merge([
            'success' => $success,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ], $data);
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } else {
        if ($success) {
            $_SESSION['success'] = $message;
        } else {
            $_SESSION['error'] = $message;
        }
        
        if (!$redirect_url) {
            $redirect_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '../dashboard.php';
        }
        
        header("Location: " . $redirect_url);
    }
    exit();
}

// Función para limpiar y validar datos de entrada
function limpiarDato($dato, $tipo = 'string') {
    switch ($tipo) {
        case 'int':
            return filter_var($dato, FILTER_VALIDATE_INT);
        case 'email':
            return filter_var($dato, FILTER_VALIDATE_EMAIL);
        case 'string':
        default:
            return trim(htmlspecialchars($dato, ENT_QUOTES, 'UTF-8'));
    }
}

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    enviarRespuesta(false, 'Sesión expirada. Por favor, inicie sesión nuevamente.', [], '../views/login_form.php');
}

// Verificar que sea una petición POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    enviarRespuesta(false, 'Método no permitido. Use POST.', [], '../dashboard.php');
}

require_once "../config/database.php";

// Verificar conexión a la base de datos
if ($conn->connect_error) {
    http_response_code(500);
    enviarRespuesta(false, 'Error de conexión a la base de datos.', [], '../dashboard.php');
}

// Obtener ID de usuario de la sesión
$usuario_id = $_SESSION['user_id'];
$usuario_rol = $_SESSION['user_role'] ?? 'usuario';
$usuario_almacen_id = $_SESSION['almacen_id'] ?? null;

// Detectar si es entrega a personal
$input = file_get_contents('php://input');
$json_data = null;

if (!empty($input)) {
    $json_data = json_decode($input, true);
    if ($json_data && isset($json_data['tipo_operacion']) && $json_data['tipo_operacion'] === 'entrega_personal') {
        manejarEntregaPersonal($conn, $usuario_id, $usuario_rol, $usuario_almacen_id, $json_data);
        exit();
    }
}

try {
    // Iniciar transacción
    $conn->begin_transaction();
    
    // Procesamiento de transferencia normal
    
    // Obtener y validar datos del formulario
    $datos = [
        'producto_id' => limpiarDato($_POST['producto_id'] ?? '', 'int'),
        'almacen_origen' => limpiarDato($_POST['almacen_origen'] ?? '', 'int'),
        'almacen_destino' => limpiarDato($_POST['almacen_destino'] ?? '', 'int'),
        'cantidad' => limpiarDato($_POST['cantidad'] ?? '', 'int')
    ];
    
    // Validaciones básicas
    if (!$datos['producto_id'] || $datos['producto_id'] <= 0) {
        http_response_code(400);
        enviarRespuesta(false, 'ID de producto inválido.');
    }
    
    if (!$datos['almacen_origen'] || $datos['almacen_origen'] <= 0) {
        http_response_code(400);
        enviarRespuesta(false, 'Almacén de origen inválido.');
    }
    
    if (!$datos['almacen_destino'] || $datos['almacen_destino'] <= 0) {
        http_response_code(400);
        enviarRespuesta(false, 'Almacén de destino inválido.');
    }
    
    if (!$datos['cantidad'] || $datos['cantidad'] <= 0) {
        http_response_code(400);
        enviarRespuesta(false, 'Cantidad inválida.');
    }
    
    if ($datos['almacen_origen'] === $datos['almacen_destino']) {
        http_response_code(400);
        enviarRespuesta(false, 'El almacén de origen y destino no pueden ser el mismo.');
    }
    
    // Verificar que el producto existe en el almacén de origen
    $sql_producto = "SELECT p.*, a.nombre as almacen_nombre, c.nombre as categoria_nombre 
                     FROM productos p 
                     JOIN almacenes a ON p.almacen_id = a.id 
                     JOIN categorias c ON p.categoria_id = c.id 
                     WHERE p.id = ? AND p.almacen_id = ?";
    $stmt = $conn->prepare($sql_producto);
    
    if (!$stmt) {
        throw new Exception("Error preparando consulta de producto: " . $conn->error);
    }
    
    $stmt->bind_param("ii", $datos['producto_id'], $datos['almacen_origen']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->rollback();
        http_response_code(404);
        enviarRespuesta(false, 'Producto no encontrado en el almacén de origen.');
    }

    $producto = $result->fetch_assoc();
    $cantidad_actual = (int)$producto['cantidad'];
    $stmt->close();

    // Verificar stock suficiente
    if ($datos['cantidad'] > $cantidad_actual) {
        $conn->rollback();
        http_response_code(400);
        enviarRespuesta(false, "Stock insuficiente. Disponible: {$cantidad_actual} unidades, solicitado: {$datos['cantidad']} unidades.");
    }
    
    // Verificar que el almacén de destino existe
    $sql_almacen_destino = "SELECT id, nombre FROM almacenes WHERE id = ?";
    $stmt = $conn->prepare($sql_almacen_destino);
    
    if (!$stmt) {
        throw new Exception("Error preparando consulta de almacén destino: " . $conn->error);
    }
    
    $stmt->bind_param("i", $datos['almacen_destino']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->rollback();
        http_response_code(404);
        enviarRespuesta(false, 'Almacén de destino no encontrado.');
    }
    
    $almacen_destino_info = $result->fetch_assoc();
    $stmt->close();
    
    // Crear solicitud de transferencia (SIEMPRE pendiente)
    $observaciones = "Transferencia solicitada desde el sistema web";
    
    // Verificar si existe la columna observaciones
    $check_obs = $conn->query("SHOW COLUMNS FROM solicitudes_transferencia LIKE 'observaciones'");
    $has_observaciones = $check_obs->num_rows > 0;
    
    if ($has_observaciones) {
        $sql_solicitud = "INSERT INTO solicitudes_transferencia 
                          (producto_id, almacen_origen, almacen_destino, cantidad, fecha_solicitud, estado, usuario_id, observaciones) 
                          VALUES (?, ?, ?, ?, NOW(), 'pendiente', ?, ?)";
        $stmt = $conn->prepare($sql_solicitud);
        $stmt->bind_param("iiiiss", 
            $datos['producto_id'], 
            $datos['almacen_origen'], 
            $datos['almacen_destino'], 
            $datos['cantidad'], 
            $usuario_id, 
            $observaciones
        );
    } else {
        $sql_solicitud = "INSERT INTO solicitudes_transferencia 
                          (producto_id, almacen_origen, almacen_destino, cantidad, fecha_solicitud, estado, usuario_id) 
                          VALUES (?, ?, ?, ?, NOW(), 'pendiente', ?)";
        $stmt = $conn->prepare($sql_solicitud);
        $stmt->bind_param("iiiii", 
            $datos['producto_id'], 
            $datos['almacen_origen'], 
            $datos['almacen_destino'], 
            $datos['cantidad'], 
            $usuario_id
        );
    }
    
    if (!$stmt) {
        throw new Exception("Error preparando consulta de solicitud: " . $conn->error);
    }
    
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->rollback();
        enviarRespuesta(false, 'Error al crear la solicitud de transferencia: ' . $stmt->error);
    }
    
    $solicitud_id = $conn->insert_id;
    $stmt->close();
    
    // Registrar en log de actividad (verificar si existe la tabla)
    try {
        $tables_result = $conn->query("SHOW TABLES LIKE 'logs_actividad'");
        if ($tables_result->num_rows > 0) {
            $sql_log = "INSERT INTO logs_actividad (usuario_id, accion, detalle, fecha_accion) 
                        VALUES (?, 'SOLICITAR_TRANSFERENCIA', ?, NOW())";
            $stmt_log = $conn->prepare($sql_log);
            
            if ($stmt_log) {
                $detalle = "Solicitó transferencia de {$datos['cantidad']} unidades de '{$producto['nombre']}' desde {$producto['almacen_nombre']} hacia {$almacen_destino_info['nombre']}";
                $stmt_log->bind_param("is", $usuario_id, $detalle);
                $stmt_log->execute();
                $stmt_log->close();
            }
        }
    } catch (Exception $e) {
        // No es crítico si falla el log
        error_log("Error al registrar log de actividad (no crítico): " . $e->getMessage());
    }
    
    // Confirmar transacción (solicitud pendiente)
    $conn->commit();
    
    // Determinar URL de redirección
    $redirect_url = '../notificaciones/pendientes.php';
    
    // Si venimos de ver-producto.php, volver ahí
    if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'ver-producto.php') !== false) {
        $redirect_url = $_SERVER['HTTP_REFERER'];
    }
    
    // Respuesta exitosa
    enviarRespuesta(true, "✅ Solicitud de transferencia enviada correctamente. La solicitud está pendiente de aprobación.", [
        'solicitud_id' => $solicitud_id,
        'estado' => 'pendiente',
        'almacen_destino' => $almacen_destino_info['nombre'],
        'cantidad_solicitada' => $datos['cantidad'],
        'producto_nombre' => $producto['nombre'],
        'mensaje_proceso' => 'La solicitud está pendiente de aprobación por el almacén destino.',
        'siguiente_paso' => 'Puedes ver el estado en "Notificaciones > Solicitudes Pendientes"'
    ], $redirect_url);
    
} catch (mysqli_sql_exception $e) {
    // Rollback en caso de error de base de datos
    if (isset($conn)) {
        $conn->rollback();
    }
    
    // Log del error para debugging
    error_log("Error de base de datos en procesar_formulario.php: " . $e->getMessage());
    
    // Manejar errores específicos de foreign key
    if (strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
        http_response_code(500);
        enviarRespuesta(false, 'Error de integridad de datos. Por favor, contacte al administrador del sistema.');
    } else {
        http_response_code(500);
        enviarRespuesta(false, 'Error de base de datos. Por favor, inténtelo más tarde.');
    }
    
} catch (Exception $e) {
    // Rollback en caso de cualquier otro error
    if (isset($conn)) {
        $conn->rollback();
    }
    
    // Log del error
    error_log("Error general en procesar_formulario.php: " . $e->getMessage() . " | Usuario: " . ($_SESSION["user_id"] ?? 'desconocido'));
    
    http_response_code(500);
    enviarRespuesta(false, 'Error inesperado. Por favor, inténtelo más tarde.');
    
} finally {
    // Cerrar conexión si existe
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

// ========================================
// FUNCIÓN CORREGIDA PARA MANEJAR ENTREGAS A PERSONAL
// ========================================
function manejarEntregaPersonal($conn, $usuario_id, $usuario_rol, $usuario_almacen_id, $data) {
    try {
        // Validar datos requeridos
        if (empty($data['destinatario_nombre']) || empty($data['destinatario_dni']) || empty($data['productos'])) {
            enviarRespuesta(false, 'Faltan datos requeridos para la entrega');
        }
        
        $destinatario_nombre = trim($data['destinatario_nombre']);
        $destinatario_dni = trim($data['destinatario_dni']);
        $productos = $data['productos'];
        
        // Validaciones
        if (strlen($destinatario_nombre) < 3) {
            enviarRespuesta(false, 'El nombre del destinatario debe tener al menos 3 caracteres');
        }
        
        if (!preg_match('/^[0-9]{8}$/', $destinatario_dni)) {
            enviarRespuesta(false, 'El DNI debe tener exactamente 8 dígitos');
        }
        
        if (empty($productos) || !is_array($productos)) {
            enviarRespuesta(false, 'No se han seleccionado productos');
        }
        
        // Iniciar transacción
        $conn->begin_transaction();
        
        // ✅ CORRECCIÓN: Determinar almacén correctamente según rol
        $almacen_trabajo = null;
        
        if ($usuario_rol === 'admin') {
            // Los admins pueden trabajar con cualquier almacén
            // Obtenemos el almacén del primer producto para determinar el contexto
            $primer_producto_id = (int)$productos[0]['id'];
            
            $sql_almacen_producto = "SELECT p.almacen_id, a.nombre as almacen_nombre 
                                   FROM productos p 
                                   JOIN almacenes a ON p.almacen_id = a.id 
                                   WHERE p.id = ?";
            $stmt_almacen = $conn->prepare($sql_almacen_producto);
            $stmt_almacen->bind_param("i", $primer_producto_id);
            $stmt_almacen->execute();
            $result_almacen = $stmt_almacen->get_result();
            
            if ($result_almacen->num_rows === 0) {
                throw new Exception("No se pudo determinar el almacén de trabajo. Producto ID $primer_producto_id no encontrado.");
            }
            
            $almacen_info = $result_almacen->fetch_assoc();
            $almacen_trabajo = $almacen_info['almacen_id'];
            $stmt_almacen->close();
            
        } else {
            // Usuarios normales solo pueden trabajar con su almacén asignado
            if (!$usuario_almacen_id) {
                throw new Exception('Su usuario no tiene un almacén asignado. Contacte al administrador.');
            }
            $almacen_trabajo = $usuario_almacen_id;
        }
        
        $productos_procesados = [];
        $total_unidades = 0;
        $productos_no_encontrados = [];
        
        // ✅ CORRECCIÓN: Procesar cada producto con mejor manejo de errores
        foreach ($productos as $producto) {
            $producto_id = (int)$producto['id'];
            $cantidad_solicitada = (int)$producto['cantidad'];
            
            if ($cantidad_solicitada <= 0) {
                continue;
            }
            
            // ✅ CORRECCIÓN: Consulta más robusta con mejor manejo de errores
            $sql_producto = "SELECT p.id, p.nombre, p.cantidad, p.almacen_id,
                                   a.nombre as almacen_nombre, 
                                   c.nombre as categoria_nombre 
                            FROM productos p 
                            JOIN almacenes a ON p.almacen_id = a.id 
                            JOIN categorias c ON p.categoria_id = c.id 
                            WHERE p.id = ? FOR UPDATE";
            
            $stmt_producto = $conn->prepare($sql_producto);
            if (!$stmt_producto) {
                throw new Exception("Error preparando consulta de producto: " . $conn->error);
            }
            
            $stmt_producto->bind_param("i", $producto_id);
            $stmt_producto->execute();
            $result_producto = $stmt_producto->get_result();
            
            if ($result_producto->num_rows === 0) {
                $stmt_producto->close();
                $productos_no_encontrados[] = "Producto ID $producto_id";
                continue; // Continuar con el siguiente producto
            }
            
            $producto_info = $result_producto->fetch_assoc();
            $stmt_producto->close();
            
            // ✅ CORRECCIÓN: Verificar que el producto pertenece al almacén correcto
            if ($producto_info['almacen_id'] != $almacen_trabajo) {
                // Para admin: mostrar error específico del almacén
                if ($usuario_rol === 'admin') {
                    throw new Exception("El producto '{$producto_info['nombre']}' no pertenece al almacén de trabajo ({$producto_info['almacen_nombre']}). Todos los productos deben ser del mismo almacén.");
                } else {
                    throw new Exception("El producto '{$producto_info['nombre']}' no pertenece a su almacén asignado.");
                }
            }
            
            // Verificar stock disponible
            if ($producto_info['cantidad'] < $cantidad_solicitada) {
                throw new Exception("Stock insuficiente para '{$producto_info['nombre']}'. Disponible: {$producto_info['cantidad']}, Solicitado: $cantidad_solicitada");
            }
            
            // Actualizar stock del producto
            $nuevo_stock = $producto_info['cantidad'] - $cantidad_solicitada;
            $sql_update = "UPDATE productos SET cantidad = ? WHERE id = ?";
            $stmt_update = $conn->prepare($sql_update);
            if (!$stmt_update) {
                throw new Exception("Error preparando actualización de stock: " . $conn->error);
            }
            
            $stmt_update->bind_param("ii", $nuevo_stock, $producto_id);
            
            if (!$stmt_update->execute()) {
                throw new Exception("Error al actualizar stock del producto '{$producto_info['nombre']}': " . $stmt_update->error);
            }
            $stmt_update->close();
            
            // Registrar entrega en tabla entrega_uniformes
            $sql_entrega = "INSERT INTO entrega_uniformes 
                           (usuario_responsable_id, nombre_destinatario, dni_destinatario, producto_id, cantidad, almacen_id, fecha_entrega) 
                           VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $stmt_entrega = $conn->prepare($sql_entrega);
            if (!$stmt_entrega) {
                throw new Exception("Error preparando registro de entrega: " . $conn->error);
            }
            
            $stmt_entrega->bind_param("issiii", 
                $usuario_id, 
                $destinatario_nombre, 
                $destinatario_dni, 
                $producto_id, 
                $cantidad_solicitada, 
                $almacen_trabajo
            );
            
            if (!$stmt_entrega->execute()) {
                throw new Exception("Error al registrar entrega del producto '{$producto_info['nombre']}': " . $stmt_entrega->error);
            }
            $stmt_entrega->close();
            
            // Registrar movimiento de salida
            $sql_movimiento = "INSERT INTO movimientos 
                              (producto_id, almacen_origen, cantidad, tipo, fecha, usuario_id, estado, descripcion) 
                              VALUES (?, ?, ?, 'salida', NOW(), ?, 'completado', ?)";
            $stmt_mov = $conn->prepare($sql_movimiento);
            if (!$stmt_mov) {
                throw new Exception("Error preparando registro de movimiento: " . $conn->error);
            }
            
            $descripcion = "Entrega a {$destinatario_nombre} (DNI: {$destinatario_dni})";
            $stmt_mov->bind_param("iiiis", 
                $producto_id, 
                $almacen_trabajo, 
                $cantidad_solicitada, 
                $usuario_id, 
                $descripcion
            );
            
            if (!$stmt_mov->execute()) {
                throw new Exception("Error al registrar movimiento: " . $stmt_mov->error);
            }
            $stmt_mov->close();
            
            $productos_procesados[] = [
                'id' => $producto_id,
                'nombre' => $producto_info['nombre'],
                'cantidad' => $cantidad_solicitada,
                'almacen' => $producto_info['almacen_nombre'],
                'stock_anterior' => $producto_info['cantidad'],
                'stock_nuevo' => $nuevo_stock
            ];
            
            $total_unidades += $cantidad_solicitada;
        }
        
        // ✅ CORRECCIÓN: Manejo de productos no encontrados
        if (!empty($productos_no_encontrados) && empty($productos_procesados)) {
            throw new Exception('Ninguno de los productos seleccionados se pudo procesar: ' . implode(', ', $productos_no_encontrados));
        }
        
        if (empty($productos_procesados)) {
            throw new Exception('No se procesó ningún producto válido');
        }
        
        // Mensaje de advertencia si algunos productos no se procesaron
        $mensaje_advertencia = '';
        if (!empty($productos_no_encontrados)) {
            $mensaje_advertencia = ' NOTA: Algunos productos no se encontraron: ' . implode(', ', $productos_no_encontrados);
        }
        
        // Registrar en logs de actividad si existe la tabla
        try {
            $check_logs = $conn->query("SHOW TABLES LIKE 'logs_actividad'");
            if ($check_logs && $check_logs->num_rows > 0) {
                $sql_log = "INSERT INTO logs_actividad (usuario_id, accion, detalle, fecha_accion) 
                            VALUES (?, 'ENTREGA_PRODUCTOS', ?, NOW())";
                $stmt_log = $conn->prepare($sql_log);
                
                if ($stmt_log) {
                    // Crear lista de productos para el detalle
                    $productos_nombres = array_slice(array_map(function($p) { return $p['nombre']; }, $productos_procesados), 0, 3);
                    $productos_texto = implode(', ', $productos_nombres);
                    if (count($productos_procesados) > 3) {
                        $productos_texto .= '...';
                    }
                    
                    $detalle = "Entregó " . count($productos_procesados) . " tipo(s) de productos ({$total_unidades} unidades total) a {$destinatario_nombre} (DNI: {$destinatario_dni}). Productos: {$productos_texto}";
                    $stmt_log->bind_param("is", $usuario_id, $detalle);
                    $stmt_log->execute();
                    $stmt_log->close();
                }
            }
        } catch (Exception $e) {
            // No es crítico si falla el log
            error_log("Error al registrar log de actividad (no crítico): " . $e->getMessage());
        }
        
        // Confirmar transacción
        $conn->commit();
        
        // ✅ CORRECCIÓN: Respuesta exitosa mejorada
        $mensaje_principal = '✅ Entrega registrada exitosamente';
        $mensaje_detalle = "Se entregaron {$total_unidades} unidades de " . count($productos_procesados) . " tipo(s) de productos a {$destinatario_nombre}";
        
        if ($mensaje_advertencia) {
            $mensaje_principal .= $mensaje_advertencia;
        }
        
        enviarRespuesta(true, $mensaje_principal, [
            'destinatario' => $destinatario_nombre,
            'dni' => $destinatario_dni,
            'productos_entregados' => count($productos_procesados),
            'total_unidades' => $total_unidades,
            'fecha_entrega' => date('Y-m-d H:i:s'),
            'productos' => $productos_procesados,
            'productos_no_encontrados' => $productos_no_encontrados,
            'almacen_trabajo' => $almacen_trabajo,
            'preservar_contexto' => true,
            'mensaje_detalle' => $mensaje_detalle
        ]);
        
    } catch (Exception $e) {
        // Rollback en caso de error
        $conn->rollback();
        error_log("Error en entrega personal: " . $e->getMessage() . " | Usuario: " . $usuario_id);
        
        // ✅ CORRECCIÓN: Mensaje de error más específico
        $mensaje_error = $e->getMessage();
        
        // Agregar información adicional para debugging si es necesario
        if (strpos($mensaje_error, 'producto') === false && strpos($mensaje_error, 'almacén') === false) {
            $mensaje_error = "Error procesando la entrega: " . $mensaje_error;
        }
        
        enviarRespuesta(false, $mensaje_error);
    }
}
?>