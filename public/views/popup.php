<?php
defined( 'ABSPATH' ) || exit;
$s          = get_option( 'auth_popup_settings', Auth_Popup_Core::default_settings() );
$brand      = esc_html( $s['popup_brand_name'] ?? get_bloginfo('name') );
$logo_url   = esc_url( $s['popup_logo_url'] ?? '' );
$en_pass    = ! empty( $s['enable_password_login'] );
$en_otp     = ! empty( $s['enable_otp_login'] );
$en_google  = ! empty( $s['enable_google'] );
$en_fb      = ! empty( $s['enable_facebook'] );
$en_loyalty = ! empty( $s['loyalty_enabled'] );
?>

<!-- Auth Popup Overlay -->
<div id="auth-popup-overlay" class="ap-overlay" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Login or Register', 'auth-popup' ); ?>" style="display:none;">
    <div class="ap-mask"></div>

    <div class="ap-dialog" role="document">

        <!-- Close -->
        <button class="ap-close" id="ap-close-btn" aria-label="<?php esc_attr_e( 'Close', 'auth-popup' ); ?>">
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M1 1l16 16M17 1L1 17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>

        <!-- Logo / Brand -->
        <div class="ap-brand">
            <?php if ( $logo_url ) : ?>
                <img src="<?php echo $logo_url; ?>" alt="<?php echo $brand; ?>" class="ap-logo">
            <?php else : ?>
                <span class="ap-brand-text"><?php echo $brand; ?></span>
            <?php endif; ?>
        </div>

        <!-- Main Tabs: Login | Register -->
        <div class="ap-tabs">
            <button class="ap-tab active"        data-tab="register"><?php esc_html_e( 'Register', 'auth-popup' ); ?></button>
            <button class="ap-tab " data-tab="login"><?php esc_html_e( 'Login',    'auth-popup' ); ?></button>
            <span class="ap-tab-indicator"></span>
        </div>

        <!-- Alert box -->
        <div class="ap-alert" id="ap-alert" style="display:none;"></div>

        <!-- ═══════════════════ LOGIN PANEL ═══════════════════ -->
        <div class="ap-panel " id="ap-panel-login">

            <?php if ( $en_pass || $en_otp ) : ?>
                <!-- Login mode tabs -->
                <div class="ap-mode-tabs" id="ap-login-mode-tabs">
                    <?php if ( $en_pass ) : ?>
                        <button class="ap-mode-tab active" data-mode="password">
                            <?php esc_html_e( 'Password', 'auth-popup' ); ?>
                        </button>
                    <?php endif; ?>
                    <?php if ( $en_otp ) : ?>
                        <button class="ap-mode-tab <?php echo $en_pass ? '' : 'active'; ?>" data-mode="otp">
                            <?php esc_html_e( 'OTP', 'auth-popup' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Login: Password -->
            <?php if ( $en_pass ) : ?>
            <form class="ap-form ap-login-password-form active" id="ap-login-password-form" data-action="auth_popup_login_password" novalidate>
                <div class="ap-field">
<!--                    <label for="ap-lp-credential">--><?php //esc_html_e( 'Mobile / Email', 'auth-popup' ); ?><!--</label>-->
                    <div class="ap-input-wrap">
                        <svg class="ap-icon" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/></svg>
                        <input type="text" id="ap-lp-credential" name="credential" placeholder="<?php esc_attr_e( 'Mobile number or email', 'auth-popup' ); ?>" autocomplete="username" required>
                    </div>
                </div>
                <div class="ap-field">
<!--                    <label for="ap-lp-password">--><?php //esc_html_e( 'Password', 'auth-popup' ); ?><!--</label>-->
                    <div class="ap-input-wrap">
                        <svg class="ap-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="1.8" fill="none"/><path d="M7 11V7a5 5 0 0110 0v4" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/></svg>
                        <input type="password" id="ap-lp-password" name="password" placeholder="<?php esc_attr_e( 'Enter password', 'auth-popup' ); ?>" autocomplete="current-password" required>
                        <button type="button" class="ap-toggle-pass" tabindex="-1">
                            <svg class="ap-eye" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.8" fill="none"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8" fill="none"/></svg>
                        </button>
                    </div>
                </div>
                <div class="ap-field-row">
                    <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" class="ap-forgot-link" tabindex="-1"><?php esc_html_e( 'Forgot password?', 'auth-popup' ); ?></a>
                </div>
                <button type="submit" class="ap-btn ap-btn-primary ap-submit-btn">
                    <?php esc_html_e( 'Login', 'auth-popup' ); ?>
                </button>
            </form>
            <?php endif; ?>

            <!-- Login: OTP -->
            <?php if ( $en_otp ) : ?>
            <form class="ap-form ap-login-otp-form <?php echo $en_pass ? '' : 'active'; ?>" id="ap-login-otp-form" novalidate>
                <!-- Step 1: Phone -->
                <div class="ap-otp-step active" data-step="1">
                    <div class="ap-field">
<!--                        <label for="ap-lo-phone">--><?php //esc_html_e( 'Mobile Number', 'auth-popup' ); ?><!--</label>-->
                        <div class="ap-input-wrap ap-phone-wrap">
                            <span class="ap-phone-prefix">+880</span>
                            <input type="tel" id="ap-lo-phone" name="phone" placeholder="<?php esc_attr_e( '01XXXXXXXXX', 'auth-popup' ); ?>" autocomplete="tel" required maxlength="11">
                        </div>
                    </div>
                    <button type="button" class="ap-btn ap-btn-primary ap-send-otp-btn" data-form="login">
                        <?php esc_html_e( 'Send OTP', 'auth-popup' ); ?>
                    </button>
                </div>
                <!-- Step 2: OTP verification -->
                <div class="ap-otp-step" data-step="2">
                    <p class="ap-otp-hint"></p>
                    <div class="ap-field">
                        <label><?php esc_html_e( 'Enter OTP', 'auth-popup' ); ?></label>
                        <div class="ap-otp-inputs">
                            <?php for ( $i = 0; $i < 6; $i++ ) : ?>
                                <input type="tel" class="ap-otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric">
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="otp" id="ap-lo-otp">
                    </div>
                    <div class="ap-otp-timer">
                        <span class="ap-timer-text"></span>
                        <button type="button" class="ap-resend-btn ap-link" data-form="login" style="display:none;"><?php esc_html_e( 'Resend OTP', 'auth-popup' ); ?></button>
                    </div>
                    <button type="submit" class="ap-btn ap-btn-primary ap-submit-btn">
                        <?php esc_html_e( 'Verify & Login', 'auth-popup' ); ?>
                    </button>
                    <button type="button" class="ap-link ap-back-btn"><?php esc_html_e( '← Change number', 'auth-popup' ); ?></button>
                </div>
            </form>
            <?php endif; ?>

            <!-- Social Login -->
            <?php if ( $en_google || $en_fb ) : ?>
            <div class="ap-divider"><span><?php esc_html_e( 'or continue with', 'auth-popup' ); ?></span></div>
            <div class="ap-social-btns">
                <?php if ( $en_google ) : ?>
                <button type="button" class="ap-btn ap-btn-social ap-btn-google" id="ap-google-btn">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                    <?php esc_html_e( 'Continue with Google', 'auth-popup' ); ?>
                </button>
                <?php endif; ?>
                <?php if ( $en_fb ) : ?>
                <button type="button" class="ap-btn ap-btn-social ap-btn-facebook" id="ap-facebook-btn">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" fill="#1877F2"/></svg>
                    <?php esc_html_e( 'Continue with Facebook', 'auth-popup' ); ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- /login panel -->

        <!-- ═══════════════════ REGISTER PANEL ════════════════════ -->
        <div class="ap-panel active" id="ap-panel-register">

            <form class="ap-form ap-register-form active" id="ap-register-form" data-action="auth_popup_register" novalidate>

                <!-- Step 1 -->
                <div class="ap-reg-step active" data-step="1">
                    <div class="ap-field">
<!--                        <label for="ap-reg-name">--><?php //esc_html_e( 'Full Name', 'auth-popup' ); ?><!--</label>-->
                        <div class="ap-input-wrap">
                            <svg class="ap-icon" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/></svg>
                            <input type="text" id="ap-reg-name" name="name" placeholder="<?php esc_attr_e( 'full name', 'auth-popup' ); ?>" autocomplete="name" required>
                        </div>
                    </div>
                    <div class="ap-field">
<!--                        <label for="ap-reg-phone">--><?php //esc_html_e( 'Mobile Number', 'auth-popup' ); ?><!--</label>-->
                        <div class="ap-input-wrap ap-phone-wrap">
                            <span class="ap-phone-prefix">+880</span>
                            <input type="tel" id="ap-reg-phone" name="phone" placeholder="<?php esc_attr_e( '01XXXXXXXXX', 'auth-popup' ); ?>" autocomplete="tel" required maxlength="11">
                        </div>
                        <span class="ap-phone-check-msg"></span>
                    </div>
                    <?php if ( $en_pass ) : ?>
                    <div class="ap-field">
<!--                        <label for="ap-reg-password">--><?php //esc_html_e( 'Password', 'auth-popup' ); ?><!--</label>-->
                        <div class="ap-input-wrap">
                            <svg class="ap-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="1.8" fill="none"/><path d="M7 11V7a5 5 0 0110 0v4" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/></svg>
                            <input type="password" id="ap-reg-password" name="password" placeholder="<?php esc_attr_e( 'Create a password', 'auth-popup' ); ?>" autocomplete="new-password" minlength="6" required>
                            <button type="button" class="ap-toggle-pass" tabindex="-1">
                                <svg class="ap-eye" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.8" fill="none"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8" fill="none"/></svg>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="ap-field">
<!--                        <label for="ap-reg-email">--><?php //esc_html_e( 'Email', 'auth-popup' ); ?><!-- <small>--><?php //esc_html_e( '(optional)', 'auth-popup' ); ?><!--</small></label>-->
                        <div class="ap-input-wrap">
                            <svg class="ap-icon" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="1.8" fill="none"/><polyline points="22,6 12,13 2,6" stroke="currentColor" stroke-width="1.8" fill="none"/></svg>
                            <input type="email" id="ap-reg-email" name="email" placeholder="<?php esc_attr_e( 'your@email.com (Optional)', 'auth-popup' ); ?>" autocomplete="email">
                        </div>
                    </div>
                    <?php if ( $en_loyalty ) : ?>
                    <!-- Loyalty Programme Checkbox -->
                    <div class="ap-loyalty-toggle">
                        <label class="ap-loyalty-check-label">
                            <input type="checkbox" id="ap-join-loyalty" name="join_loyalty" value="1" >
                            <span class="ap-loyalty-check-box"></span>
                            <span class="ap-loyalty-check-text">
                                ⭐ <?php esc_html_e( 'Become a Herlan Star Member.', 'auth-popup' ); ?>
                            </span>
                        </label>
                    </div>

                    <div class="ap-loyalty-fields" id="ap-loyalty-benefits" >
                        <div class="ap-loyalty-fields-inner">
                            <p>Lorem ipsum dolor sit amet, consectetur adipisicing elit. Ad animi, aperiam cumque distinctio doloribus ea earum eius error laudantium, magnam necessitatibus non officiis placeat quibusdam saepe sint suscipit ullam vero!</p>
                        </div>
                    </div>
                    <!-- Loyalty Benefits-->


                    <!-- Loyalty extra fields (shown when checkbox is ticked) -->
                    <div class="ap-loyalty-fields" id="ap-loyalty-fields" style="display:none;">
                        <div class="ap-loyalty-fields-inner">
                            <p class="ap-loyalty-desc"><?php esc_html_e( 'Complete your loyalty profile to start earning points!', 'auth-popup' ); ?></p>
                            <div class="ap-field-row-1">
                                <div class="ap-field">
<!--                                    <label for="ap-reg-gender">--><?php //esc_html_e( 'Gender', 'auth-popup' ); ?><!-- <span class="ap-required">*</span></label>-->
                                    <div class="ap-input-wrap">
                                        <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M16 21v-1a4 4 0 00-8 0v1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                        <select name="gender" id="ap-reg-gender" class="ap-select">
                                            <option value=""><?php esc_html_e( 'Select Gender', 'auth-popup' ); ?></option>
                                            <option value="Male"><?php esc_html_e( 'Male', 'auth-popup' ); ?></option>
                                            <option value="Female"><?php esc_html_e( 'Female', 'auth-popup' ); ?></option>
                                            <option value="Other"><?php esc_html_e( 'Other', 'auth-popup' ); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <br>
                                <div class="ap-field">
<!--                                    <label for="ap-reg-dob">--><?php //esc_html_e( 'Date of Birth', 'auth-popup' ); ?><!-- <span class="ap-required">*</span></label>-->
                                    <div class="ap-input-wrap">
                                        <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                        <input type="date" name="dob" id="ap-reg-dob" value="<?php echo date('M/D/Y') ?>" class="ap-date-input" max="<?php echo esc_attr( date('Y-m-d', strtotime('-10 years')) ); ?>">
                                    </div>
                                </div>
                            </div>
<!--                            <div class="ap-field">-->
<!--                                <label for="ap-reg-card">--><?php //esc_html_e( 'Card Number', 'auth-popup' ); ?><!-- <small>--><?php //esc_html_e( '(optional)', 'auth-popup' ); ?><!--</small></label>-->
<!--                                <div class="ap-input-wrap">-->
<!--                                    <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M2 10h20" stroke="currentColor" stroke-width="1.8"/></svg>-->
<!--                                    <input type="text" name="card_number" id="ap-reg-card" placeholder="--><?php //esc_attr_e( 'Enter loyalty card number', 'auth-popup' ); ?><!--">-->
<!--                                </div>-->
<!--                            </div>-->
                        </div>
                    </div>
                    <?php endif; // loyalty_enabled ?>

                    <button type="button" class="ap-btn ap-btn-primary ap-send-otp-btn" data-form="register">
                        <?php esc_html_e( 'Continue with OTP', 'auth-popup' ); ?>
                    </button>
                </div>

                <!-- Step 2: OTP verification -->
                <div class="ap-reg-step" data-step="2">
                    <p class="ap-otp-hint"></p>
                    <div class="ap-field">
                        <label><?php esc_html_e( 'Enter OTP', 'auth-popup' ); ?></label>
                        <div class="ap-otp-inputs">
                            <?php for ( $i = 0; $i < 6; $i++ ) : ?>
                                <input type="tel" class="ap-otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric">
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="otp" id="ap-reg-otp">
                    </div>
                    <div class="ap-otp-timer">
                        <span class="ap-timer-text"></span>
                        <button type="button" class="ap-resend-btn ap-link" data-form="register" style="display:none;"><?php esc_html_e( 'Resend OTP', 'auth-popup' ); ?></button>
                    </div>
                    <button type="submit" class="ap-btn ap-btn-primary ap-submit-btn">
                        <?php esc_html_e( 'Create Account', 'auth-popup' ); ?>
                    </button>
                    <button type="button" class="ap-link ap-back-btn"><?php esc_html_e( '← Change details', 'auth-popup' ); ?></button>
                </div>

            </form>

            <!-- Continue as Guest (checkout page only, injected via JS) -->
            <div class="ap-guest-wrap" id="ap-guest-wrap" style="display:none;text-align:center;margin:8px 0 4px;">
                <div class="ap-divider"><span><?php esc_html_e( 'or', 'auth-popup' ); ?></span></div>
                <button type="button" class="ap-btn ap-btn-ghost" id="ap-guest-btn">
                    <?php esc_html_e( 'Continue as Guest', 'auth-popup' ); ?>
                </button>
            </div>

            <!-- Social login for Register too -->
            <?php if ( $en_google || $en_fb ) : ?>
            <div class="ap-divider"><span><?php esc_html_e( 'or sign up with', 'auth-popup' ); ?></span></div>
            <div class="ap-social-btns">
                <?php if ( $en_google ) : ?>
                <button type="button" class="ap-btn ap-btn-social ap-btn-google" id="ap-google-btn-reg">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                    <?php esc_html_e( 'Continue with Google', 'auth-popup' ); ?>
                </button>
                <?php endif; ?>
                <?php if ( $en_fb ) : ?>
                <button type="button" class="ap-btn ap-btn-social ap-btn-facebook" id="ap-facebook-btn-reg">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" fill="#1877F2"/></svg>
                    <?php esc_html_e( 'Continue with Facebook', 'auth-popup' ); ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- /register panel -->



        <!-- Terms note -->
        <p class="ap-terms">
            <?php esc_html_e( 'By continuing, you agree to our', 'auth-popup' ); ?>
            <a href="<?php echo esc_url( get_privacy_policy_url() ); ?>" target="_blank"><?php esc_html_e( 'Privacy Policy', 'auth-popup' ); ?></a>
        </p>

    </div><!-- /.ap-dialog -->
</div><!-- /#auth-popup-overlay -->
