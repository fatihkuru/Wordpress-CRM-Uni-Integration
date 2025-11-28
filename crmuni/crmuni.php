<?php
/**
 * Plugin Name: CRM UNI
 * Description: WordPress ve CRMUNI entegrasyonunu kolaylaştıran güçlü modül. Kullanıcıların web sitelerindeki formları CRMUNI ile entegre ederek müşteri verilerini yönetmelerine olanak sağlar.
 * Version: 0.1.7
 * Author: Univerco
 * Author URI: https://univerco.com.tr
 * Plugin URI: https://crmuni.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.0
 * Requires at least: 5.0
 * Tested up to: 6.0
 * Text Domain: crmuni
 * Domain Path: /languages
 * Stable tag: 0.1
 */

if (!defined('UNI_DEBUG')) {
    define('UNI_DEBUG', get_option('crmuni_unidebug')); // Debug modu aktif/pasif
}

// Sabitler
define('API_HOST', 'crmuni.com');          
define('API_IP', '89.252.174.19');        
define('API_PORT', 443);                  
define('DEFAULT_NEW_LEAD_STATUS_ID', 2);  
define('DEFAULT_NEW_LEAD_SOURCE_ID', 4);  
define('CRMUNI_TOKEN', 'uni');

// Include sınıflar
require_once plugin_dir_path(__FILE__) . 'includes/class-crmuni.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-crmuniapi.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-crmuni-cf7-integration.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-crmuni-ar-contactus.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-crmuni-copyright.php';

// Debug log
function crmuni_debug_log($data) {
    if (defined('UNI_DEBUG') && UNI_DEBUG) {
        $log_file = plugin_dir_path(__FILE__) . 'unidebug.log';
        $log_entry = "[" . date('Y-m-d H:i:s') . "] " . print_r($data, true) . "\n";
        if (file_put_contents($log_file, $log_entry, FILE_APPEND) === false) {
            error_log('CRMUNI: unidebug.log dosyasına yazılamadı.');
        }
    }
}

// Plugin güncelleme kontrolü
function crmuni_plugin_update_check() {
    $update_check_url = 'https://' . API_HOST . '/crmuni/update-check.php';
    $plugin_data = get_plugin_data(__FILE__);
    $current_version = $plugin_data['Version'];

    add_action('http_api_curl', function ($handle) {
        curl_setopt($handle, CURLOPT_RESOLVE, [API_HOST . ':' . API_PORT . ':' . API_IP]);
    });

    $response = wp_remote_post($update_check_url, [
        'timeout' => 15,
        'headers' => [
            'Accept'    => 'application/json',
            'Host'      => API_HOST,
            'authtoken' => CRMUNI_TOKEN,
        ],
        'body' => ['current_version' => $current_version],
    ]);

    remove_action('http_api_curl', function ($handle) {
        curl_setopt($handle, CURLOPT_RESOLVE, [API_HOST . ':' . API_PORT . ':' . API_IP]);
    });

    if (is_wp_error($response)) {
        crmuni_debug_log("API POST failed: " . $response->get_error_message());
        return;
    }

    $update_data = json_decode(wp_remote_retrieve_body($response));
    if (isset($update_data->new_version) && version_compare($update_data->new_version, $current_version, '>')) {
        $plugin_data = (object)[
            'slug' => 'crmuni',
            'new_version' => $update_data->new_version,
            'url' => $update_data->url,
            'package' => $update_data->package_url,
        ];
        $plugins = get_site_transient('update_plugins');
        $plugins->response['crmuni/crmuni.php'] = $plugin_data;
        set_site_transient('update_plugins', $plugins);
    }
}
add_action('admin_init', 'crmuni_plugin_update_check');

// API ve domain kontrol
function crmuni_send_domain_to_api() {
    $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    if (!$domain) { crmuni_debug_log("Domain alınamadı."); return; }

    $api_url = 'https://' . API_HOST . '/crmuni/api';
    $data = ['domain' => $domain];
    $resolved_ip = gethostbyname(API_HOST);

    if ($resolved_ip === API_HOST || empty($resolved_ip)) {
        crmuni_debug_log("DNS başarısız, IP kullanılacak: " . API_IP);
        add_action('http_api_curl', function ($handle) {
            curl_setopt($handle, CURLOPT_RESOLVE, [API_HOST . ':' . API_PORT . ':' . API_IP]);
        });
    } else {
        crmuni_debug_log("DNS başarılı: " . API_HOST . " -> " . $resolved_ip);
    }

    $response = wp_remote_post($api_url, [
        'method' => 'POST',
        'body' => $data,
        'timeout' => 45,
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Host' => API_HOST,
            'authtoken' => CRMUNI_TOKEN,
        ],
    ]);

    remove_action('http_api_curl', function ($handle) {
        curl_setopt($handle, CURLOPT_RESOLVE, [API_HOST . ':' . API_PORT . ':' . API_IP]);
    });

    if (is_wp_error($response)) {
        crmuni_debug_log("API POST failed: " . $response->get_error_message());
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        crmuni_debug_log("API POST response [$response_code]: " . $response_body);
    }
}
add_action('activated_plugin', 'crmuni_send_domain_to_api');
add_action('upgrader_process_complete', 'crmuni_send_domain_to_api', 10, 2);

// Autoload includes
function crmuni_autoload_modules($directory) {
    foreach (glob(plugin_dir_path(__FILE__) . $directory . '*.php') as $file) require_once $file;
}
crmuni_autoload_modules('includes/');

// Plugin initialization
function crmuni_initialize_plugin() {
    $api_url = get_option('crmuni_api_url');
    $api_key = get_option('crmuni_api_key');

    new CRMunI();

    if ($api_url && $api_key) {
        $crmuni_api = new CRMuniAPI($api_url, $api_key);

        if (get_option('crmuni_cf7_active')) new CRMuni_CF7_Integration($crmuni_api);

        require_once plugin_dir_path(__FILE__) . '../ar-contactus/classes/ArContactUsPerfex.php';
        new CRMuni_Ar_Contactus($crmuni_api);

        if (get_option('crmuni_copyright')) {
            $copyright = new CRMunI_Copyright();
            add_action('wp_footer', [$copyright, 'add_copyright_text'], 50);
        }
    }
}
add_action('plugins_loaded', 'crmuni_initialize_plugin');

// Admin menu
function crmuni_admin_menu() {
    add_menu_page(
        'CRMUNI Ayarları',
        'CRMUNI Ayarları',
        'manage_options',
        'crmuni_settings',
        'crmuni_settings_page',
        'dashicons-admin-network',
        2
    );
}
add_action('admin_menu', 'crmuni_admin_menu');

// Modern admin UI
function crmuni_settings_page() {
    ?>
    <div class="wrap crmuni-wrap">
        <h1>CRM UNI Ayarları</h1>
        <style>
            .crmuni-wrap .form-table th { width: 250px; font-weight:600; }
            .crmuni-wrap input[type=text], .crmuni-wrap input[type=password] { width: 100%; padding: 6px; font-size:14px; }
            .crmuni-wrap input[type=checkbox] { transform: scale(1.4); margin-right:8px; vertical-align:middle; }
            .crmuni-wrap .section { padding:15px; border:1px solid #ddd; margin-bottom:15px; background:#f9f9f9; border-radius:5px; }
            .crmuni-wrap h2.section-title { margin-top:0; margin-bottom:10px; font-size:18px; color:#21759b; }
            .crmuni-wrap .submit { background:#21759b; border-color:#21759b; color:#fff; font-weight:600; padding:8px 16px; text-transform:uppercase; }
            .crmuni-wrap .submit:hover { background:#1b5d7a; border-color:#1b5d7a; }
        </style>
        <form method="post" action="options.php">
            <?php settings_fields('crmuni_options_group'); do_settings_sections('crmuni_settings'); ?>
            
            <div class="section">
                <h2 class="section-title">API Ayarları</h2>
                <table class="form-table">
                    <tr>
                        <th>CRMuni API URL</th>
                        <td><input type="text" name="crmuni_api_url" value="<?php echo esc_attr(get_option('crmuni_api_url')); ?>"/></td>
                    </tr>
                    <tr>
                        <th>CRMuni API Anahtarı</th>
                        <td><input type="password" name="crmuni_api_key" value="<?php echo esc_attr(get_option('crmuni_api_key')); ?>"/></td>
                    </tr>
                </table>
            </div>

            <div class="section">
                <h2 class="section-title">Entegrasyonlar</h2>
                <table class="form-table">
                    <tr><th>Contact Form 7 Aktif</th>
                        <td><input type="checkbox" name="crmuni_cf7_active" value="1" <?php checked(1, get_option('crmuni_cf7_active')); ?>/></td></tr>
                    <tr><th>Ar-Contact Aktif</th>
                        <td><input type="checkbox" disabled checked /> Zorunlu</td></tr>
                    <tr><th>Copyright</th>
                        <td><input type="checkbox" name="crmuni_copyright" value="1" <?php checked(1, get_option('crmuni_copyright')); ?>/></td></tr>
                </table>
            </div>

            <div class="section">
                <h2 class="section-title">Diğer Ayarlar</h2>
                <table class="form-table">
                    <tr><th>UniDebug</th>
                        <td><input type="checkbox" name="crmuni_unidebug" value="1" <?php checked(1, get_option('crmuni_unidebug')); ?>/></td></tr>
                    <tr><th>Uni API</th>
                        <td><input type="checkbox" name="apiuni" value="1" <?php checked(1, get_option('apiuni')); ?>/></td></tr>
                </table>
            </div>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Register settings
function crmuni_register_settings() {
    register_setting('crmuni_options_group', 'crmuni_api_url');
    register_setting('crmuni_options_group', 'crmuni_api_key');
    register_setting('crmuni_options_group', 'crmuni_cf7_active');
    register_setting('crmuni_options_group', 'crmuni_unidebug');
    register_setting('crmuni_options_group', 'crmuni_copyright');
    register_setting('crmuni_options_group', 'apiuni');
}
add_action('admin_init', 'crmuni_register_settings');

// Create mapping table
function crmuni_create_mapping_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'crmuni_cf7_mapping';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        form_id BIGINT(20) NOT NULL,
        form_tag_name VARCHAR(191) NOT NULL,
        api_field_name VARCHAR(191) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY form_tag (form_id, form_tag_name)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'crmuni_create_mapping_table');

// Prevent deactivation
add_action('admin_init', 'prevent_plugin_deactivation');
function prevent_plugin_deactivation() {
    $plugin_file = plugin_basename(__FILE__);
    if (isset($_GET['action'], $_GET['plugin']) && $_GET['action'] === 'deactivate' && $_GET['plugin'] === $plugin_file) {
        wp_die('Bu plugin devre dışı bırakılamaz!');
    }
}

// Uni API frontend
function uniapi_initialize() {
    if (is_admin()) return;
    if (!get_option('apiuni')) return;

    $inline = '
    if (typeof uncData === "undefined" || !Array.isArray(uncData)) {
        uncData = [];
    }
    uncData.push({ "unc.start": (new Date).getTime(), event:"unc.js", domain: window.location.hostname });
    uncData.push({ event:"config", timestamp:new Date().getTime(), config:{type:"defaultConfig", pushdata:false, debugMode:false, uiFeedback:false, navigator:false} });
    ';
    wp_enqueue_script('univerco-api', 'https://api.univerco.com.tr/api.js', [], null, true);
    wp_add_inline_script('univerco-api', $inline, 'before');
}
add_action('wp_enqueue_scripts', 'uniapi_initialize');