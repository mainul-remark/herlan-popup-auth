<?php
/**
 * Checkout Form — Auth Popup override
 *
 * Desktop (≥993px): #customer_details floats left; #order_review_heading +
 *   #order_review + #payment float right — standard two-column layout.
 *
 * Mobile (<993px): CSS flex-order restores the original flow:
 *   Your order heading → order table → shipping form → payment.
 *
 * Based on WooCommerce template: checkout/form-checkout.php
 * WooCommerce version: 9.4.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_checkout_form', $checkout );

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
    echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
    return;
}
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">

    <?php if ( $checkout->get_checkout_fields() ) : ?>

        <?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

        <div class="col2-set" id="customer_details">
            <div class="col-1">
                <?php do_action( 'woocommerce_checkout_billing' ); ?>
            </div>
            <div class="col-2">
                <?php do_action( 'woocommerce_checkout_shipping' ); ?>
            </div>
        </div>

        <?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

    <?php endif; ?>

    <?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>

    <h3 id="order_review_heading"><?php esc_html_e( 'Your order', 'woocommerce' ); ?></h3>

    <?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

    <div id="order_review" class="woocommerce-checkout-review-order">
        <?php
        /**
         * Render only the order table here (priority 10).
         * Payment (priority 20) is rendered separately below so it can be
         * positioned correctly on both desktop and mobile.
         */
        remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
        do_action( 'woocommerce_checkout_order_review' );
        add_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
        ?>
    </div>

    <?php woocommerce_checkout_payment(); ?>

    <?php do_action( 'woocommerce_checkout_after_order_review' ); ?>

</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
