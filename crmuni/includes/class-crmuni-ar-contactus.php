<?php


if (!class_exists('CRMuni_Ar_Contactus')) {
    class CRMuni_Ar_Contactus {
        private $api;
    
        public function __construct($api = null) {
            $this->api = $api;
            crmuni_debug_log('CRMuni_Ar_Contactus sınıfı başlatılıyor.');

            // ArContactUsPerfex sınıfını geçersiz kılıyoruz
            add_action('init', [$this, 'override_new_lead']);
            add_action('plugins_loaded', [$this, 'override_new_lead']);
        }

        /**
         * ArContactUsPerfex newLead metodunu geçersiz kılar.
         */
        public function override_new_lead() {
            crmuni_debug_log('override_new_lead metodu çalıştırılıyor.');

            if (class_exists('ArContactUsPerfex')) {
                crmuni_debug_log('ArContactUsPerfex sınıfı bulundu.');

                // Mevcut newLead metodunu kaldır
                remove_action('ar_contactus_newlead_action', [ArContactUsPerfex::class, 'newLead'],10);
                crmuni_debug_log('Mevcut newLead metodu devre dışı bırakıldı.');

                // Yeni newLead metodunu ekle
                add_action('ar_contactus_newlead_action', [$this, 'newLead'],11);
                
                crmuni_debug_log('Yeni newLead metodu eklendi.');
            } else {
                crmuni_debug_log('Hata: ArContactUsPerfex sınıfı bulunamadı.');
            }
        }

        /**
         * Yeni newLead fonksiyonu, gelen verileri CRMuni API'sine gönderir.
         */
        public function newLead($lead_data) {
            crmuni_debug_log('newLead metodu çağrıldı. Gelen veriler: ' . print_r($lead_data, true));

            if (!$this->api) {
                crmuni_debug_log('Hata: CRMuniAPI örneği oluşturulamadı.');
                return 'CRM bağlantısı kurulamadı.';
            }

            // Gerekli alanların kontrolü
            if (empty($lead_data['name'])) {
                crmuni_debug_log('Hata: Lead ismi eksik.');
                return 'Lead ismi zorunludur.';
            }

            crmuni_debug_log('Lead verileri kontrol edildi, API çağrısı yapılıyor.');

            // API'ye lead gönder
            try {
                $response = $this->api->send_lead_to_api($lead_data);
                crmuni_debug_log('API yanıtı alındı: ' . print_r($response, true));
            } catch (Exception $e) {
                crmuni_debug_log('Hata: API çağrısı başarısız oldu. Mesaj: ' . $e->getMessage());
                return 'API çağrısı sırasında bir hata oluştu.';
            }

            // Yanıtı döndür
            return $response;
        }
    }
}