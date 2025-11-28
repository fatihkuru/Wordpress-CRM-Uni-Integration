<?php
class CRMunI {

    // Sınıfı başlat ve filtreyi ekle
    public function __construct() {
        // Filtreyi ekle
        add_filter('admin_footer_text', [$this, 'modify_admin_footer_text']);
    }

    // Yönetim paneli footer metnini düzenleme
    public function modify_admin_footer_text($footer_text) {
        $custom_text = '<a href="https://www.univerco.com.tr" target="_blank">Univerco.com.tr</a> yönetim paneline hoş geldiniz.';
        return $footer_text . ' | ' . $custom_text;
    }

}
?>
