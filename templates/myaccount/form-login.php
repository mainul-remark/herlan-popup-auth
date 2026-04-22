<?php
defined( 'ABSPATH' ) || exit;

if ( is_user_logged_in() ) {
    return;
}

require AUTH_POPUP_PATH . 'public/views/inline-form.php';
