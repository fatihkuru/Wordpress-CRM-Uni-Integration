<?php
class CRMuni_CF7_Integration {
    private $api;

    public function __construct($api = null) {
        $this->api = $api;

        if ($this->is_cf7_active()) {
            add_action('wpcf7_mail_sent', array($this, 'send_cf7_to_crmuni'));
            add_action('admin_menu', [$this, 'add_admin_menu']);
        }
    }

    private function is_cf7_active() {
        return defined('WPCF7_VERSION');
    }

    public function add_admin_menu() {
        add_submenu_page(
            'crmuni_settings',
            'Contact Form 7 Eşleme',
            'CF7 Eşleme',
            'manage_options',
            'crmuni_cf7_options',
            [$this, 'cf7_mapping_page']
        );
    }

    public function create_mapping_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crmuni_cf7_mapping';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            form_id bigint(20) NOT NULL,
            form_tag_name varchar(191) NOT NULL,
            api_field_name varchar(191) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY form_tag_unique (form_id, form_tag_name)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function send_cf7_to_crmuni($contact_form) {
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) return;

        $data = $submission->get_posted_data();
        $lead_data = $this->map_form_to_lead_data($data, $contact_form);

        if (!$this->api || !method_exists($this->api, 'send_lead_to_api')) {
            error_log('CRMuni API object not provided or send_lead_to_api not available.');
            return;
        }

        $response = $this->api->send_lead_to_api($lead_data);

        if (isset($response['status']) && !$response['status']) {
            error_log('CRMuni API Error: ' . (isset($response['message']) ? $response['message'] : 'Unknown error'));
        } else {
            error_log('CRMuni API Success: ' . json_encode($response));
        }
    }

    private function map_form_to_lead_data($data, $contact_form) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crmuni_cf7_mapping';

        $insert_data = [];
        $custom_fields = [];
        $unmapped_lines = [];

        foreach ($data as $tag => $value) {
            if (is_array($value)) {
                $value_str = implode(', ', array_map('sanitize_text_field', $value));
            } else {
                $value_str = sanitize_text_field($value);
            }

            if ($value_str === '') continue; // boş değerleri atla

            $api_field = $this->get_mapped_api_field_for_form($contact_form->id(), $tag);

            if ($api_field) {
                if (strpos($api_field, 'custom:') === 0) {
                    // Custom field - ID formatında
                    $field_id = substr($api_field, 7); // "custom:" prefix'ini kaldır → ID kalır
                    $custom_fields[$field_id] = $value_str; // ID'yi key olarak kullan
                } else {
                    // Normal alan
                    $insert_data[$api_field] = $value_str;
                }
            } else {
                // Mapping yoksa description alt satırına ekle
                $unmapped_lines[] = $tag . ': ' . $value_str;
            }
        }

        if (!empty($custom_fields)) {
            // Perfex formatında custom_fields gönder: ['leads' => ['field_slug' => 'value']]
            $insert_data['custom_fields'] = [
                'leads' => $custom_fields
            ];
        }

        if (!empty($unmapped_lines)) {
            $desc = implode("\n", $unmapped_lines);
            if (!isset($insert_data['description'])) {
                $insert_data['description'] = $desc;
            } else {
                $insert_data['description'] .= "\n" . $desc;
            }
        }

        // Opsiyonel sabit değerler (source, status)
        if (!isset($insert_data['source'])) $insert_data['source'] = 4;
        if (!isset($insert_data['status'])) $insert_data['status'] = 2;

        return $insert_data;
    }    
    
    private function get_mapped_api_field_for_form($form_id, $tag_name) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crmuni_cf7_mapping';
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT api_field_name FROM $table_name WHERE form_id = %d AND form_tag_name = %s",
            intval($form_id),
            sanitize_text_field($tag_name)
        ));
        return $result ? $result : '';
    }

    public function cf7_mapping_page() {
        if (!class_exists('WPCF7_ContactForm')) {
            echo '<p>Contact Form 7 yüklü değil.</p>';
            return;
        }

        // Kaydetme işlemi
        if (isset($_POST['save_mapping']) && isset($_POST['mapping']) && isset($_POST['form_id'])) {
            if (!isset($_POST['_crmuni_cf7_map_nonce']) || !wp_verify_nonce($_POST['_crmuni_cf7_map_nonce'], 'crmuni_cf7_map_action')) {
                echo '<div class="error notice is-dismissible"><p>Güvenlik doğrulaması başarısız.</p></div>';
            } else {
                $this->save_mapping(intval($_POST['form_id']), $_POST['mapping']);
            }
        }

        $forms = WPCF7_ContactForm::find();

        echo '<div class="wrap">';
        echo '<h1>CF7 → Perfex CRM Eşleme</h1>';
        echo '<p class="description">Contact Form 7 formlarınızdaki alanları Perfex CRM lead alanlarıyla eşleştirin.</p>';

        if (!empty($forms)) {
            // Custom fields listesini API'den çek
            $custom_fields = [];
            if ($this->api) {
                $custom_fields = $this->api->get_custom_fields('leads');
            }

            // Her form için eşleme
            foreach ($forms as $form) {
                echo '<div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">';
                echo '<h2 style="margin-top: 0;">' . esc_html($form->title()) . ' <span style="color: #666; font-size: 14px;">(ID: ' . esc_html($form->id()) . ')</span></h2>';

                // Form tag'lerini çıkar
                $form_props = $form->get_properties();
                $content = isset($form_props['form']) ? $form_props['form'] : '';
                $tags = [];

                if (class_exists('WPCF7_FormTagsManager') && method_exists('WPCF7_FormTagsManager', 'get_instance')) {
                    $tags = WPCF7_FormTagsManager::get_instance()->scan($content);
                }
                if (empty($tags)) {
                    preg_match_all('/\[(\w+)/', $content, $m);
                    if (!empty($m[1])) {
                        foreach ($m[1] as $n) {
                            $obj = new stdClass();
                            $obj->name = $n;
                            $tags[] = $obj;
                        }
                    }
                }

                if (!empty($tags)) {
                    echo '<form method="post" action="">';
                    wp_nonce_field('crmuni_cf7_map_action', '_crmuni_cf7_map_nonce');
                    echo '<input type="hidden" name="form_id" value="'. esc_attr($form->id()) .'">';

                    echo '<table class="wp-list-table widefat fixed striped">';
                    echo '<thead>';
                    echo '<tr>';
                    echo '<th style="width: 200px;">Form Alanı</th>';
                    echo '<th>Perfex CRM Alanı</th>';
                    echo '</tr>';
                    echo '</thead>';
                    echo '<tbody>';

                    foreach ($tags as $tag) {
                        $tag_name = isset($tag->name) ? $tag->name : '';
                        if (empty($tag_name)) continue;

                        $current_value = $this->get_mapped_api_field_for_form($form->id(), $tag_name);

                        echo '<tr>';
                        echo '<td><strong>'. esc_html($tag_name) .'</strong></td>';
                        echo '<td>';

                        // Tek dropdown ile hem standard hem custom fields
                        echo '<select name="mapping['. esc_attr($tag_name) .']" style="width: 100%; max-width: 500px;">';
                        echo '<option value="">-- Eşleme Yok --</option>';

                        // Standard fields grubu
                        echo '<optgroup label="Standart Alanlar">';
                        $standard_fields = [
                            'name' => 'İsim (zorunlu)',
                            'email' => 'E-posta',
                            'phonenumber' => 'Telefon',
                            'company' => 'Şirket',
                            'address' => 'Adres',
                            'city' => 'Şehir',
                            'state' => 'İl/Eyalet',
                            'zip' => 'Posta Kodu',
                            'country' => 'Ülke',
                            'description' => 'Açıklama',
                            'website' => 'Website'
                        ];

                        foreach ($standard_fields as $field_key => $field_label) {
                            $selected = ($current_value === $field_key) ? 'selected' : '';
                            echo '<option value="'. esc_attr($field_key) .'" '. $selected .'>'. esc_html($field_label) .'</option>';
                        }
                        echo '</optgroup>';

                        // Custom fields grubu
                        if (!empty($custom_fields)) {
                            echo '<optgroup label="Custom Fields (Özel Alanlar)">';
                            foreach ($custom_fields as $field_id => $field_info) {
                                $option_value = 'custom:' . $field_id;
                                $selected = ($current_value === $option_value) ? 'selected' : '';
                                echo '<option value="'. esc_attr($option_value) .'" '. $selected .'>';
                                echo esc_html($field_info['label']) . ' (ID: '. esc_html($field_id) .')';
                                echo '</option>';
                            }
                            echo '</optgroup>';
                        }

                        echo '</select>';
                        echo '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody>';
                    echo '</table>';

                    echo '<p class="submit" style="padding-left: 0;">';
                    echo '<input type="submit" name="save_mapping" class="button button-primary" value="Kaydet">';
                    echo '</p>';
                    echo '</form>';
                } else {
                    echo '<p style="color: #d63638;">Bu formda eşleştirilecek alan bulunamadı.</p>';
                }

                echo '</div>'; // form container
            }

            if (empty($custom_fields) && $this->api) {
                echo '<div class="notice notice-warning" style="margin: 20px 0;">';
                echo '<p><strong>Uyarı:</strong> Custom fields yüklenemedi. Perfex CRM API bağlantınızı kontrol edin.</p>';
                echo '</div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Contact Form 7 formu bulunamadı.</p></div>';
        }

        echo '</div>'; // wrap
    }

    private function save_mapping($form_id, $mapping) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crmuni_cf7_mapping';
        foreach ($mapping as $tag => $slug) {
            $tag_s = sanitize_text_field($tag);
            $slug_s = sanitize_text_field($slug);
            if (!empty($slug_s)) {
                $wpdb->replace(
                    $table_name,
                    [
                        'form_id' => intval($form_id),
                        'form_tag_name' => $tag_s,
                        'api_field_name' => $slug_s
                    ],
                    ['%d','%s','%s']
                );
            } else {
                $wpdb->delete(
                    $table_name,
                    [
                        'form_id' => intval($form_id),
                        'form_tag_name' => $tag_s
                    ],
                    ['%d','%s']
                );
            }
        }
        echo '<div class="updated notice is-dismissible"><p>Eşlemeler kaydedildi.</p></div>';
    }
}