<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Zorunlu eklenti olduğu için, burada sadece kullanıcıya bildirimde bulunabilirsiniz
function prevent_plugin_removal() {
    if ( current_user_can( 'activate_plugins' ) ) {
        wp_die( 'UNI CRM modülü zorunludur ve kaldırılamaz.' );
    }
}

add_action( 'admin_init', 'prevent_plugin_removal' );
