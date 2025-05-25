<?php
/*
Plugin Name: SaleAlToque Manager
Description: Productos, ranking, CSV y usuarios activos. Estable con filtros de fecha y detalles de usuarios.
Version: 3.0.1
Author: SaleAlToque.uy
*/

defined('ABSPATH') || exit;

// --- Activación del Plugin y Creación de Tablas ---
register_activation_hook(__FILE__, 'sat_manager_activate');
function sat_manager_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Tabla de estadísticas
    $tabla_estadisticas = $wpdb->prefix . 'sat_estadisticas';
    $sql_estadisticas = "CREATE TABLE IF NOT EXISTS $tabla_estadisticas (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        tipo varchar(20) NOT NULL,
        fecha datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY product_id (product_id),
        KEY tipo (tipo),
        KEY fecha (fecha)
    ) $charset_collate;";

    // Nueva tabla para usuarios activos en tiempo real
    $tabla_active_users = $wpdb->prefix . 'sat_active_users';
    $sql_active_users = "CREATE TABLE IF NOT EXISTS $tabla_active_users (
        user_id bigint(20) DEFAULT 0 NOT NULL,
        session_id varchar(64) NOT NULL,
        last_activity datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY_KEY_COL varchar(128) NOT NULL,
        PRIMARY KEY (PRIMARY_KEY_COL),
        KEY user_id (user_id),
        KEY session_id (session_id),
        KEY last_activity (last_activity)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_estadisticas);
    dbDelta($sql_active_users);
}

// --- Rastreo de Usuarios Activos (Frontend) ---
add_action('template_redirect', 'sat_track_user_activity');
function sat_track_user_activity() {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
        return;
    }

    global $wpdb;
    $tabla_active_users = $wpdb->prefix . 'sat_active_users';
    $user_id = get_current_user_id();
    $session_id_val = '';

    // WordPress no usa sesiones PHP por defecto. Iniciarlas aquí puede causar conflictos.
    // Se utilizará un identificador basado en IP y User Agent para invitados si no hay cookie.
    /*
    if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    $session_id_val = session_id();
    */

    // Usar una cookie para identificar visitantes si es posible, o generar un ID.
    if (isset($_COOKIE['sat_guest_session_id'])) {
        $session_id_val = sanitize_text_field($_COOKIE['sat_guest_session_id']);
    }

    if (empty($session_id_val)) {
        $session_id_val = md5( (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '') . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '') . uniqid(rand(), true) );
        if (!headers_sent()) {
            setcookie('sat_guest_session_id', $session_id_val, time() + (86400 * 30), COOKIEPATH, COOKIE_DOMAIN, false, true); // Cookie por 30 días, HttpOnly
        }
    }
    
    $primary_key_col_val = ($user_id > 0) ? 'user_' . $user_id : 'guest_' . $session_id_val;

    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$tabla_active_users} (user_id, session_id, last_activity, PRIMARY_KEY_COL)
         VALUES (%d, %s, NOW(), %s)
         ON DUPLICATE KEY UPDATE last_activity = NOW(), user_id = VALUES(user_id), session_id = VALUES(session_id)", // Usar VALUES() es más seguro en ON DUPLICATE
        $user_id,
        $session_id_val,
        $primary_key_col_val
    ) );
}

// --- Limpieza periódica de usuarios inactivos ---
// Para tareas periódicas, es mejor usar wp_cron. https://developer.wordpress.org/plugins/cron/
// Por ahora, se deja en wp_loaded, pero considerar cambiarlo para mejor rendimiento.
add_action('wp_loaded', 'sat_clear_old_active_users');
function sat_clear_old_active_users() {
    $inactivity_threshold_minutes = apply_filters('sat_active_users_threshold', 5);

    global $wpdb;
    $tabla_active_users = $wpdb->prefix . 'sat_active_users';

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$tabla_active_users} WHERE last_activity < NOW() - INTERVAL %d MINUTE",
        intval($inactivity_threshold_minutes)
    ) );
}

// --- Encolar Scripts y Estilos para el Panel de Administración ---
add_action('admin_enqueue_scripts', 'sat_manager_admin_scripts');
function sat_manager_admin_scripts($hook) {
    // Cargar scripts solo en la página del plugin
    if ('toplevel_page_salealtoque-manager' != $hook) {
        return;
    }

    wp_enqueue_script(
        'chart-js',
        'https://cdn.jsdelivr.net/npm/chart.js',
        array(),
        '4.4.1',
        true
    );

    wp_enqueue_script(
        'sat-manager-chart-script',
        plugins_url('sat-manager-chart.js', __FILE__),
        array('jquery', 'chart-js'), // dependencia de chart-js
        '3.0.1', // Versión actualizada
        true
    );

    global $wpdb;
    $tabla_active_users = $wpdb->prefix . 'sat_active_users';
    $tabla_estadisticas = $wpdb->prefix . 'sat_estadisticas';
    $threshold_minutes = apply_filters('sat_active_users_threshold', 5);

    // Datos para el gráfico de usuarios activos
    $active_logged_in_users = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT user_id) FROM {$tabla_active_users} WHERE user_id > 0 AND last_activity >= NOW() - INTERVAL %d MINUTE",
        $threshold_minutes
    ));
    $active_guest_users = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$tabla_active_users} WHERE user_id = 0 AND last_activity >= NOW() - INTERVAL %d MINUTE",
        $threshold_minutes
    ));

    // Datos para gráfico de actividad por días (últimos 7 días)
    $dias_semana_es = [1 => 'Dom', 2 => 'Lun', 3 => 'Mar', 4 => 'Mié', 5 => 'Jue', 6 => 'Vie', 7 => 'Sáb'];
    $actividad_por_dia_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            DAYOFWEEK(fecha) as dia_num, 
            COUNT(*) as total_eventos
        FROM {$tabla_estadisticas}
        WHERE fecha >= DATE_SUB(NOW(), INTERVAL %d DAY)
        GROUP BY dia_num
        ORDER BY dia_num",
        7 // Últimos 7 días
    ));
    
    $actividad_dias_labels = [];
    $actividad_dias_data = [];
    // Ordenar y rellenar datos para los 7 días de la semana, empezando por el día actual hacia atrás si es necesario,
    // o simplemente usar los días que tienen datos. Aquí se usarán los que tienen datos y se mapearán.
    // Para una secuencia completa de 7 días, se necesitaría más lógica para rellenar días sin actividad.
    // Este ejemplo solo usa los días con actividad y los traduce.
    foreach($actividad_por_dia_raw as $row) {
        $actividad_dias_labels[] = isset($dias_semana_es[$row->dia_num]) ? $dias_semana_es[$row->dia_num] : 'Día ' . $row->dia_num;
        $actividad_dias_data[] = $row->total_eventos;
    }


    // Datos para gráfico de actividad por horas (últimos 7 días)
    $actividad_por_hora_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            HOUR(fecha) as hora, 
            COUNT(*) as total_eventos
        FROM {$tabla_estadisticas}
        WHERE fecha >= DATE_SUB(NOW(), INTERVAL %d DAY) 
        GROUP BY hora
        ORDER BY hora",
        7 // Últimos 7 días
    ));

    $actividad_horas_labels = [];
    $actividad_horas_data = [];
    foreach($actividad_por_hora_raw as $row) {
        $actividad_horas_labels[] = str_pad($row->hora, 2, '0', STR_PAD_LEFT) . ':00';
        $actividad_horas_data[] = $row->total_eventos;
    }

    // Pasar datos a JavaScript
    wp_localize_script('sat-manager-chart-script', 'satChartData', array(
        'loggedInActive' => intval($active_logged_in_users),
        'guestActive' => intval($active_guest_users),
        'actividadDias' => [ // JS espera un objeto con labels y data
            'labels' => $actividad_dias_labels,
            'data' => $actividad_dias_data
        ],
        'actividadHoras' => [ // JS espera un objeto con labels y data
            'labels' => $actividad_horas_labels,
            'data' => $actividad_horas_data
        ],
        'isPluginPage' => true // Para que JS sepa que está en la página del plugin
    ));
    
    // --- ESTA ERA LA LÍNEA CON EL ERROR DE SINTAXIS ($) ---
    // $ <--- Eliminada

    // Agregar estilos CSS personalizados (sin cambios, pero se pueden mover a un archivo .css)
    wp_add_inline_style('wp-admin', "
        .sat-filter-box{background:#f9f9f9;border:1px solid #ddd;padding:15px;margin:20px 0;border-radius:5px;}
        .sat-filter-box h3{margin-top:0;}
        .sat-active-users-list{max-height:300px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#fff;border-radius:5px;}
        .sat-user-item{padding:8px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;}
        .sat-user-item:last-child{border-bottom:none;}
        .sat-user-name{font-weight:bold;color:#0073aa;}
        .sat-user-time{font-size:0.9em;color:#666;}
        .sat-charts-container{display:flex;gap:20px;margin:20px 0;flex-wrap:wrap;}
        .sat-chart-box{flex:1;min-width:300px;max-width:400px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px;text-align:center;}
        .sat-chart-box h4{margin:0 0 10px 0;font-size:14px;color:#333;}
        .sat-chart-canvas{width:100% !important;height:200px !important;}
    ");
}

// --- Panel de Administración ---
add_action('admin_menu', function () {
    // Cambiada la posición del menú a 26 (suele ser después de Entradas o Medios)
    add_menu_page('SaleAlToque Manager', '🦁 SaleAlToque', 'manage_options', 'salealtoque-manager', 'sat_manager_panel', 'dashicons-chart-area', 26);
});

function sat_manager_panel() {
    global $wpdb;

    $tabla_eventos = $wpdb->prefix . 'sat_estadisticas';
    $tabla_posts = $wpdb->posts;
    $tabla_active_users = $wpdb->prefix . 'sat_active_users';

    // Procesar filtros de fecha
    $fecha_desde = isset($_GET['fecha_desde']) ? sanitize_text_field($_GET['fecha_desde']) : '';
    $fecha_hasta = isset($_GET['fecha_hasta']) ? sanitize_text_field($_GET['fecha_hasta']) : '';
    
    $date_conditions = [];
    $date_params = [];

    if (!empty($fecha_desde)) {
        $date_conditions[] = "e.fecha >= %s";
        $date_params[] = $fecha_desde . ' 00:00:00';
    }
    if (!empty($fecha_hasta)) {
        $date_conditions[] = "e.fecha <= %s";
        $date_params[] = $fecha_hasta . ' 23:59:59';
    }
    
    $date_where_eventos = ""; // Para la tabla de eventos (alias 'e')
    if (!empty($date_conditions)) {
        $date_where_eventos = " AND (" . implode(" AND ", $date_conditions) . ")";
    }
    
    // Para consultas que no usan alias 'e' para la tabla de eventos
    $date_where_directo = ""; 
    $date_params_directo = [];
    if (!empty($fecha_desde)) {
        $date_where_directo .= " AND fecha >= %s";
        $date_params_directo[] = $fecha_desde . ' 00:00:00';
    }
    if (!empty($fecha_hasta)) {
        $date_where_directo .= " AND fecha <= %s";
        $date_params_directo[] = $fecha_hasta . ' 23:59:59';
    }


    echo '<div class="wrap"><h1>🦁 SaleAlToque Manager 3.0.1</h1>'; // Versión actualizada

    // Comprobación de existencia de la tabla de estadísticas
    if ($wpdb->get_var("SHOW TABLES LIKE '$tabla_eventos'") !== $tabla_eventos) {
        echo '<p style="color:red;">⚠️ La tabla de estadísticas no existe. Visitá un producto para activarla.</p></div>';
        return;
    }

    // --- FILTROS POR FECHA ---
    echo '<div class="sat-filter-box">';
    echo '<h3>📅 Filtrar por fechas</h3>';
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="salealtoque-manager">';
    echo '<label for="fecha_desde">Desde: </label>';
    echo '<input type="date" id="fecha_desde" name="fecha_desde" value="' . esc_attr($fecha_desde) . '">';
    echo ' ';
    echo '<label for="fecha_hasta">Hasta: </label>';
    echo '<input type="date" id="fecha_hasta" name="fecha_hasta" value="' . esc_attr($fecha_hasta) . '">';
    echo ' ';
    echo '<input type="submit" class="button" value="Filtrar">';
    echo ' ';
    echo '<a href="' . admin_url('admin.php?page=salealtoque-manager') . '" class="button">Limpiar filtros</a>';
    echo '</form>';
    
    if (!empty($fecha_desde) || !empty($fecha_hasta)) {
        echo '<p><strong>Filtros activos:</strong> ';
        if (!empty($fecha_desde)) echo 'Desde: ' . esc_html($fecha_desde) . ' ';
        if (!empty($fecha_hasta)) echo 'Hasta: ' . esc_html($fecha_hasta);
        echo '</p>';
    }
    echo '</div>';

    // --- USUARIOS ACTIVOS EN TIEMPO REAL CON DETALLES ---
    echo '<h2 style="margin-top:20px;">👤 Usuarios Activos en Tiempo Real <span style="font-size:0.8em;color:#777;">(Últimos ' . esc_html(apply_filters('sat_active_users_threshold', 5)) . ' minutos)</span></h2>';

    $threshold_minutes = apply_filters('sat_active_users_threshold', 5);
    $usuarios_activos_detallados_query = $wpdb->prepare(
        "SELECT user_id, last_activity 
         FROM {$tabla_active_users} 
         WHERE last_activity >= NOW() - INTERVAL %d MINUTE 
         ORDER BY last_activity DESC",
        $threshold_minutes
    );
    $usuarios_activos_detallados = $wpdb->get_results($usuarios_activos_detallados_query);

    $active_logged_in_users_count = 0;
    $active_guest_users_count = 0;
    $usuarios_logueados = [];

    if ($usuarios_activos_detallados) {
        foreach ($usuarios_activos_detallados as $usuario_activo) {
            if ($usuario_activo->user_id > 0) {
                $active_logged_in_users_count++;
                $user_data = get_userdata($usuario_activo->user_id);
                if ($user_data) {
                    $usuarios_logueados[] = [
                        'name' => $user_data->display_name,
                        'email' => $user_data->user_email,
                        'last_activity' => $usuario_activo->last_activity
                    ];
                }
            } else {
                $active_guest_users_count++;
            }
        }
    }
    $total_active_users = $active_logged_in_users_count + $active_guest_users_count;

    echo '<div style="display:flex; gap:20px; align-items:flex-start;">';
    // Este canvas será usado por el script JS para el gráfico de usuarios activos (logueados vs visitantes)
    echo '<div style="width:200px; height:200px;"><canvas id="usuarios-activos-chart"></canvas></div>'; 
    echo '<div style="flex:1;">';
    echo '<p><strong>Total de usuarios activos:</strong> ' . intval($total_active_users) . '</p>';
    echo '<p><strong>Usuarios activos logueados:</strong> ' . intval($active_logged_in_users_count) . '</p>';
    echo '<p><strong>Usuarios activos visitantes:</strong> ' . intval($active_guest_users_count) . '</p>';

    if (!empty($usuarios_logueados)) {
        echo '<h4>👥 Usuarios logueados activos:</h4>';
        echo '<div class="sat-active-users-list">';
        foreach ($usuarios_logueados as $usuario) {
            $tiempo_transcurrido = human_time_diff(strtotime($usuario['last_activity']), current_time('timestamp'));
            echo '<div class="sat-user-item">';
            echo '<div>';
            echo '<span class="sat-user-name">' . esc_html($usuario['name']) . '</span><br>';
            echo '<small>' . esc_html($usuario['email']) . '</small>';
            echo '</div>';
            echo '<span class="sat-user-time">Hace ' . esc_html($tiempo_transcurrido) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';

    // --- GRÁFICOS DE ACTIVIDAD COMPACTOS (AHORA USANDO IDS ÚNICOS) ---
    echo '<h2 style="margin-top:30px;">📊 Análisis de Actividad (Últimos 7 días)</h2>';
    echo '<div class="sat-charts-container">';
    
    echo '<div class="sat-chart-box">';
    echo '<h4>📅 Actividad por días de la semana</h4>';
    echo '<canvas id="chart-dias" class="sat-chart-canvas"></canvas>'; // ID único
    echo '</div>';
    
    echo '<div class="sat-chart-box">';
    echo '<h4>⏰ Actividad por horas del día</h4>';
    echo '<canvas id="chart-horas" class="sat-chart-canvas"></canvas>'; // ID único
    echo '</div>';
    
    // Se eliminó el tercer gráfico de usuarios activos aquí, ya que 'usuarios-activos-chart' ya se usa arriba
    // y el JS actual solo configura un gráfico de usuarios activos.
    // Si se necesita otro tipo de gráfico aquí, deberá tener un ID único y su propia lógica JS.
    
    echo '</div>';

    // --- PRODUCTOS MÁS VISITADOS (Top 10) CON FILTROS ---
    echo '<h2>📦 Productos más visitados (Top 10)</h2>';
    
    $productos_query_sql = "
        SELECT
            p.ID,
            p.post_title,
            p.post_author,
            SUM(CASE WHEN e.tipo = 'visita' THEN 1 ELSE 0 END) AS visitas,
            SUM(CASE WHEN e.tipo = 'whatsapp' THEN 1 ELSE 0 END) AS whatsapps,
            SUM(CASE WHEN e.tipo = 'llamada' THEN 1 ELSE 0 END) AS llamadas
        FROM {$tabla_posts} p
        LEFT JOIN {$tabla_eventos} e ON p.ID = e.product_id
        WHERE p.post_type = 'product' AND p.post_status = 'publish'";
    
    $productos_query_sql .= $date_where_eventos; // $date_where_eventos ya incluye el AND
    $productos_query_sql .= " GROUP BY p.ID, p.post_title, p.post_author ORDER BY visitas DESC LIMIT 10";
    
    $productos_top10 = $wpdb->get_results( $wpdb->prepare($productos_query_sql, $date_params) );

    if ($productos_top10) {
        echo '<div style="display:flex;flex-wrap:wrap;gap:20px;">';
        foreach ($productos_top10 as $p) {
            $autor = get_userdata($p->post_author);
            $nombre = $autor ? $autor->display_name : 'Desconocida';
            echo "<div style='border:1px solid #ccc;padding:15px;border-radius:10px;background:#fff;flex:1 1 calc(33.33% - 20px)'>";
            echo "<strong>📦 Producto:</strong> " . esc_html($p->post_title) . "<br>";
            echo "<strong>🏪 Tienda:</strong> " . esc_html($nombre) . "<br>";
            echo "<strong>👁️ Visitas:</strong> " . intval($p->visitas) . "<br>";
            echo "<strong>💬 WhatsApp:</strong> " . intval($p->whatsapps) . "<br>";
            echo "<strong>📞 Llamadas:</strong> " . intval($p->llamadas) . "</div>";
        }
        echo '</div>';
    } else {
        echo '<p style="color:#999;">No hay productos con estadísticas en el período seleccionado.</p>';
    }

    // --- RANKING DE TIENDAS MÁS ACTIVAS CON FILTROS ---
    echo '<h2 style="margin-top:40px;">🏆 Ranking de tiendas más activas</h2>';
    
    $autores = $wpdb->get_col("SELECT DISTINCT post_author FROM {$tabla_posts} WHERE post_type='product' AND post_status='publish'");
    $ranking = [];

    foreach ($autores as $autor_id) {
        $autor_id_int = intval($autor_id); // Asegurar que es un entero
        $autor = get_userdata($autor_id_int);
        if (!$autor) continue;
        $nombre = $autor->display_name;
        
        $query_ranking_sql = "
            SELECT COUNT(*) FROM {$tabla_eventos} e
            WHERE e.product_id IN (SELECT ID FROM {$tabla_posts} WHERE post_author = %d AND post_type='product' AND post_status='publish')
        ";
        
        $current_ranking_params = array_merge([$autor_id_int], $date_params); // Parámetros para esta consulta
        $query_ranking_sql .= $date_where_eventos; // $date_where_eventos ya incluye el AND
        
        $total_interacciones_autor = $wpdb->get_var( $wpdb->prepare($query_ranking_sql, $current_ranking_params) );
        $ranking[] = ['nombre' => $nombre, 'total' => intval($total_interacciones_autor)];
    }

    // Cambiada función flecha por función anónima para compatibilidad PHP < 7.4
    usort($ranking, function($a, $b) {
        if ($a['total'] == $b['total']) {
            return 0;
        }
        return ($a['total'] < $b['total']) ? 1 : -1; // For descending order
    });

    if ($ranking && array_sum(array_column($ranking, 'total')) > 0) {
        echo '<ul>';
        foreach (array_slice($ranking, 0, 5) as $r) {
            if ($r['total'] > 0) {
                echo "<li><strong>" . esc_html($r['nombre']) . "</strong>: " . esc_html($r['total']) . " interacciones</li>";
            }
        }
        echo '</ul>';
    } else {
        echo '<p style="color:#999;">No hay actividad en el período seleccionado.</p>';
    }

    // --- TODOS LOS PRODUCTOS POR TIENDA CON FILTROS ---
    echo '<h2 style="margin-top:40px;">📦 Todos los productos por tienda</h2>';

    $productos_por_autor = [];
    $todos_los_productos = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_author
        FROM {$tabla_posts} p
        WHERE p.post_type = 'product' AND p.post_status = 'publish'
        ORDER BY p.post_author, p.post_title
    ");

    if ($todos_los_productos) {
        $stats_query_sql = "
            SELECT
                product_id,
                SUM(CASE WHEN tipo = 'visita' THEN 1 ELSE 0 END) AS visitas,
                SUM(CASE WHEN tipo = 'whatsapp' THEN 1 ELSE 0 END) AS whatsapps,
                SUM(CASE WHEN tipo = 'llamada' THEN 1 ELSE 0 END) AS llamadas
            FROM {$tabla_eventos} e 
            WHERE 1=1 "; // 1=1 para facilitar la adición de AND
        
        $stats_query_sql .= $date_where_eventos; // $date_where_eventos ya incluye el AND
        $stats_query_sql .= " GROUP BY product_id";
        
        $all_product_stats = $wpdb->get_results( $wpdb->prepare($stats_query_sql, $date_params), OBJECT_K );

        foreach ($todos_los_productos as $producto) {
            $autor_id = $producto->post_author;
            if (!isset($productos_por_autor[$autor_id])) {
                $autor = get_userdata($autor_id);
                $productos_por_autor[$autor_id]['nombre'] = $autor ? $autor->display_name : 'Desconocida';
                $productos_por_autor[$autor_id]['productos'] = [];
            }

            $stats = isset($all_product_stats[$producto->ID]) ? $all_product_stats[$producto->ID] : (object)['visitas' => 0, 'whatsapps' => 0, 'llamadas' => 0];

            $productos_por_autor[$autor_id]['productos'][] = [
                'titulo' => esc_html($producto->post_title),
                'visitas' => intval($stats->visitas),
                'whatsapps' => intval($stats->whatsapps),
                'llamadas' => intval($stats->llamadas),
            ];
        }

        foreach ($productos_por_autor as $autor_data) {
            echo '<div style="margin-bottom: 30px;">';
            echo '<h3>🏪 Tienda: ' . esc_html($autor_data['nombre']) . '</h3>';
            if (!empty($autor_data['productos'])) {
                echo '<div style="display:flex; flex-wrap:wrap; gap:20px;">';
                foreach ($autor_data['productos'] as $prod_data) {
                    echo "<div style='border:1px solid #ccc;padding:15px;border-radius:10px;background:#fff;flex:1 1 calc(33.33% - 20px); max-width: calc(33.33% - 20px); box-sizing: border-box;'>";
                    echo "<strong>📦 Producto:</strong> " . $prod_data['titulo'] . "<br>";
                    echo "<strong>👁️ Visitas:</strong> " . $prod_data['visitas'] . "<br>";
                    echo "<strong>💬 WhatsApp:</strong> " . $prod_data['whatsapps'] . "<br>";
                    echo "<strong>📞 Llamadas:</strong> " . $prod_data['llamadas'] . "</div>";
                }
                echo '</div>';
            } else {
                echo '<p style="color:#777;">No tiene productos publicados.</p>';
            }
            echo '</div>';
        }

    } else {
        echo '<p style="color:#999;">No hay productos publicados aún.</p>';
    }

    // --- Exportar CSV CON FILTROS ---
    $export_params_array = array(
        'page' => 'salealtoque-manager',
        'exportar_csv' => '1'
    );
    
    if (!empty($fecha_desde)) $export_params_array['fecha_desde'] = $fecha_desde;
    if (!empty($fecha_hasta)) $export_params_array['fecha_hasta'] = $fecha_hasta;
    
    $export_url = wp_nonce_url(add_query_arg($export_params_array, admin_url('admin.php')), 'exportar_sat_csv', 'sat_export_nonce');
    
    echo '<p style="margin-top:30px;"><a href="' . esc_url($export_url) . '" class="button button-primary">⬇ Exportar CSV de Eventos';
    if (!empty($fecha_desde) || !empty($fecha_hasta)) {
        echo ' (Filtrado)';
    }
    echo '</a></p>';
    echo '</div>'; // Cierre de .wrap
}

// --- Lógica de exportación CSV (con nonce y filtros) ---
add_action('admin_init', function () {
    if (isset($_GET['exportar_csv']) && current_user_can('manage_options')) {
        if (!isset($_GET['sat_export_nonce']) || !wp_verify_nonce($_GET['sat_export_nonce'], 'exportar_sat_csv')) {
            wp_die('¡Acción de seguridad no válida!');
        }

        global $wpdb;
        $tabla_eventos = $wpdb->prefix . 'sat_estadisticas';

        if ($wpdb->get_var("SHOW TABLES LIKE '$tabla_eventos'") !== $tabla_eventos) {
            // Podrías mostrar un aviso aquí en lugar de solo retornar si es interactivo,
            // pero como es una descarga, fallar silenciosamente o morir es una opción.
            wp_die('La tabla de estadísticas no existe.'); 
            return;
        }

        $fecha_desde = isset($_GET['fecha_desde']) ? sanitize_text_field($_GET['fecha_desde']) : '';
        $fecha_hasta = isset($_GET['fecha_hasta']) ? sanitize_text_field($_GET['fecha_hasta']) : '';
        
        $export_date_conditions = [];
        $export_date_params = [];

        if (!empty($fecha_desde)) {
            $export_date_conditions[] = "fecha >= %s";
            $export_date_params[] = $fecha_desde . ' 00:00:00';
        }
        if (!empty($fecha_hasta)) {
            $export_date_conditions[] = "fecha <= %s";
            $export_date_params[] = $fecha_hasta . ' 23:59:59';
        }
        
        $export_date_where = "";
        if (!empty($export_date_conditions)) {
            $export_date_where = " WHERE " . implode(" AND ", $export_date_conditions);
        }

        $filename = 'productos_estadisticas';
        if (!empty($fecha_desde) || !empty($fecha_hasta)) {
            $filename .= '_filtrado';
            if (!empty($fecha_desde)) $filename .= '_desde_' . str_replace('-', '', $fecha_desde);
            if (!empty($fecha_hasta)) $filename .= '_hasta_' . str_replace('-', '', $fecha_hasta);
        }
        $filename .= '.csv';

        header('Content-Type: text/csv; charset=utf-8'); // Añadido charset
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        $output = fopen('php://output', 'w');

        // BOM para UTF-8 en Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 

        fputcsv($output, ['Producto ID', 'Nombre del Producto', 'Tienda (Autor)', 'Tipo de Evento', 'Fecha']);

        $query_sql = "SELECT product_id, tipo, fecha FROM {$tabla_eventos} {$export_date_where} ORDER BY fecha DESC";
        $rows = $wpdb->get_results( $wpdb->prepare($query_sql, $export_date_params) );

        if ($rows) {
            foreach ($rows as $r) {
                $product_title = get_the_title($r->product_id);
                $post_author_id = get_post_field('post_author', $r->product_id);
                $author_name = 'Desconocida';
                if ($post_author_id) {
                    $author_data = get_userdata($post_author_id);
                    $author_name = $author_data ? $author_data->display_name : 'Desconocida (ID: ' . $post_author_id . ')';
                }
                fputcsv($output, [$r->product_id, $product_title, $author_name, $r->tipo, $r->fecha]);
            }
        }
        fclose($output);
        exit;
    }
});
