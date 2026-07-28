<?php defined( 'ABSPATH' ) || exit; ?>
<div id="ap-checkout-email-verify" class="ap-checkout-verify-bar" data-email="<?php echo esc_attr( $user->user_email ); ?>">
    <p class="ap-checkout-verify-msg">
        <?php
        printf(
            /* translators: %s: account email address */
            esc_html__( 'Please verify your account email (%s) to enable "Pay Later" payment method.', 'auth-popup' ),
            '<strong>' . esc_html( $user->user_email ) . '</strong>'
        );
        ?>
        <?php if ( ! empty( $feature_policy ) && '' !== trim( wp_strip_all_tags( $feature_policy ) ) ) : ?>
            <button type="button" class="ap-policy-info-btn" id="ap-pay-later-policy-btn" aria-label="<?php esc_attr_e( 'View Pay Later policy', 'auth-popup' ); ?>">?</button>
        <?php endif; ?>
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

    <button type="button" class="ap-checkout-verify-close" id="ap-checkout-verify-close" aria-label="<?php esc_attr_e( 'Dismiss', 'auth-popup' ); ?>">&times;</button>
</div>

<?php if ( ! empty( $feature_policy ) && '' !== trim( wp_strip_all_tags( $feature_policy ) ) ) : ?>
<div id="ap-pay-later-policy-overlay" class="ap-policy-overlay" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Pay Later Policy', 'auth-popup' ); ?>">
    <div class="ap-policy-mask" data-ap-policy-close></div>
    <div class="ap-policy-dialog" role="document">
        <button type="button" class="ap-policy-close" data-ap-policy-close aria-label="<?php esc_attr_e( 'Close', 'auth-popup' ); ?>">&times;</button>
        <h3 class="ap-policy-title"><?php esc_html_e( 'Pay Later Policy', 'auth-popup' ); ?></h3>
        <div class="ap-policy-content"><?php echo wp_kses_post( $feature_policy ); ?></div>
    </div>
</div>
<style>
.ap-policy-info-btn{vertical-align:middle;margin-left:5px;width:16px;height:16px;border-radius:50%;border:1px solid rgba(255,255,255,.6);background:transparent;color:#fff;font-size:.62rem;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:0;}
.ap-policy-info-btn:hover{background:rgba(255,255,255,.15);}
.ap-policy-overlay{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;padding:20px;}
.ap-policy-overlay.is-open{display:flex;}
.ap-policy-mask{position:absolute;inset:0;background:rgba(0,0,0,.6);}
.ap-policy-dialog{position:relative;background:#fff;border-radius:12px;max-width:600px;width:100%;max-height:80vh;overflow-y:auto;padding:28px 24px 24px;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.ap-policy-close{position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#666;font-size:1.3rem;line-height:1;padding:6px;}
.ap-policy-close:hover{color:#000;}
.ap-policy-title{margin:0 0 12px;font-size:18px;font-weight:700;color:#111;}
.ap-policy-content{font-size:14px;line-height:1.6;color:#333;}
.ap-policy-content p:last-child{margin-bottom:0;}
</style>
<script>
(function () {
    var btn     = document.getElementById( 'ap-pay-later-policy-btn' );
    var overlay = document.getElementById( 'ap-pay-later-policy-overlay' );
    if ( ! btn || ! overlay ) { return; }

    btn.addEventListener( 'click', function () {
        overlay.classList.add( 'is-open' );
    } );

    overlay.querySelectorAll( '[data-ap-policy-close]' ).forEach( function ( el ) {
        el.addEventListener( 'click', function () {
            overlay.classList.remove( 'is-open' );
        } );
    } );

    document.addEventListener( 'keydown', function ( e ) {
        if ( 'Escape' === e.key ) { overlay.classList.remove( 'is-open' ); }
    } );
})();
</script>
<?php endif; ?>

<script>
(function () {
    try {
        if ( sessionStorage.getItem( 'ap_checkout_verify_dismissed' ) === '1' ) {
            var el = document.getElementById( 'ap-checkout-email-verify' );
            if ( el ) { el.style.display = 'none'; }
        }
    } catch ( e ) {}
})();
</script>
