<?php
class CRMuniAPI
{
    private $api_url;
    private $api_key;

    public function __construct() {
        $this->api_url = get_option('crmuni_api_url');
        $this->api_key = get_option('crmuni_api_key');
        //$this->create_lead($data);
        if (empty($this->api_url) || empty($this->api_key)) {
            crmuni_debug_log('CRMuniAPI: API URL veya API Anahtarı eksik.');
            return; // Yapıcıda hata varsa devam etme
        }
    }

    /**
     * Perfex CRM'den leads için custom fields listesini çeker
     * @return array Custom fields listesi [id => name]
     */
    public function get_custom_fields($module = 'leads') {
        crmuni_debug_log('get_custom_fields başladı, modül: ' . $module);

        // Önce cache'e bakalım (1 saat)
        $cache_key = 'crmuni_custom_fields_' . $module;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            crmuni_debug_log('Custom fields cache\'den alındı');
            return $cached;
        }

        // Perfex API'den custom fields endpoint'ine istek
        // Not: Perfex API'de direkt custom fields endpoint'i yoksa,
        // bir lead çekip oradaki custom fields yapısını kullanabiliriz
        $endpoint = rtrim($this->api_url, '/') . '/api/leads?limit=1';

        $response = $this->crmuni_curl_request($endpoint, 'GET', [], $this->api_key, true);

        $custom_fields = [];

        if (!empty($response) && is_array($response)) {
            $lead = $response[0] ?? null;

            if ($lead && isset($lead['customfields'])) {
                // Perfex'te custom fields şu formatta gelir:
                // customfields[field_id] = ['value' => 'x', 'label' => 'Field Name', ...]
                foreach ($lead['customfields'] as $field_id => $field_data) {
                    if (is_array($field_data) && isset($field_data['label'])) {
                        $custom_fields[$field_id] = [
                            'id' => $field_id,
                            'label' => $field_data['label'],
                            'slug' => $field_data['slug'] ?? 'field_' . $field_id,
                            'type' => $field_data['type'] ?? 'input'
                        ];
                    }
                }
            }
        }

        // Cache'e kaydet (1 saat)
        if (!empty($custom_fields)) {
            set_transient($cache_key, $custom_fields, HOUR_IN_SECONDS);
            crmuni_debug_log('Custom fields cache\'e kaydedildi: ' . count($custom_fields) . ' alan');
        }

        return $custom_fields;
    }

    public function send_lead_to_api($lead_data)
    {
        crmuni_debug_log('send_lead_to_api başladı.');

        // DNS çözümlemesini kontrol et (sadece warning, engelleme yok)
        if (!$this->test_dns_resolution()) {
            crmuni_debug_log('UYARI: DNS çözümleme başarısız ama devam ediliyor...');
        }

        // Zorunlu alanları otomatik doldur
        if (empty($lead_data['name'])) {
            // Name yoksa başka alanlardan oluştur
            if (!empty($lead_data['company'])) {
                $lead_data['name'] = $lead_data['company'];
            } elseif (!empty($lead_data['email'])) {
                $lead_data['name'] = $lead_data['email'];
            } elseif (!empty($lead_data['phonenumber'])) {
                $lead_data['name'] = $lead_data['phonenumber'];
            } else {
                $lead_data['name'] = 'Lead ' . date('Y-m-d H:i:s');
            }
            crmuni_debug_log('Name otomatik oluşturuldu: ' . $lead_data['name']);
        }

        if (empty($lead_data['source'])) {
            $lead_data['source'] = DEFAULT_NEW_LEAD_SOURCE_ID;
        }

        if (empty($lead_data['status'])) {
            $lead_data['status'] = DEFAULT_NEW_LEAD_STATUS_ID;
        }

        crmuni_debug_log('Lead Data Hazır: ' . print_r($lead_data, true));

        $oiriginphone = $lead_data['phonenumber'] ?? null;
        $phone = preg_replace('/[^0-9]/', '', $oiriginphone);
        
        $email = $lead_data['email'] ?? null;

        crmuni_debug_log('Telefon: ' . ($phone ?? 'Yok') . ', E-posta: ' . ($email ?? 'Yok'));

        $existing_lead = null;
        if ($phone) {
            crmuni_debug_log('Telefon ile lead aranıyor.');
            $existing_lead = $this->search_lead($phone);
            crmuni_debug_log($existing_lead);

        }

        if (!$existing_lead && $email) {
            crmuni_debug_log('E-posta ile lead aranıyor.');
            $existing_lead = $this->search_lead($email);
            crmuni_debug_log($existing_lead);
        }

        $tags = $this->generate_tags();

        if ($existing_lead && isset($existing_lead['id'])) {
            $lead_id = $existing_lead['id'];
            crmuni_debug_log("Mevcut lead bulundu, ID: $lead_id");

            $update_data = $this->prepare_update_data($lead_data, $tags, $existing_lead);
            crmuni_debug_log($update_data);

            $update_result = $this->update_lead($lead_id, $update_data);
            return $update_result ? "Lead güncellendi: $lead_id" : "Lead güncelleme başarısız!";
        } else {
            crmuni_debug_log("Lead bulunamadı, yeni lead oluşturuluyor.");
            $lead_data['tags'] = implode(', ', $tags);
            $create_result = $this->create_lead($lead_data);
            return $create_result ? "Yeni lead oluşturuldu: " . ($create_result['id'] ?? 'Unknown ID') : "Yeni lead oluşturma başarısız!";
        }
    }

    private function update_lead($id, $update_data)
    {
        crmuni_debug_log("update_lead başladı, ID: $id");

        $endpoint = rtrim($this->api_url, '/') . '/api/leads/' . $id;

        crmuni_debug_log('PUT isteği gönderiliyor: ' . $endpoint);

        $response = $this->crmuni_curl_request($endpoint,'PUT',$update_data,$this->api_key,true);

        return !empty($response) && is_array($response) ? $response[0] : null;
    }

    private function search_lead($keysearch)
    {
        crmuni_debug_log("search_lead Start, anahtar: $keysearch");
    
        $endpoint = rtrim($this->api_url, '/') . '/api/leads/search/' . urlencode($keysearch);
        $headers = array(
            'authtoken' => $this->api_key,
        );
    
        // API_IP sabiti tanımlıysa, IP adresi üzerinden doğrudan bağlantı yapalım
        if (defined('API_IP') && defined('API_PORT')) {
            $host = API_HOST;
            $ip_address = API_IP;  // API'nin IP adresi
            $port = API_PORT; // Port numarası
        } else {
            $host = parse_url($this->api_url, PHP_URL_HOST);
            $ip_address = gethostbyname($host);
            $port = parse_url($this->api_url, PHP_URL_PORT) ?: 443;  // Varsayılan port 80 (http) ya da 443 (https)
        }
        
        $response = $this->crmuni_curl_request($endpoint,'GET',$data,$this->api_key,true);
    
        if (is_wp_error($response)) {
            crmuni_debug_log('GET isteği hatası: ' . $response->get_error_message());
            return null;
        }
        crmuni_debug_log("search_lead End------------------------------");

        return !empty($response) && is_array($response) ? $response[0] : null;
    }
    
    private function create_lead($lead_data)
    {
        crmuni_debug_log('create_lead başladı.');
        crmuni_debug_log($lead_data);
    
        $endpoint = rtrim($this->api_url, '/') . '/api/leads';

        crmuni_debug_log('POST isteği gönderiliyor: ' . $endpoint);
        
        $response = $this->crmuni_curl_request($endpoint,'POST',$lead_data,$this->api_key,true);

        crmuni_debug_log('Gönderilen Veri: ' . $lead_data);

        return !empty($response) && is_array($response) ? $response[0] : null;
    
    }
    
    private function generate_tags()
    {
        $tags = array('crmuniapi');

        $referer = $_SERVER['HTTP_REFERER'] ?? 'unknown';

        $parsed_url = parse_url($referer);
        $domain = $parsed_url['host'] ?? 'unknown';
        $path = $parsed_url['path'] ?? 'unknown';

        $query_params = [];
        if (isset($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_params);
        }

        $tags[] = 'domain:' . $domain;
        $tags[] = 'path:' . $path;

        if (isset($query_params)) {
            foreach ($query_params as $key => $value) {
                $tags[] = $key . ':' . $value;
            }
        } else {
            crmuni_debug_log('GET parametreleri bulunamadı.');
        }

        crmuni_debug_log('Tags oluşturuldu: ' . implode(', ', $tags));

        return $tags;
    }

    private function prepare_update_data($lead_data, $tags, $existing_lead)
    {
        $exclude_fields = array('customfields', 'form_id');

        $update_data = $existing_lead;

        foreach ($exclude_fields as $field) {
            unset($update_data[$field]);
        }

        foreach ($lead_data as $key => $value) {
            if (!empty($value) && !in_array($key, $exclude_fields) && array_key_exists($key, $existing_lead)) {
                $update_data[$key] = $value;
            }
        }

        $update_data['tags'] = implode(', ', $tags);
        $lead_data['tags'] = $update_data['tags'];
        $new_description = $this->build_description($existing_lead['description'] ?? '', $lead_data);
        $update_data['description'] = $new_description;
        $update_data['status'] = DEFAULT_NEW_LEAD_STATUS_ID; 
        return $update_data;
    }

    private function build_description($current_description, $lead_data)
    {
        $new_description = '';

        if ($current_description && empty($new_description)) {
            $new_description .= $this->format_lead_data($current_description) . "</br>";
        }

        $current_date_time = date("d.m.Y H:i"); 

        $new_description .= "----(" . $current_date_time . ")----</br>";
        $new_description .= $this->format_lead_data($lead_data);

        crmuni_debug_log('Description oluşturuldu: ' . $new_description);

        return trim($new_description);
    }

    private function format_lead_data($data)
    {
        $formatted_data = '';

        if (is_string($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if ($key != 'form_id' && $key != 'form_title') {
                $formatted_data .= "$key -> $value</br>";
            }
        }

        return $formatted_data;
    }

    private function test_dns_resolution()
    {
        if (defined('API_IP') && API_IP) {
            crmuni_debug_log("Manuel DNS Çözümleme: API IP adresi kullanılıyor: " . API_IP);
            return true;
        }

        $parsed_url = parse_url($this->api_url);
        if (!$parsed_url || !isset($parsed_url['host'])) {
            crmuni_debug_log('Geçersiz API URL\'si: ' . $this->api_url);
            return false;
        }

        $hostname = $parsed_url['host'];
        $dns_result = gethostbyname($hostname);

        if ($dns_result === $hostname) {
            crmuni_debug_log('DNS çözümlemesi başarısız: ' . $hostname);
            return false;
        }

        crmuni_debug_log("DNS çözümlemesi başarılı: " . $dns_result);
        return true;
    }
    
function crmuni_curl_requestxxxxx($url, $method = 'GET', $data = [], $token = '', $resolve = null)
{
    crmuni_debug_log('crmuni_curl_request Start -------------------------------');
    
    crmuni_debug_log('Yapı: ' . $url . ' - ' .  $method . ' - ' . json_encode($data) . ' - '. $token . ' - '. json_encode($resolve));
    
    // cURL oturumunu başlat
    $ch = curl_init($url);
    
    crmuni_debug_log('Verbose Başladı');  // cURL hatasını döndürüyoruz
    curl_setopt($ch, CURLOPT_VERBOSE, true);  // cURL detaylı çıktı
    
    // IP üzerinden çözümleme yapılacaksa
    if ($resolve) {
        crmuni_debug_log('Resolve Başladı');
        $host = API_HOST;
        $port = API_PORT;
        $ip_address = API_IP;
        
        $resolve = ["$host:$port:$ip_address"];
        curl_setopt($ch, CURLOPT_RESOLVE, $resolve);  // --resolve entegrasyonu ile IP üzerinden yönlendirme
        crmuni_debug_log("Resolve: "); // API yanıtını logla
        crmuni_debug_log($resolve); // API yanıtını logla

    }
    
    crmuni_debug_log('Options Başladı');
    // cURL seçeneklerini ayarlıyoruz
    // cURL seçeneklerini ayarlıyoruz
    $options = [
        CURLOPT_RETURNTRANSFER => true,  // Yanıtı al
        CURLOPT_TIMEOUT => 10,           // Bağlantı zaman aşımı süresi
        CURLOPT_CONNECTTIMEOUT => 10,    // Bağlantı kurulum süresi
        CURLOPT_SSL_VERIFYPEER => true, // SSL sertifikası doğrulaması
        CURLOPT_SSL_VERIFYHOST => 2,    // SSL host doğrulaması
        CURLOPT_VERBOSE => true,         // Detaylı çıktı almak için
        CURLOPT_RESOLVE => $resolve,    // --resolve entegrasyonu
        CURLOPT_HTTPHEADER => [
            'authtoken: ' . $token,     // Kimlik doğrulama başlığı
        ],
    ];

    // HTTP metoduna göre işlemi yapıyoruz
    switch (strtoupper($method)) {
        case 'GET':
            curl_setopt($ch, CURLOPT_HTTPGET, true); // GET isteği
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true); // POST isteği
            if (!empty($data)) {
                // multipart/form-data ile veri gönderimi
                $multipartData = [];
                foreach ($data as $key => $value) {
                    // Eğer veri bir dosya ise, dosya yolu belirtilebilir
                    // Örnek: "file" => "@path_to_file"
                    $multipartData[$key] = $value;
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $multipartData); // multipart/form-data verisi
            }
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'); // PUT isteği
            if (!empty($data)) {
                // multipart/form-data ile veri gönderimi
                $multipartData = [];
                foreach ($data as $key => $value) {
                    // Eğer veri bir dosya ise, dosya yolu belirtilebilir
                    // Örnek: "file" => "@path_to_file"
                    $multipartData[$key] = $value;
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $multipartData); // multipart/form-data verisi
            }
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE'); // DELETE isteği
            if (!empty($data)) {
                // multipart/form-data ile veri gönderimi
                $multipartData = [];
                foreach ($data as $key => $value) {
                    // Eğer veri bir dosya ise, dosya yolu belirtilebilir
                    // Örnek: "file" => "@path_to_file"
                    $multipartData[$key] = $value;
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $multipartData); // multipart/form-data verisi
            }
            break;
        default:
            throw new Exception('Geçersiz HTTP metodu: ' . $method);
    }

    // Ekstra seçenekleri ayarlıyoruz
    curl_setopt_array($ch, $options);

    // cURL isteğini çalıştır
    $response = curl_exec($ch);
    crmuni_debug_log('Options init Başladı');
    // Ekstra seçenekleri ayarlıyoruz
    curl_setopt_array($ch, $options);
    
    crmuni_debug_log('Curl Başladı');
    // cURL isteğini çalıştır
    $response = curl_exec($ch);

    crmuni_debug_log('Hata Başladı');
    
    // cURL hatası kontrolü
    if ($response === false) {
        // cURL hatasını döndürüyoruz
        crmuni_debug_log('cURL Hata: ' . curl_error($ch));
        curl_close($ch);
        return null; // Hata durumunda null döndür
    }

    crmuni_debug_log("API Yanıtı: " . $response); // API yanıtını logla

    // Yanıtı JSON'a dönüştür
    $response_data = json_decode($response, true);

    // JSON hata kontrolü
    if (json_last_error() !== JSON_ERROR_NONE) {
        crmuni_debug_log('JSON Hatası: ' . json_last_error_msg());
        return null;
    }

    // cURL oturumunu kapat
    curl_close($ch);
    crmuni_debug_log('crmuni_curl_request End -------------------------------');

    // JSON yanıt verisini döndür
    return $response_data;
}

/**
 * Nested array'leri multipart/form-data için flatten eder
 * Örnek: ['custom_fields' => ['leads' => ['field1' => 'val']]]
 * -> ['custom_fields[leads][field1]' => 'val']
 */
private function flatten_array($array, $prefix = '') {
    $result = [];
    foreach ($array as $key => $value) {
        $new_key = $prefix === '' ? $key : $prefix . '[' . $key . ']';
        if (is_array($value)) {
            $result = array_merge($result, $this->flatten_array($value, $new_key));
        } else {
            $result[$new_key] = $value;
        }
    }
    return $result;
}

function crmuni_curl_request($url, $method = 'GET', $data = [], $token = '', $resolve = null)
{
    crmuni_debug_log('crmuni_curl_request Start -------------------------------');
    crmuni_debug_log('Yapı: ' . $url . ' - ' . $method . ' - ' . json_encode($data) . ' - ' . $token . ' - ' . json_encode($resolve));

    // cURL oturumunu başlat
    $ch = curl_init($url);

    crmuni_debug_log('Verbose Başladı');
    curl_setopt($ch, CURLOPT_VERBOSE, true);

    // IP üzerinden çözümleme yapılacaksa
    if ($resolve) {
        crmuni_debug_log('Resolve Başladı');
        $host = API_HOST;
        $port = API_PORT;
        $ip_address = API_IP;
        
        $resolve = ["$host:$port:$ip_address"];
        curl_setopt($ch, CURLOPT_RESOLVE, $resolve);  
        crmuni_debug_log("Resolve: ");
        crmuni_debug_log($resolve);
    }
    
    crmuni_debug_log('Options Başladı');
    // cURL genel seçenekleri
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_VERBOSE => true,
        CURLOPT_HTTPHEADER => [
            'authtoken: ' . $token
        ],
    ];

    // HTTP metoduna göre işlemi yapıyoruz
    switch (strtoupper($method)) {
        case 'GET':
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                crmuni_debug_log('POST Multipart format');
                crmuni_debug_log('POST Data: ' . print_r($data, true));

                // Nested array'leri flatten et
                $post_data = $this->flatten_array($data);
                crmuni_debug_log('POST Flattened: ' . print_r($post_data, true));

                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            } else {
                crmuni_debug_log('POST Boş');
            }
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); 
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
                crmuni_debug_log(json_encode($data));
            } else {
                crmuni_debug_log('PUT Boş');
            }
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE'); 
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        default:
            throw new Exception('Geçersiz HTTP metodu: ' . $method);
    }

    // Ekstra seçenekleri ayarlıyoruz
    curl_setopt_array($ch, $options);

    crmuni_debug_log('Curl Başladı');
    // cURL isteğini çalıştır
    $response = curl_exec($ch);

    crmuni_debug_log('Hata Başladı');
    
    // cURL hatası kontrolü
    if ($response === false) {
        crmuni_debug_log('cURL Hata: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }

    crmuni_debug_log("API Yanıtı: " . $response);

    // Yanıtı JSON'a dönüştür
    $response_data = json_decode($response, true);

    // JSON hata kontrolü
    if (json_last_error() !== JSON_ERROR_NONE) {
        crmuni_debug_log('JSON Hatası: ' . json_last_error_msg());
        return null;
    }

    // cURL oturumunu kapat
    curl_close($ch);
    crmuni_debug_log('crmuni_curl_request End -------------------------------\n\n\n');

    return $response_data;
}

}