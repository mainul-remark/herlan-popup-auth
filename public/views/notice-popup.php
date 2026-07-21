<?php
defined( 'ABSPATH' ) || exit;
/** @var array $notice Provided by Auth_Popup_Notice_Manager::render_notice() */
?>

<!-- Auth Popup Notice Overlay (shown on "Continue as Guest") -->
<div id="ap-notice-overlay" class="apn-overlay" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Notice', 'auth-popup' ); ?>" style="display:none;">
    <div class="apn-mask"></div>
    <div class="apn-dialog" role="document">
        <button type="button" class="apn-close" id="apn-close-btn" aria-label="<?php esc_attr_e( 'Close', 'auth-popup' ); ?>">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        </button>

        <?php if ( $notice['image'] ) : ?>
            <img class="apn-image" src="<?php echo esc_url( $notice['image'] ); ?>" alt="<?php echo esc_attr( $notice['title'] ); ?>">
        <?php endif; ?>

        <?php if ( $notice['title'] ) : ?>
            <h3 class="apn-title"><?php echo esc_html( $notice['title'] ); ?></h3>
        <?php endif; ?>

        <?php if ( $notice['message'] ) : ?>
            <div class="apn-description"><?php echo wp_kses_post( $notice['message'] ); ?></div>
        <?php endif; ?>

        <button type="button" class="ap-btn ap-btn-primary apn-login-btn" id="apn-login-btn">
            <?php esc_html_e( 'Login / Register', 'auth-popup' ); ?>
        </button>
    </div>
</div>
