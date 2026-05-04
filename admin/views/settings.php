<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap auth-popup-admin">
    <h1><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Auth Popup Settings', 'auth-popup' ); ?></h1>

    <?php settings_errors( 'auth_popup_settings' ); ?>

    <?php $s = get_option( 'auth_popup_settings', Auth_Popup_Core::default_settings() ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'auth_popup_settings_group' ); ?>

        <div class="auth-popup-tabs">
            <nav class="nav-tab-wrapper">
                <a href="#tab-sms"       class="nav-tab nav-tab-active" data-tab="tab-sms"><?php esc_html_e( 'SMS / OTP',  'auth-popup' ); ?></a>
                <a href="#tab-google"    class="nav-tab"                data-tab="tab-google"><?php esc_html_e( 'Google',    'auth-popup' ); ?></a>
                <a href="#tab-facebook"  class="nav-tab"                data-tab="tab-facebook"><?php esc_html_e( 'Facebook', 'auth-popup' ); ?></a>
                <a href="#tab-general"   class="nav-tab"                data-tab="tab-general"><?php esc_html_e( 'General',  'auth-popup' ); ?></a>
                <a href="#tab-loyalty"   class="nav-tab"                data-tab="tab-loyalty"><?php esc_html_e( 'Loyalty', 'auth-popup' ); ?></a>
                <a href="#tab-checkout"  class="nav-tab"                data-tab="tab-checkout"><?php esc_html_e( 'Checkout', 'auth-popup' ); ?></a>
                <a href="#tab-migration" class="nav-tab"                data-tab="tab-migration"><?php esc_html_e( 'Migration', 'auth-popup' ); ?></a>
            </nav>

            <!-- SMS / OTP -->
            <div id="tab-sms" class="auth-popup-tab-content active">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable OTP Login', 'auth-popup' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auth_popup_settings[enable_otp_login]" value="1" <?php checked( $s['enable_otp_login'], '1' ); ?>>
                                <?php esc_html_e( 'Allow users to login/register via mobile OTP', 'auth-popup' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'SMS Base URL', 'auth-popup' ); ?></th>
                        <td>
                            <input type="url" name="auth_popup_settings[sms_base_url]" value="<?php echo esc_attr( $s['sms_base_url'] ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'SSLCommerce iSMS Plus base URL (default: https://se.smsplus.net/api/v1)', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'SMS API Token', 'auth-popup' ); ?></th>
                        <td>
                            <input type="password" name="auth_popup_settings[sms_api_token]" value="<?php echo esc_attr( $s['sms_api_token'] ); ?>" class="regular-text" autocomplete="new-password">
                            <p class="description"><?php esc_html_e( 'Bearer token from SSLCommerce iSMS Plus dashboard', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Sender ID (SID)', 'auth-popup' ); ?></th>
                        <td>
                            <input type="text" name="auth_popup_settings[sms_sender_id]" value="<?php echo esc_attr( $s['sms_sender_id'] ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'Your approved sender ID / mask from SSLCommerce', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'OTP Expiry (minutes)', 'auth-popup' ); ?></th>
                        <td><input type="number" name="auth_popup_settings[otp_expiry_minutes]" value="<?php echo absint( $s['otp_expiry_minutes'] ); ?>" min="1" max="30" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Max OTP requests / hour (per phone)', 'auth-popup' ); ?></th>
                        <td>
                            <input type="number" name="auth_popup_settings[otp_max_per_hour]" value="<?php echo absint( $s['otp_max_per_hour'] ); ?>" min="1" max="20" class="small-text">
                            <p class="description"><?php esc_html_e( 'Maximum OTP sends allowed per phone number per hour.', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Max OTP requests / hour (per IP)', 'auth-popup' ); ?></th>
                        <td>
                            <input type="number" name="auth_popup_settings[otp_max_per_hour_ip]" value="<?php echo absint( $s['otp_max_per_hour_ip'] ?? 10 ); ?>" min="1" max="50" class="small-text">
                            <p class="description"><?php esc_html_e( 'Maximum OTP sends allowed from a single IP address per hour (across all phone numbers). Prevents mass-enumeration attacks.', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Max OTP verification attempts', 'auth-popup' ); ?></th>
                        <td>
                            <input type="number" name="auth_popup_settings[otp_max_verify_attempts]" value="<?php echo absint( $s['otp_max_verify_attempts'] ?? 5 ); ?>" min="1" max="10" class="small-text">
                            <p class="description"><?php esc_html_e( 'Maximum wrong OTP guesses allowed before the OTP is invalidated. Prevents brute-force guessing.', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Google -->
            <div id="tab-google" class="auth-popup-tab-content">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable Google Login', 'auth-popup' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auth_popup_settings[enable_google]" value="1" <?php checked( $s['enable_google'], '1' ); ?>>
                                <?php esc_html_e( 'Show "Continue with Google" button', 'auth-popup' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Google Client ID', 'auth-popup' ); ?></th>
                        <td>
                            <input type="text" name="auth_popup_settings[google_client_id]" value="<?php echo esc_attr( $s['google_client_id'] ); ?>" class="large-text">
                            <p class="description">
                                <?php esc_html_e( 'From Google Cloud Console → APIs & Services → Credentials. Add', 'auth-popup' ); ?>
                                <code><?php echo esc_html( home_url() ); ?></code>
                                <?php esc_html_e( 'as an Authorized JavaScript Origin.', 'auth-popup' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Google Client Secret', 'auth-popup' ); ?></th>
                        <td>
                            <input type="password" name="auth_popup_settings[google_client_secret]" value="<?php echo esc_attr( $s['google_client_secret'] ); ?>" class="large-text" autocomplete="new-password">
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Facebook -->
            <div id="tab-facebook" class="auth-popup-tab-content">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable Facebook Login', 'auth-popup' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auth_popup_settings[enable_facebook]" value="1" <?php checked( $s['enable_facebook'], '1' ); ?>>
                                <?php esc_html_e( 'Show "Continue with Facebook" button', 'auth-popup' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Facebook App ID', 'auth-popup' ); ?></th>
                        <td>
                            <input type="text" name="auth_popup_settings[fb_app_id]" value="<?php echo esc_attr( $s['fb_app_id'] ); ?>" class="large-text">
                            <p class="description">
                                <?php esc_html_e( 'From Meta for Developers → Your App → Settings → Basic. Add', 'auth-popup' ); ?>
                                <code><?php echo esc_html( home_url() ); ?></code>
                                <?php esc_html_e( 'to App Domains and Facebook Login → Valid OAuth Redirect URIs.', 'auth-popup' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Facebook App Secret', 'auth-popup' ); ?></th>
                        <td>
                            <input type="password" name="auth_popup_settings[fb_app_secret]" value="<?php echo esc_attr( $s['fb_app_secret'] ); ?>" class="large-text" autocomplete="new-password">
                        </td>
                    </tr>
                </table>
            </div>

            <!-- General -->
            <div id="tab-general" class="auth-popup-tab-content">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Inline Form on My Account Page', 'auth-popup' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auth_popup_settings[myaccount_inline_form]" value="1" <?php checked( $s['myaccount_inline_form'] ?? '1', '1' ); ?>>
                                <?php esc_html_e( 'Replace the WooCommerce login form on /my-account with the auth popup\'s inline login/register form (no popup overlay)', 'auth-popup' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'The popup still works normally on all other pages. Only the /my-account login page is affected.', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Enable Password Login', 'auth-popup' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auth_popup_settings[enable_password_login]" value="1" <?php checked( $s['enable_password_login'], '1' ); ?>>
                                <?php esc_html_e( 'Allow login with mobile/email + password', 'auth-popup' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Brand Name', 'auth-popup' ); ?></th>
                        <td><input type="text" name="auth_popup_settings[popup_brand_name]" value="<?php echo esc_attr( $s['popup_brand_name'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Logo URL', 'auth-popup' ); ?></th>
                        <td>
                            <input type="url" name="auth_popup_settings[popup_logo_url]" value="<?php echo esc_attr( $s['popup_logo_url'] ); ?>" class="large-text" id="ap-logo-url">
                            <button type="button" class="button" id="ap-logo-picker"><?php esc_html_e( 'Choose Logo', 'auth-popup' ); ?></button>
                            <p class="description"><?php esc_html_e( 'Leave blank to use site name as text.', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Redirect URL after Login', 'auth-popup' ); ?></th>
                        <td>
                            <input type="url" name="auth_popup_settings[redirect_url]" value="<?php echo esc_attr( $s['redirect_url'] ); ?>" class="large-text">
                            <p class="description"><?php esc_html_e( 'Where to send users after successful login/register.', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Trigger CSS Selector', 'auth-popup' ); ?></th>
                        <td>
                            <input type="text" name="auth_popup_settings[trigger_selector]" value="<?php echo esc_attr( $s['trigger_selector'] ); ?>" class="regular-text" placeholder=".auth-popup-trigger, #login-btn">
                            <p class="description"><?php esc_html_e( 'Comma-separated CSS selectors that open the popup on click. Also use shortcode [auth_popup_button].', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'REST API Key', 'auth-popup' ); ?></th>
                        <td>
                            <input type="text" readonly id="ap-rest-api-key" value="<?php echo esc_attr( $s['rest_api_key'] ?? '' ); ?>" class="large-text" style="font-family:monospace;">
                            <input type="hidden" name="auth_popup_settings[rest_api_key]" value="<?php echo esc_attr( $s['rest_api_key'] ?? '' ); ?>">
                            <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('ap-rest-api-key').value)"><?php esc_html_e( 'Copy', 'auth-popup' ); ?></button>
                            <p class="description">
                                <?php esc_html_e( 'Send this key in every REST API request header:', 'auth-popup' ); ?>
                                <code>X-API-Key: &lt;key&gt;</code><br>
                                <?php esc_html_e( 'Generated automatically. Keep it secret — it controls access to all REST endpoints.', 'auth-popup' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Access Token Lifetime (hours)', 'auth-popup' ); ?></th>
                        <td>
                            <input type="number" name="auth_popup_settings[token_lifetime_hours]" value="<?php echo absint( $s['token_lifetime_hours'] ?? 12 ); ?>" min="1" max="168" class="small-text">
                            <p class="description"><?php esc_html_e( 'How long a login token stays valid before the app must refresh it. Default: 12 hours. Max: 168 (7 days).', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Refresh Token Lifetime (days)', 'auth-popup' ); ?></th>
                        <td>
                            <input type="number" name="auth_popup_settings[refresh_token_lifetime_days]" value="<?php echo absint( $s['refresh_token_lifetime_days'] ?? 7 ); ?>" min="1" max="365" class="small-text">
                            <p class="description"><?php esc_html_e( 'How long a refresh token stays valid. When it expires the user must log in again. Default: 7 days.', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                </table>

                <div class="auth-popup-shortcode-info">
                    <h3><?php esc_html_e( 'Integration', 'auth-popup' ); ?></h3>
                    <p><?php esc_html_e( 'Use the shortcode to place a login button anywhere:', 'auth-popup' ); ?></p>
                    <code>[auth_popup_button label="Login / Register"]</code>
                    <p><?php esc_html_e( 'Or add class', 'auth-popup' ); ?> <code>auth-popup-trigger</code> <?php esc_html_e( 'to any existing button/link.', 'auth-popup' ); ?></p>
                    <p><?php esc_html_e( 'PHP function:', 'auth-popup' ); ?> <code>&lt;?php auth_popup_trigger_button(); ?&gt;</code></p>
                </div>
            </div>

            <!-- Loyalty -->
            <div id="tab-loyalty" class="auth-popup-tab-content">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable Loyalty Programme', 'auth-popup' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auth_popup_settings[loyalty_enabled]" value="1" <?php checked( $s['loyalty_enabled'] ?? '0', '1' ); ?>>
                                <?php esc_html_e( 'Show "Join Herlan Star Loyalty Programme" on the registration form', 'auth-popup' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Loyalty API Base URL', 'auth-popup' ); ?></th>
                        <td>
                            <input type="url" name="auth_popup_settings[loyalty_api_url]" value="<?php echo esc_attr( $s['loyalty_api_url'] ?? '' ); ?>" class="large-text" placeholder="https://api.example.com/">
                            <p class="description"><?php esc_html_e( 'The base URL of the Herlan Loyalty API. Registration data will be POSTed to {Base URL}/registration.', 'auth-popup' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Checkout -->
            <div id="tab-checkout" class="auth-popup-tab-content">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Auto-hide Shipping Form', 'auth-popup' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auth_popup_settings[checkout_hide_shipping_form]" value="1"
                                    <?php checked( $s['checkout_hide_shipping_form'] ?? '1', '1' ); ?>>
                                <?php esc_html_e( 'Hide the shipping details form by default when the user has at least one saved address', 'auth-popup' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'A toggle button ("Enter address manually") will still let users reveal the form. The hidden form is auto-filled from the selected saved address so checkout still works.', 'auth-popup' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Disable "Ship to Different Address"', 'auth-popup' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auth_popup_settings[checkout_disable_ship_to_different]" value="1"
                                    <?php checked( $s['checkout_disable_ship_to_different'] ?? '1', '1' ); ?>>
                                <?php esc_html_e( 'Remove the "Ship to a different address?" section from the checkout page', 'auth-popup' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Recommended when using the address book — shipping and billing use the same address. Disable this only if you need separate shipping and billing addresses.', 'auth-popup' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Migration -->
            <div id="tab-migration" class="auth-popup-tab-content">

                <!-- Mobile & Email Migration -->
                <h2><?php esc_html_e( 'Mobile & Email Sync Status', 'auth-popup' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Syncs existing WooCommerce data into WordPress: billing_phone → Auth Popup profiles table (enables OTP login), billing_email → WordPress account email (only for users whose account email is currently empty). Runs automatically in batches of 200.', 'auth-popup' ); ?></p>

                <div id="ap-udm-box" style="margin-top:16px;max-width:600px;">
                    <table class="form-table" style="margin:0 0 16px;">
                        <tr>
                            <th style="width:160px;"><?php esc_html_e( 'Status', 'auth-popup' ); ?></th>
                            <td><span id="ap-udm-status">—</span></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Mobile Numbers', 'auth-popup' ); ?></th>
                            <td>
                                <div style="background:#ddd;border-radius:4px;height:18px;width:100%;max-width:400px;overflow:hidden;">
                                    <div id="ap-udm-phone-bar" style="background:#2271b1;height:100%;width:0%;transition:width .4s;"></div>
                                </div>
                                <span id="ap-udm-phone" style="display:block;margin-top:4px;font-size:12px;color:#666;">— / —</span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Email Addresses', 'auth-popup' ); ?></th>
                            <td>
                                <div style="background:#ddd;border-radius:4px;height:18px;width:100%;max-width:400px;overflow:hidden;">
                                    <div id="ap-udm-email-bar" style="background:#2271b1;height:100%;width:0%;transition:width .4s;"></div>
                                </div>
                                <span id="ap-udm-email" style="display:block;margin-top:4px;font-size:12px;color:#666;">— / —</span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Next batch', 'auth-popup' ); ?></th>
                            <td><span id="ap-udm-next">—</span></td>
                        </tr>
                    </table>

                    <button type="button" class="button button-primary" id="ap-udm-run">
                        <?php esc_html_e( 'Run Next Batch Now', 'auth-popup' ); ?>
                    </button>
                    &nbsp;
                    <button type="button" class="button" id="ap-udm-restart">
                        <?php esc_html_e( 'Restart Migration', 'auth-popup' ); ?>
                    </button>
                    <span id="ap-udm-msg" style="margin-left:10px;color:#2271b1;font-size:13px;"></span>
                </div>

            </div>

        </div>

        <?php submit_button(); ?>
    </form>
</div>

<script>
(function(){
    // Tab switching
    document.querySelectorAll('.nav-tab').forEach(function(tab){
        tab.addEventListener('click', function(e){
            e.preventDefault();
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
            document.querySelectorAll('.auth-popup-tab-content').forEach(c => c.classList.remove('active'));
            this.classList.add('nav-tab-active');
            document.getElementById(this.dataset.tab).classList.add('active');
        });
    });

    // Media picker for logo
    var logoPicker = document.getElementById('ap-logo-picker');
    if(logoPicker && typeof wp !== 'undefined' && wp.media){
        logoPicker.addEventListener('click', function(){
            var frame = wp.media({ title: 'Choose Logo', button: { text: 'Use this image' }, multiple: false });
            frame.on('select', function(){
                var attachment = frame.state().get('selection').first().toJSON();
                document.getElementById('ap-logo-url').value = attachment.url;
            });
            frame.open();
        });
    }

    // ── Mobile & Email Migration monitor ───────────────────────────
    var udmPollTimer = null;

    function apUdmFetch() {
        jQuery.post(AuthPopupAdmin.ajaxUrl, {
            action: 'auth_popup_user_data_mig_status',
            nonce:  AuthPopupAdmin.nonce,
        }, function(res) {
            if (!res.success) return;
            var d = res.data;

            var statusEl = document.getElementById('ap-udm-status');
            if (d.done) {
                statusEl.innerHTML = '<span style="color:#46b450;font-weight:600;">&#10003; Complete</span>';
                clearInterval(udmPollTimer);
            } else {
                statusEl.innerHTML = '<span style="color:#f0a500;font-weight:600;">&#9679; In Progress</span>';
            }

            var phonePercent = d.phone_total > 0 ? Math.min(100, Math.round(d.phone_migrated / d.phone_total * 100)) : 100;
            document.getElementById('ap-udm-phone-bar').style.width = phonePercent + '%';
            document.getElementById('ap-udm-phone').textContent =
                d.phone_migrated.toLocaleString() + ' / ' + d.phone_total.toLocaleString() + ' synced (' + phonePercent + '%)';

            var emailPercent = d.email_total > 0 ? Math.min(100, Math.round(d.email_synced / d.email_total * 100)) : 100;
            document.getElementById('ap-udm-email-bar').style.width = emailPercent + '%';
            document.getElementById('ap-udm-email').textContent =
                d.email_synced.toLocaleString() + ' / ' + d.email_total.toLocaleString() + ' synced (' + emailPercent + '%)' +
                (d.email_remaining > 0 ? ' — ' + d.email_remaining.toLocaleString() + ' remaining' : '');
            document.getElementById('ap-udm-next').textContent =
                d.next_run ? d.next_run : (d.done ? '—' : 'Not scheduled');
        });
    }

    function apUdmStartPolling() {
        apUdmFetch();
        clearInterval(udmPollTimer);
        udmPollTimer = setInterval(apUdmFetch, 4000);
    }

    // Auto-start polling when Migration tab is opened
    document.querySelectorAll('.nav-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            if (this.dataset.tab === 'tab-migration') {
                apUdmStartPolling();
            } else {
                clearInterval(udmPollTimer);
            }
        });
    });

    // Run Next Batch Now
    document.getElementById('ap-udm-run') && document.getElementById('ap-udm-run').addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        document.getElementById('ap-udm-msg').textContent = 'Running batch…';
        jQuery.post(AuthPopupAdmin.ajaxUrl, {
            action: 'auth_popup_run_user_data_mig',
            nonce:  AuthPopupAdmin.nonce,
        }, function() {
            btn.disabled = false;
            document.getElementById('ap-udm-msg').textContent = 'Done. Refreshing…';
            apUdmFetch();
            setTimeout(function(){ document.getElementById('ap-udm-msg').textContent = ''; }, 3000);
        });
    });

    // Restart Migration
    document.getElementById('ap-udm-restart') && document.getElementById('ap-udm-restart').addEventListener('click', function() {
        if (!confirm('This will re-process all billing_phone and billing_email entries. Continue?')) return;
        var btn = this;
        btn.disabled = true;
        document.getElementById('ap-udm-msg').textContent = 'Restarting…';
        jQuery.post(AuthPopupAdmin.ajaxUrl, {
            action:  'auth_popup_run_user_data_mig',
            nonce:   AuthPopupAdmin.nonce,
            restart: 1,
        }, function() {
            btn.disabled = false;
            document.getElementById('ap-udm-msg').textContent = 'Restarted. Refreshing…';
            apUdmStartPolling();
            setTimeout(function(){ document.getElementById('ap-udm-msg').textContent = ''; }, 3000);
        });
    });
})();
</script>
