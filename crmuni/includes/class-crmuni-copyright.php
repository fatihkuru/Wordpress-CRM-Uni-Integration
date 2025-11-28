<?php

class CRMunI_Copyright {

    // Footer'a Copyright Metni Ekleme
    public function add_copyright_text() {
        // Footer'da mevcut bir copyright var mı kontrol et
        $footer_html = $this->get_footer_html();
        if ($this->has_copyright($footer_html)) {
            // Eğer varsa, mevcut copyright metnini güncelle
            echo $this->handle_existing_copyright($footer_html);
            $this->add_copyright_to_page();
        } else {
            // Yoksa, sayfada copyright metni ekle
            $this->add_copyright_to_page();
        }
    }

    // Footer HTML içeriğini al
    private function get_footer_html() {
        if (locate_template('footer.php')) {
            ob_start();
            get_footer(); // Footer'ı tamponla al
            return ob_get_clean();
        }
        return '';
    }

    // Footer'da copyright var mı kontrol et
    private function has_copyright($footer_html) {
        return strpos($footer_html, 'id="uni-copyright"') !== false || 
               strpos($footer_html, 'class="copyright-footer"') !== false || 
               strpos($footer_html, 'class="copyright"') !== false || 
               strpos($footer_html, 'class="copy"') !== false || 
               strpos($footer_html, '©') !== false;
    }

    // Mevcut copyright elemanını işle
    private function handle_existing_copyright($footer_html) {
        if (strpos($footer_html, 'univerco.com.tr') !== false) {
            return ''; // Değiştirilmemesi gereken bir alan
        }
        return $this->get_updated_copyright(); // SEO uyumlu link ekle
    }

    // Sayfa içeriğine copyright metni ekle
    private function add_copyright_to_page() {
        if (strpos($this->get_page_content(), 'copyright') === false) {
            echo '<div class="page-copyright">' . $this->get_updated_copyright() . '</div>';
        }
    }

    // Sayfa içeriğini al
    private function get_page_content() {
        global $wp_query;
        return is_singular() ? get_post_field('post_content', $wp_query->get_queried_object_id()) : get_option('footer_text', '');
    }

    // SEO uyumlu olarak copyright metnini oluştur
    private function get_updated_copyright() {
        $site_name = get_bloginfo('name');
        $current_year = date('Y');
        $url = 'https://univerco.com.tr';
        return '&copy; ' . $current_year . ' ' . $site_name . ' | Programming and design with ❤️ by <img loading="lazy" decoding="async" class="wp-image-4354" src="https://api.univerco.com.tr/img/univerco-icon-300x169.png" alt="Univerco" width="30" height="17" srcset="https://api.univerco.com.tr/img/univerco-icon-300x169.png 300w, https://api.univerco.com.tr/img/univerco-icon.png 512w" sizes="(max-width: 30px) 100vw, 30px" style="display: inline;"><a href="' . esc_url($url) . '" target="_blank">Univerco</a>';
    }
}
