<?php defined( 'ABSPATH' ) || exit; ?>
<div id="ap-checkout-email-verify" class="ap-checkout-verify-box" data-email="<?php echo esc_attr( $user->user_email ); ?>">
    <p class="ap-checkout-verify-msg">
        <?php
        printf(
            /* translators: %s: account email address */
            esc_html__( 'Please verify your account email (%s) before placing your order.', 'auth-popup' ),
            '<strong>' . esc_html( $user->user_email ) . '</strong>'
        );
        ?>
    </p>

    <button type="button" class="ap-btn ap-btn-ghost" id="ap-checkout-send-code">
        <?php esc_html_e( 'Send Verification Code', 'auth-popup' ); ?>
    </button>

    <div class="ap-otp-inputs" id="ap-checkout-otp-inputs" style="display:none;">
        <?php for ( $i = 0; $i < 6; $i++ ) : ?>
            <input type="tel" class="ap-otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric">
        <?php endfor; ?>
    </div>
    <input type="hidden" id="ap-checkout-otp-code">

    <div class="ap-otp-timer" id="ap-checkout-otp-timer" style="display:none;">
        <span class="ap-timer-text"></span>
        <button type="button" class="ap-resend-btn ap-link" id="ap-checkout-resend-btn" style="display:none;"><?php esc_html_e( 'Resend Code', 'auth-popup' ); ?></button>
    </div>

    <button type="button" class="ap-btn ap-btn-primary" id="ap-checkout-verify-code-btn" style="display:none;">
        <?php esc_html_e( 'Verify', 'auth-popup' ); ?>
    </button>
</div>
