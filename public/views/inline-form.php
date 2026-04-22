<?php
defined( 'ABSPATH' ) || exit;
$s            = get_option( 'auth_popup_settings', Auth_Popup_Core::default_settings() );
$brand        = esc_html( $s['popup_brand_name'] ?? get_bloginfo('name') );
$logo_url     = esc_url( $s['popup_logo_url'] ?? '' );
$en_pass      = ! empty( $s['enable_password_login'] );
$en_otp       = ! empty( $s['enable_otp_login'] );
$en_google    = ! empty( $s['enable_google'] );
$en_fb        = ! empty( $s['enable_facebook'] );
$en_loyalty   = ! empty( $s['loyalty_enabled'] );
$lostpass_url = esc_url( wp_lostpassword_url() );
?>

<div class="ap-inline-wrap">

    <!-- Tab navigation — visible on page, Login default -->
    <div class="ap-tabs ap-inline-tabs" aria-label="<?php esc_attr_e( 'Login or Register', 'auth-popup' ); ?>">
        <button class="ap-tab active" data-tab="login"><?php esc_html_e( 'Login', 'auth-popup' ); ?></button>
        <button class="ap-tab"        data-tab="register"><?php esc_html_e( 'Register', 'auth-popup' ); ?></button>
        <button class="ap-tab ap-tab-forgot-hidden" data-tab="forgot"><?php esc_html_e( 'Forgot Password', 'auth-popup' ); ?></button>
        <span class="ap-tab-indicator"></span>
    </div>

    <!-- Alert box -->
    <div class="ap-alert" id="ap-alert" style="display:none;"></div>

    <!-- ═══════════════════════ LOGIN PANEL ═══════════════════════ -->
    <div class="ap-panel active" id="ap-panel-login">

        <?php if ( $en_pass || $en_otp ) : ?>
        <div class="ap-mode-tabs" id="ap-login-mode-tabs">
            <?php if ( $en_pass ) : ?>
                <button class="ap-mode-tab active" data-mode="password"><?php esc_html_e( 'Password', 'auth-popup' ); ?></button>
            <?php endif; ?>
            <?php if ( $en_otp ) : ?>
                <button class="ap-mode-tab <?php echo $en_pass ? '' : 'active'; ?>" data-mode="otp"><?php esc_html_e( 'OTP', 'auth-popup' ); ?></button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Login: Password -->
        <?php if ( $en_pass ) : ?>
        <form class="ap-form ap-login-password-form active" id="ap-login-password-form" data-action="auth_popup_login_password" novalidate>
            <div class="ap-field">
                <div class="ap-input-wrap">
                    <svg class="ap-icon" viewBox="0 0 24 24" fill="none">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="1.8"/>
                        <polyline points="22,6 12,13 2,6" stroke="currentColor" stroke-width="1.8"/>
                    </svg>
                    <input type="text" id="ap-lp-credential" name="credential"
                           placeholder="<?php esc_attr_e( 'Mobile Or Email address', 'auth-popup' ); ?>"
                           autocomplete="username" required>
                </div>
            </div>
            <div class="ap-field">
                <div class="ap-input-wrap">
                    <svg class="ap-icon" viewBox="0 0 24 24" fill="none">
                        <rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="1.8"/>
                        <path d="M7 11V7a5 5 0 0110 0v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                    <input type="password" id="ap-lp-password" name="password"
                           placeholder="<?php esc_attr_e( 'Password', 'auth-popup' ); ?>"
                           autocomplete="current-password" required>
                    <button type="button" class="ap-toggle-pass" tabindex="-1">
                        <svg class="ap-eye" viewBox="0 0 24 24" fill="none">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.8"/>
                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="ap-remember-forgot-row">
                <label class="ap-remember-label">
                    <input type="checkbox" name="rememberme" value="forever" class="ap-remember-checkbox">
                    <span class="ap-check-box"></span>
                    <span><?php esc_html_e( 'Remember me', 'auth-popup' ); ?></span>
                </label>
                <a href="#" class="ap-forgot-trigger" data-lostpass="<?php echo $lostpass_url; ?>"><?php esc_html_e( 'Forgot Password', 'auth-popup' ); ?></a>
            </div>
            <button type="submit" class="ap-btn ap-btn-primary ap-submit-btn">
                <?php esc_html_e( 'Login', 'auth-popup' ); ?>
            </button>
        </form>
        <?php endif; ?>

        <!-- Login: OTP -->
        <?php if ( $en_otp ) : ?>
        <form class="ap-form ap-login-otp-form <?php echo $en_pass ? '' : 'active'; ?>" id="ap-login-otp-form" novalidate>
            <div class="ap-otp-step active" data-step="1">
                <div class="ap-field">
                    <div class="ap-input-wrap ap-phone-wrap">
                        <span class="ap-phone-prefix">+880</span>
                        <input type="tel" id="ap-lo-phone" name="phone"
                               placeholder="<?php esc_attr_e( '01XXXXXXXXX', 'auth-popup' ); ?>"
                               autocomplete="tel" required maxlength="11">
                    </div>
                </div>
                <button type="button" class="ap-btn ap-btn-primary ap-send-otp-btn" data-form="login">
                    <?php esc_html_e( 'Send OTP', 'auth-popup' ); ?>
                </button>
            </div>
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

        <!-- Switch to register -->
        <p class="ap-switch-text">
            <?php esc_html_e( "Don't have an account?", 'auth-popup' ); ?>
            <a href="#" class="ap-switch-link" data-switch-to="register"><?php esc_html_e( 'Sign Up here', 'auth-popup' ); ?></a>
        </p>

        <!-- Social login -->
        <?php if ( $en_google || $en_fb ) : ?>
        <div class="ap-divider"><span><?php esc_html_e( 'Or Continue With Account', 'auth-popup' ); ?></span></div>
        <div class="ap-social-circles">
            <?php if ( $en_fb ) : ?>
            <button type="button" class="ap-social-circle ap-btn-facebook" id="ap-facebook-btn" title="<?php esc_attr_e( 'Continue with Facebook', 'auth-popup' ); ?>">
                <svg viewBox="0 0 24 24" width="22" height="22"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" fill="#1877F2"/></svg>
            </button>
            <?php endif; ?>
            <?php if ( $en_google ) : ?>
            <button type="button" class="ap-social-circle ap-btn-google" id="ap-google-btn" title="<?php esc_attr_e( 'Continue with Google', 'auth-popup' ); ?>">
                <svg viewBox="0 0 24 24" width="22" height="22"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /login panel -->

    <!-- ═══════════════════════ REGISTER PANEL ═══════════════════════ -->
    <div class="ap-panel" id="ap-panel-register">

        <form class="ap-form ap-register-form active" id="ap-register-form" data-action="auth_popup_register" novalidate>

            <!-- Step 1 -->
            <div class="ap-reg-step active" data-step="1">

                <div class="ap-field">
                    <div class="ap-input-wrap">
                        <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        <input type="text" id="ap-reg-name" name="name"
                               placeholder="<?php esc_attr_e( 'Name', 'auth-popup' ); ?>"
                               autocomplete="name" required>
                    </div>
                </div>

                <div class="ap-field">
                    <div class="ap-input-wrap ap-phone-wrap">
                        <span class="ap-phone-prefix">+880</span>
                        <input type="tel" id="ap-reg-phone" name="phone"
                               placeholder="<?php esc_attr_e( '01XXXXXXXXX', 'auth-popup' ); ?>"
                               autocomplete="tel" required maxlength="11" minlength="11">
                    </div>
                    <span class="ap-phone-check-msg"></span>
                </div>

                <div class="ap-field">
                    <div class="ap-input-wrap">
                        <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="1.8"/><polyline points="22,6 12,13 2,6" stroke="currentColor" stroke-width="1.8"/></svg>
                        <input type="email" id="ap-reg-email" name="email"
                               placeholder="<?php esc_attr_e( 'Email address (Optional)', 'auth-popup' ); ?>"
                               autocomplete="email">
                    </div>
                </div>

                <button type="button" class="ap-btn ap-btn-primary ap-send-otp-btn" data-form="register">
                    <?php esc_html_e( 'Continue', 'auth-popup' ); ?>
                </button>

            </div><!-- /step 1 -->

            <!-- Step 2: OTP -->
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
                <button type="button" class="ap-btn ap-btn-primary ap-verify-reg-otp-btn">
                    <?php esc_html_e( 'Verify OTP', 'auth-popup' ); ?>
                </button>
                <button type="button" class="ap-link ap-back-btn"><?php esc_html_e( '← Change details', 'auth-popup' ); ?></button>
            </div><!-- /step 2 -->

            <!-- Step 3: Password + Loyalty -->
            <div class="ap-reg-step" data-step="3">

                <?php if ( $en_pass ) : ?>
                <div class="ap-field">
                    <div class="ap-input-wrap">
                        <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M7 11V7a5 5 0 0110 0v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        <input type="password" id="ap-reg-password" name="password"
                               placeholder="<?php esc_attr_e( 'Password', 'auth-popup' ); ?>"
                               autocomplete="new-password" minlength="6" required>
                        <button type="button" class="ap-toggle-pass" tabindex="-1">
                            <svg class="ap-eye" viewBox="0 0 24 24" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                        </button>
                    </div>
                </div>
                <div class="ap-field">
                    <div class="ap-input-wrap">
                        <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M7 11V7a5 5 0 0110 0v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        <input type="password" id="ap-reg-confirm-password" name="confirm_password"
                               placeholder="<?php esc_attr_e( 'Confirm Password', 'auth-popup' ); ?>"
                               autocomplete="new-password" minlength="6">
                        <button type="button" class="ap-toggle-pass" tabindex="-1">
                            <svg class="ap-eye" viewBox="0 0 24 24" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( $en_loyalty ) : ?>
                <div class="ap-loyalty-toggle">
                    <label class="ap-loyalty-check-label">
                        <input type="checkbox" id="ap-join-loyalty" name="join_loyalty" value="1">
                        <span class="ap-loyalty-check-box"></span>
                        <span class="ap-loyalty-check-text">
                            ⭐ <?php esc_html_e( 'Become a Herlan Star Member.', 'auth-popup' ); ?>
                        </span>
                    </label>
                </div>
                <div class="ap-loyalty-fields" id="ap-loyalty-benefits">
                    <div class="ap-loyalty-fields-inner">
                        <div class="ap-loyalty-rules-wrap">
                            <h3 class="ap-loyalty-rules-title"><?php esc_html_e( 'Herlan Star Member Rules', 'auth-popup' ); ?></h3>
                            <div class="ap-loyalty-table-scroll">
                                <table id="auth-loyal-rules-table" class="ap-loyal-rules-table">
                                    <thead>
                                        <tr>
                                            <th class="ap-col-num">#</th>
                                            <th><?php esc_html_e( 'Name', 'auth-popup' ); ?></th>
                                            <th><?php esc_html_e( 'Description', 'auth-popup' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="ap-loyalty-rules-tbody">
                                        <tr class="ap-rule-loading-row">
                                            <td colspan="3">
                                                <span class="ap-rule-spinner"></span>
                                                <?php esc_html_e( 'Loading rules…', 'auth-popup' ); ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div id="ap-loyalty-rules-footer" style="display:none;">
                                <button type="button" id="ap-loyalty-view-more">
                                    <?php esc_html_e( 'View More', 'auth-popup' ); ?>
                                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ap-loyalty-fields" id="ap-loyalty-fields" style="display:none;">
                    <div class="ap-loyalty-fields-inner">
                        <p class="ap-loyalty-desc"><?php esc_html_e( 'Complete your loyalty profile to start earning points!', 'auth-popup' ); ?></p>
                        <div class="ap-field-row-1">
                            <div class="ap-field">
                                <label for="ap-reg-gender"><?php esc_html_e( 'Gender', 'auth-popup' ); ?> <span class="ap-required">*</span></label>
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
                            <div class="ap-field">
                                <label for="ap-reg-dob"><?php esc_html_e( 'Date of Birth', 'auth-popup' ); ?> <span class="ap-required">*</span></label>
                                <div class="ap-input-wrap">
                                    <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                    <input type="text" name="dob" id="ap-reg-dob" class="ap-date-input ap-datepicker"
                                           placeholder="<?php esc_attr_e( 'Select date of birth', 'auth-popup' ); ?>"
                                           readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="ap-btn ap-btn-primary ap-submit-btn">
                    <?php esc_html_e( 'Create Account', 'auth-popup' ); ?>
                </button>
                <button type="button" class="ap-link ap-back-to-step2-btn"><?php esc_html_e( '← Back', 'auth-popup' ); ?></button>

            </div><!-- /step 3 -->

        </form>

        <!-- Switch to login -->
        <p class="ap-switch-text">
            <?php esc_html_e( 'Already have an account?', 'auth-popup' ); ?>
            <a href="#" class="ap-switch-link" data-switch-to="login"><?php esc_html_e( 'Sign In here', 'auth-popup' ); ?></a>
        </p>

        <!-- Social sign up -->
        <?php if ( $en_google || $en_fb ) : ?>
        <div class="ap-divider"><span><?php esc_html_e( 'Or Continue With Account', 'auth-popup' ); ?></span></div>
        <div class="ap-social-circles">
            <?php if ( $en_fb ) : ?>
            <button type="button" class="ap-social-circle ap-btn-facebook" id="ap-facebook-btn-reg" title="<?php esc_attr_e( 'Continue with Facebook', 'auth-popup' ); ?>">
                <svg viewBox="0 0 24 24" width="22" height="22"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" fill="#1877F2"/></svg>
            </button>
            <?php endif; ?>
            <?php if ( $en_google ) : ?>
            <button type="button" class="ap-social-circle ap-btn-google" id="ap-google-btn-reg" title="<?php esc_attr_e( 'Continue with Google', 'auth-popup' ); ?>">
                <svg viewBox="0 0 24 24" width="22" height="22"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /register panel -->

    <!-- ═══════════════════════ FORGOT PASSWORD PANEL ═══════════════════════ -->
    <div class="ap-panel" id="ap-panel-forgot">

        <div class="ap-panel-header">
            <h2 class="ap-title"><?php esc_html_e( 'Forgot Password', 'auth-popup' ); ?></h2>
            <p class="ap-subtitle" id="ap-forgot-subtitle"><?php esc_html_e( 'Enter your email address and we\'ll send you a verification code.', 'auth-popup' ); ?></p>
        </div>

        <!-- Step 1: Email -->
        <div class="ap-fp-step active" data-step="1">
            <div class="ap-field">
                <div class="ap-input-wrap">
                    <svg class="ap-icon" viewBox="0 0 24 24" fill="none">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="1.8"/>
                        <polyline points="22,6 12,13 2,6" stroke="currentColor" stroke-width="1.8"/>
                    </svg>
                    <input type="email" id="ap-forgot-email"
                           placeholder="<?php esc_attr_e( 'Email address', 'auth-popup' ); ?>"
                           autocomplete="email">
                </div>
            </div>
            <button type="button" class="ap-btn ap-btn-primary" id="ap-forgot-submit">
                <?php esc_html_e( 'Send OTP', 'auth-popup' ); ?>
            </button>
        </div>

        <!-- Step 2: OTP -->
        <div class="ap-fp-step" id="ap-fp-step-2" data-step="2">
            <p class="ap-otp-hint" id="ap-forgot-otp-hint"></p>
            <div class="ap-field">
                <label><?php esc_html_e( 'Enter OTP', 'auth-popup' ); ?></label>
                <div class="ap-otp-inputs" id="ap-forgot-otp-inputs">
                    <?php for ( $i = 0; $i < 6; $i++ ) : ?>
                        <input type="tel" class="ap-otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <?php endfor; ?>
                </div>
                <input type="hidden" id="ap-forgot-otp">
            </div>
            <div class="ap-otp-timer">
                <span class="ap-timer-text"></span>
                <button type="button" class="ap-link ap-resend-btn" id="ap-forgot-resend-btn"
                        data-form="forgot" style="display:none;">
                    <?php esc_html_e( 'Resend OTP', 'auth-popup' ); ?>
                </button>
            </div>
            <button type="button" class="ap-btn ap-btn-primary" id="ap-forgot-verify-otp-btn">
                <?php esc_html_e( 'Verify OTP', 'auth-popup' ); ?>
            </button>
            <button type="button" class="ap-link" id="ap-forgot-back-to-email">
                <?php esc_html_e( '← Change email', 'auth-popup' ); ?>
            </button>
        </div>

        <!-- Step 3: New password -->
        <div class="ap-fp-step" data-step="3">
            <div class="ap-field">
                <div class="ap-input-wrap">
                    <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M7 11V7a5 5 0 0110 0v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <input type="password" id="ap-forgot-new-password"
                           placeholder="<?php esc_attr_e( 'New Password', 'auth-popup' ); ?>"
                           autocomplete="new-password" minlength="6">
                    <button type="button" class="ap-toggle-pass" tabindex="-1">
                        <svg class="ap-eye" viewBox="0 0 24 24" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                    </button>
                </div>
            </div>
            <div class="ap-field">
                <div class="ap-input-wrap">
                    <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M7 11V7a5 5 0 0110 0v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <input type="password" id="ap-forgot-confirm-password"
                           placeholder="<?php esc_attr_e( 'Confirm New Password', 'auth-popup' ); ?>"
                           autocomplete="new-password" minlength="6">
                    <button type="button" class="ap-toggle-pass" tabindex="-1">
                        <svg class="ap-eye" viewBox="0 0 24 24" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                    </button>
                </div>
            </div>
            <button type="button" class="ap-btn ap-btn-primary" id="ap-forgot-reset-btn">
                <?php esc_html_e( 'Reset Password', 'auth-popup' ); ?>
            </button>
        </div>

    </div><!-- /forgot panel -->

    <!-- ═══════════ SOCIAL LOGIN COMPLETE PANEL ═══════════ -->
    <div class="ap-panel" id="ap-panel-social-complete">

        <div class="ap-panel-header">
            <h2 class="ap-title" id="ap-sc-title"><?php esc_html_e( 'Complete Sign-in', 'auth-popup' ); ?></h2>
            <p class="ap-subtitle" id="ap-sc-subtitle"><?php esc_html_e( 'Please verify your mobile number to continue.', 'auth-popup' ); ?></p>
        </div>

        <form id="ap-social-complete-form" autocomplete="off">
            <input type="hidden" name="temp_token" id="ap-sc-temp-token">

            <div id="ap-sc-phone-section">
                <div class="ap-field">
                    <div class="ap-input-wrap ap-phone-wrap">
                        <span class="ap-phone-prefix">+880</span>
                        <input type="tel" id="ap-sc-phone" name="phone"
                               placeholder="<?php esc_attr_e( '01XXXXXXXXX', 'auth-popup' ); ?>"
                               autocomplete="tel" required maxlength="11" minlength="11">
                    </div>
                    <span class="ap-phone-check-msg" id="ap-sc-phone-msg"></span>
                </div>
                <button type="button" class="ap-btn ap-btn-primary" id="ap-sc-send-otp-btn">
                    <?php esc_html_e( 'Verify Mobile Number', 'auth-popup' ); ?>
                </button>
            </div>

            <div id="ap-sc-otp-section" style="display:none;">
                <p class="ap-otp-hint" id="ap-sc-otp-hint"></p>
                <div class="ap-field">
                    <label><?php esc_html_e( 'Enter OTP', 'auth-popup' ); ?></label>
                    <div class="ap-otp-inputs" id="ap-sc-otp-inputs">
                        <?php for ( $i = 0; $i < 6; $i++ ) : ?>
                            <input type="tel" class="ap-otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="otp" id="ap-sc-otp">
                </div>
                <div class="ap-otp-timer">
                    <span class="ap-timer-text"></span>
                    <button type="button" class="ap-link ap-resend-btn" data-form="social" style="display:none;">
                        <?php esc_html_e( 'Resend OTP', 'auth-popup' ); ?>
                    </button>
                </div>
                <button type="button" class="ap-btn ap-btn-primary" id="ap-sc-verify-otp-btn">
                    <?php esc_html_e( 'Verify OTP', 'auth-popup' ); ?>
                </button>
                <button type="button" class="ap-link" id="ap-sc-back-btn">
                    <?php esc_html_e( '← Change number', 'auth-popup' ); ?>
                </button>
            </div>

            <div id="ap-sc-loyalty-section" style="display:none;">

                <?php if ( $en_loyalty ) : ?>
                <div class="ap-loyalty-toggle">
                    <label class="ap-loyalty-check-label">
                        <input type="checkbox" id="ap-sc-join-loyalty" name="join_loyalty" value="1">
                        <span class="ap-loyalty-check-box"></span>
                        <span class="ap-loyalty-check-text">
                            ⭐ <?php esc_html_e( 'Become a Herlan Star Member.', 'auth-popup' ); ?>
                        </span>
                    </label>
                </div>
                <div class="ap-loyalty-fields" id="ap-sc-loyalty-benefits">
                    <div class="ap-loyalty-fields-inner">
                        <div class="ap-loyalty-rules-wrap">
                            <h3 class="ap-loyalty-rules-title"><?php esc_html_e( 'Herlan Star Member Rules', 'auth-popup' ); ?></h3>
                            <div class="ap-loyalty-table-scroll">
                                <table class="ap-loyal-rules-table">
                                    <thead>
                                        <tr>
                                            <th class="ap-col-num">#</th>
                                            <th><?php esc_html_e( 'Name', 'auth-popup' ); ?></th>
                                            <th><?php esc_html_e( 'Description', 'auth-popup' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="ap-sc-loyalty-rules-tbody">
                                        <tr class="ap-rule-loading-row">
                                            <td colspan="3">
                                                <span class="ap-rule-spinner"></span>
                                                <?php esc_html_e( 'Loading rules…', 'auth-popup' ); ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ap-loyalty-fields" id="ap-sc-loyalty-fields" style="display:none;">
                    <div class="ap-loyalty-fields-inner">
                        <p class="ap-loyalty-desc"><?php esc_html_e( 'Complete your loyalty profile to start earning points!', 'auth-popup' ); ?></p>
                        <div class="ap-field-row-1">
                            <div class="ap-field">
                                <label for="ap-sc-gender"><?php esc_html_e( 'Gender', 'auth-popup' ); ?> <span class="ap-required">*</span></label>
                                <div class="ap-input-wrap">
                                    <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M16 21v-1a4 4 0 00-8 0v1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                    <select name="gender" id="ap-sc-gender" class="ap-select">
                                        <option value=""><?php esc_html_e( 'Select Gender', 'auth-popup' ); ?></option>
                                        <option value="Male"><?php esc_html_e( 'Male', 'auth-popup' ); ?></option>
                                        <option value="Female"><?php esc_html_e( 'Female', 'auth-popup' ); ?></option>
                                        <option value="Other"><?php esc_html_e( 'Other', 'auth-popup' ); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="ap-field">
                                <label for="ap-sc-dob"><?php esc_html_e( 'Date of Birth', 'auth-popup' ); ?> <span class="ap-required">*</span></label>
                                <div class="ap-input-wrap">
                                    <svg class="ap-icon" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                    <input type="text" name="dob" id="ap-sc-dob" class="ap-date-input ap-datepicker"
                                           placeholder="<?php esc_attr_e( 'Select date of birth', 'auth-popup' ); ?>"
                                           readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="ap-btn ap-btn-primary ap-submit-btn">
                    <?php esc_html_e( 'Complete Sign-in', 'auth-popup' ); ?>
                </button>

            </div>

        </form>

    </div><!-- /social complete panel -->

    <!-- Terms -->
    <p class="ap-terms">
        <?php esc_html_e( 'By continuing, you agree to our', 'auth-popup' ); ?>
        <a href="<?php echo esc_url( get_privacy_policy_url() ); ?>" target="_blank"><?php esc_html_e( 'Privacy Policy', 'auth-popup' ); ?></a>
    </p>

</div><!-- /.ap-inline-wrap -->
