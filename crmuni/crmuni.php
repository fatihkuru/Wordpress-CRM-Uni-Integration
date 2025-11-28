<?php
/**
 * Plugin Name: CRM UNI
 * Description: Bu modül, WordPress ve CRMUNI entegrasyonunu kolaylaştıran güçlü bir modüldür. Kullanıcıların, web sitelerindeki iletişim formlarını CRMUNI ile entegre ederek, müşteri verilerini hızlı ve verimli bir şekilde yönetmelerine olanak tanır. Uni Api Updated.
 * Version: 0.1.6.3
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
    define('UNI_DEBUG', get_option('crmuni_unidebug')); // Debug modu aktif etmek için true yapın, devre dışı bırakmak için false.
}

define('API_HOST', 'crmuni.com');  // API domaini
define('API_IP', '89.252.174.19');    // API IP adresi
define('API_PORT', 443);              // HTTPS için port (443)
define('DEFAULT_NEW_LEAD_STATUS_ID', 2);              // CRMUNI Default Lead Status Id'si
define('DEFAULT_NEW_LEAD_SOURCE_ID', 4);              // CRMUNI Default Lead Status Id'si
define('CRMUNI_TOKEN', 'uni');

// Güncelleme kontrol fonksiyonu ekle
function crmuni_plugin_update_check()
{
    $update_check_url = 'https://' . API_HOST .'/crmuni/update-check.php'; // Güncelleme kontrol URL'si

    // Güncel eklenti bilgilerini alın
    $plugin_data = get_plugin_data(__FILE__);
    $current_version = $plugin_data['Version']; // Mevcut sürümü alın

    // `http_api_curl` filtresiyle IP çözümleme ekleme
    add_action('http_api_curl', function ($handle) {
        curl_setopt($handle, CURLOPT_RESOLVE, array(
            API_HOST . ':' . API_PORT . ':' . API_IP, // Alan adı-IP eşleştirme
        ));
    });

    // Güncelleme bilgilerini al
    $response = wp_remote_post($update_check_url, array(
        'timeout'           => 15,
        'headers'           => array(
            'Accept'        => 'application/json',
            'Host'          => API_HOST, // Orijinal alan adı
            'authtoken'     => CRMUNI_TOKEN,   // Doğru format
        ),
        'body' => array(
            'current_version' => $current_version, // Mevcut sürümü gönder
        ),
    ));

    // `http_api_curl` filtresini kaldır
    remove_action('http_api_curl', function ($handle) {
        curl_setopt($handle, CURLOPT_RESOLVE, array(
            API_HOST . ':' . API_PORT . ':' . API_IP,
        ));
    });

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        crmuni_debug_log("API POST failed: " . $error_message);
        return; // Hata durumunda işlem yapma
    }

    $update_data = json_decode(wp_remote_retrieve_body($response));

    // Eğer yeni sürüm bilgisi varsa, güncelleme bildirimi yap
    if (isset($update_data->new_version) && version_compare($update_data->new_version, $current_version, '>')) {
        $plugin_data = (object) array(
            'slug' => 'crmuni',
            'new_version' => $update_data->new_version,
            'url' => $update_data->url,
            'package' => $update_data->package_url,
        );

        $plugins = get_site_transient('update_plugins');
        $plugins->response['crmuni/crmuni.php'] = $plugin_data;
        set_site_transient('update_plugins', $plugins);
    }
}
add_action('admin_init', 'crmuni_plugin_update_check');


// Post işlemlerinde DNS çözümleme kontrolü ve IP ile çözümleme
function crmuni_send_domain_to_api() {
    // WordPress domain bilgisini al
    $domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '');

    // Domain boşsa hata kaydet
    if (empty($domain)) {
        crmuni_debug_log("Domain bilgisi alınamadı.");
        return;
    }

    // API URL'si
    $api_url = 'https://' . API_HOST . '/crmuni/api';

    // API'ye gönderilecek veriler
    $data = array(
        'domain' => $domain,
    );

    // DNS çözümlemesini kontrol et
    $resolved_ip = gethostbyname(API_HOST);
    if ($resolved_ip === API_HOST || empty($resolved_ip)) {
        crmuni_debug_log("DNS çözümleme başarısız: " . API_HOST . ". IP kullanılacak: " . API_IP);

        // `http_api_curl` filtresiyle IP çözümlemesi ekle
        add_action('http_api_curl', function ($handle) {
            curl_setopt($handle, CURLOPT_RESOLVE, array(
                API_HOST . ':' . API_PORT . ':' . API_IP, // Alan adı-IP eşleştirme
            ));
        });
    } else {
        crmuni_debug_log("DNS çözümleme başarılı: " . API_HOST . " -> " . $resolved_ip);
    }

    // API'ye POST isteği gönder
    $response = wp_remote_post($api_url, array(
        'method'    => 'POST',
        'body'      => $data,
        'timeout'   => 45,
        'headers'   => array(
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Host'          => API_HOST, // Orijinal alan adı
            'authtoken'     => CRMUNI_TOKEN,   // Doğru format
        ),
    ));

    //crmuni_debug_log($response);

    // `http_api_curl` filtresini kaldır
    remove_action('http_api_curl', function ($handle) {
        curl_setopt($handle, CURLOPT_RESOLVE, array(
            API_HOST . ':' . API_PORT . ':' . API_IP,
        ));
    });

    // Yanıtı kontrol et
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        crmuni_debug_log("API POST failed: " . $error_message);
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200) {
            crmuni_debug_log("API POST success: " . $response_body);
        } else {
            crmuni_debug_log("API POST failed with response code: " . $response_code);
        }
    }
}

// Modül yüklendiğinde veya güncellendiğinde API'ye veri gönder
add_action('activated_plugin', 'crmuni_send_domain_to_api'); // Plugin aktivasyonu
add_action('upgrader_process_complete', 'crmuni_send_domain_to_api', 10, 2); // Plugin güncellemesi


// Autoload fonksiyonunu
function crmuni_autoload_modules($directory)
{
    $directory = plugin_dir_path(__FILE__).$directory; 
    foreach (glob($directory . '*.php') as $file) {
        require_once $file;
    }
}

// Eklenti içindeki "includes" klasörünü tarayın
crmuni_autoload_modules('includes/');

// CRMuni_CF7_Integration sınıfını başlat
function crmuni_initialize_plugin()
{
    // CRMuni API bağlantısını oluştur
    $api_url = get_option('crmuni_api_url');
    $api_key = get_option('crmuni_api_key');

    new CRMunI();
    
    if ($api_url && $api_key) {
        
        $crmuni_api = new CRMuniAPI($api_url, $api_key);

        $cf7_active = get_option('crmuni_cf7_active');
        $ar_contact_active = true;
        
        $copyright_active = get_option('crmuni_copyright');

        if ($cf7_active) {
            new CRMuni_CF7_Integration($crmuni_api);
        }
        
        if ($ar_contact_active) {
            require_once plugin_dir_path(__FILE__) . '../ar-contactus/classes/ArContactUsPerfex.php';
            new CRMuni_Ar_Contactus($crmuni_api);
        }
        
        if ($copyright_active) {
            $copyright = new CRMunI_Copyright();
            add_action('wp_footer', array($copyright, 'add_copyright_text'), 50); // Footer için copyright metni ekle
        }

    }
}

add_action('plugins_loaded', 'crmuni_initialize_plugin');

// Admin paneli menüsü ekleyin
function crmuni_admin_menu()
{
    // Ana menü
    add_menu_page(
        'CRMUNI Ayarları',          // Sayfa başlığı
        'CRMUNI Ayarları',          // Menü adı
        'manage_options',           // Erişim izni
        'crmuni_settings',          // Sayfa slug'ı
        'crmuni_settings_page',     // Sayfa fonksiyonu
        'dashicons-admin-network',  // İkon
        0                          // Menü sırası
    );
}

add_action('admin_menu', 'crmuni_admin_menu');


function uniapi_initialize() {
    if (is_admin()) return;

    $active = get_option('apiuni');
    if (!$active) return;

    // inline config
        $inline = '
        if (typeof uncData === "undefined" || !Array.isArray(uncData)) {
            uncData = [];
        }
        
        uncData.push({
            "unc.start":(new Date).getTime(),
            event:"unc.js",
            domain:window.location.hostname
        });

        uncData.push({
            event: "config",
            timestamp: new Date().getTime(),
            config: {
                type: "defaultConfig",
                pushdata: false,
                debugMode: false,
                uiFeedback: false,
                navigator: false
            }
        });
    ';

    // harici api script
    wp_enqueue_script('univerco-api', 'https://api.univerco.com.tr/api.js', array(), null, true);
    wp_add_inline_script('univerco-api', $inline, 'before');
}
add_action('wp_enqueue_scripts', 'uniapi_initialize');

// Ayar sayfası içeriği
function crmuni_settings_page()
{
    ?>
    <div class="wrap">
        <h1>CRM UNI Ayarları</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('crmuni_options_group');
            do_settings_sections('crmuni_settings');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">CRMuni API URL</th>
                    <td>
                        <input type="text" name="crmuni_api_url"
                            value="<?php echo esc_attr(get_option('crmuni_api_url')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">CRMuni API Anahtarı</th>
                    <td>
                        <input type="text" name="crmuni_api_key"
                            value="<?php echo esc_attr(get_option('crmuni_api_key')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Contact Form 7 Aktif</th>
                    <td>
                        <input type="checkbox" name="crmuni_cf7_active" value="1" <?php checked(1, get_option('crmuni_cf7_active'), true); ?> />
                    </td>
                </tr>
                <tr>
                    <th scope="row">UniDebug</th>
                    <td>
                        <input type="checkbox" name="crmuni_unidebug" value="1" <?php checked(1, get_option('crmuni_unidebug'), true); ?> />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Copyright</th>
                    <td>
                        <input type="checkbox" name="crmuni_copyright" value="1" <?php checked(1, get_option('crmuni_copyright'), true); ?> />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Uni Api</th>
                    <td>
                        <input type="checkbox" name="apiuni" value="1" <?php checked(1, get_option('apiuni'), true); ?> />
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Ayarları kaydetmek için
function crmuni_register_settings()
{
    register_setting('crmuni_options_group', 'crmuni_api_url');
    register_setting('crmuni_options_group', 'crmuni_api_key');
    register_setting('crmuni_options_group', 'crmuni_cf7_active');
    register_setting('crmuni_options_group', 'crmuni_unidebug');
    register_setting('crmuni_options_group', 'crmuni_copyright');
    register_setting('crmuni_options_group', 'apiuni');
}
add_action('admin_init', 'crmuni_register_settings');


// Veritabanı tablosunu oluştur
function crmuni_create_mapping_table()
{
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

register_activation_hook(__FILE__, 'crmuni_create_mapping_table'); // Plugin aktif olduğunda tabloyu oluştur


// debug log fonksiyonu
function crmuni_debug_log($data)
{
    if (defined('UNI_DEBUG') && UNI_DEBUG) {
        $log_file = plugin_dir_path(__FILE__) . 'unidebug.log';

        // Veriyi log dosyasına yaz
        $log_entry = "[" . date('Y-m-d H:i:s') . "] " . print_r($data, true) . "\n";
        $result = file_put_contents($log_file, $log_entry, FILE_APPEND);

        // Eğer yazma işlemi başarısızsa log kaydını error_log ile bildirelim
        if ($result === false) {
            error_log('CRMUNI: unidebug.log dosyasına yazılamadı.');
        }
    }
}


// Plugin'in admin panelinden deaktive edilmesini engellemek
add_action('admin_init', 'prevent_plugin_deactivation');
function prevent_plugin_deactivation() {
    // Plugin'in dosya yolunu kontrol et
    $plugin_path = plugin_basename( __FILE__ );

    // Eğer şu anki plugin devre dışı bırakılmak isteniyorsa, işlemi engelle
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'deactivate' && isset( $_GET['plugin'] ) && $_GET['plugin'] === $plugin_path ) {
        wp_die( 'Bu plugin devre dışı bırakılamaz.' );
    }
}

add_action('admin_init', 'block_plugin_deactivation');
function block_plugin_deactivation() {
    // Plugin'in dosya yolunu kontrol et
    $plugin_file = plugin_basename( __FILE__ );

    // Eğer deaktif etmeye çalışan kişi yönetici ise, engelle
    if ( is_admin() && isset($_GET['action']) && $_GET['action'] == 'deactivate' && isset($_GET['plugin']) && $_GET['plugin'] == $plugin_file ) {
        wp_die( 'Bu plugin devre dışı bırakılamaz!' );
    }
}

