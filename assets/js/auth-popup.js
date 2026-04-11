/**
 * Auth Popup — Frontend JavaScript
 * Handles: popup open/close, tab switching, OTP flow,
 *          AJAX login/register, Google GIS, Facebook SDK.
 */
(function ($) {
    'use strict';

    if (typeof AuthPopup === 'undefined') return;

    const AP = {
        $overlay:  null,
        $dialog:   null,
        $alert:    null,
        otpTimers: {},   // { formKey: intervalId }

        /* ── Init ──────────────────────────────────────────────────── */
        init() {
            // Runs for all users — has its own isLoggedIn guard inside
            this.initAccountDrawer();

            // Everything below requires the popup overlay (guest users only)
            this.$overlay = $('#auth-popup-overlay');
            if (!this.$overlay.length) return;

            this.$dialog = this.$overlay.find('.ap-dialog');
            this.$alert  = this.$overlay.find('#ap-alert');

            this.bindTriggers();
            this.bindClose();
            this.bindMainTabs();
            this.bindModeTabs();
            this.bindSendOTP();
            this.bindOTPInputs();
            this.bindBackBtns();
            this.bindForms();
            this.bindPasswordToggle();
            this.bindPhoneCheck();
            this.bindLoyaltyToggle();
            this.bindSwitchLinks();
            this.bindForgotPanel();
            this.bindHeaderBack();
            this.initGoogle();
            this.initFacebook();
            this.initCheckout();
            this.loadLoyaltyRules();
            this.initDatepicker();
        },

        /* ── Trigger / Open ────────────────────────────────────────── */
        bindTriggers() {
            // Configured CSS selector triggers
            const selector = AuthPopup.triggerSelector || '.auth-popup-trigger';
            $(document).on('click', selector, (e) => {
                e.preventDefault();
                this.open();
            });

            // Intercept any link pointing to the WooCommerce my-account page
            // for non-logged-in users — open popup instead of redirecting.
            if (AuthPopup.isLoggedIn !== '1' && AuthPopup.myAccountUrl) {
                const myAccPath = AuthPopup.myAccountUrl.replace(/^https?:\/\/[^/]+/, '').replace(/\/$/, '');
                $(document).on('click', 'a', (e) => {
                    const href = $(e.currentTarget).attr('href') || '';
                    // Match exact my-account path (ignore sub-pages like /my-account/orders)
                    const hrefPath = href.replace(/^https?:\/\/[^/]+/, '').replace(/\/$/, '');
                    if (hrefPath === myAccPath) {
                        e.preventDefault();
                        this.open();
                    }
                });
            }
        },

        open() {
            this.$overlay.fadeIn(180);
            $('body').addClass('ap-no-scroll');
            this.$dialog.find('.ap-tab').first().trigger('click');
            this.clearAlert();
            // Trap focus
            setTimeout(() => {
                this.$dialog.find('input:first').focus();
            }, 220);
        },

        close() {
            this.$overlay.fadeOut(160);
            $('body').removeClass('ap-no-scroll');
            this.clearAllTimers();
        },

        /* ── Close handlers ─────────────────────────────────────────── */
        bindClose() {
            $('#ap-close-btn').on('click', () => this.close());
            this.$overlay.find('.ap-mask').on('click', () => this.close());
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape' && this.$overlay.is(':visible')) this.close();
            });
        },

        /* ── Main tabs (Login / Register) ──────────────────────────── */
        bindMainTabs() {
            this.$overlay.on('click', '.ap-tab', (e) => {
                const $tab    = $(e.currentTarget);
                const $tabs   = $tab.closest('.ap-tabs').find('.ap-tab');
                const $panels = this.$overlay.find('.ap-panel');
                const tab     = $tab.data('tab');

                $tabs.removeClass('active');
                $tab.addClass('active');
                $panels.removeClass('active');
                $('#ap-panel-' + tab).addClass('active');

                this.clearAlert();
            });
        },

        /* ── Mode tabs (Password / OTP) ─────────────────────────────── */
        bindModeTabs() {
            this.$overlay.on('click', '.ap-mode-tab', (e) => {
                const $tab   = $(e.currentTarget);
                const $group = $tab.closest('.ap-mode-tabs');
                $group.find('.ap-mode-tab').removeClass('active');
                $tab.addClass('active');

                const mode  = $tab.data('mode');
                const panel = $tab.closest('.ap-panel');
                panel.find('.ap-form').removeClass('active');
                panel.find('.ap-login-' + mode + '-form').addClass('active');

                this.clearAlert();
            });
        },

        /* ── Send OTP ───────────────────────────────────────────────── */
        bindSendOTP() {
            this.$overlay.on('click', '.ap-send-otp-btn', (e) => {
                const $btn  = $(e.currentTarget);
                const form  = $btn.data('form'); // 'login' or 'register'
                const phone = this.getPhoneFromForm(form);

                if (form === 'register') {
                    const name = $('#ap-reg-name').val().trim();
                    if (!name) {
                        this.showAlert('error', this.i18n('Please enter your name.'));
                        return;
                    }
                }

                if (!phone || phone.length < 10) {
                    this.showAlert('error', this.i18n('Please enter a valid mobile number.'));
                    return;
                }

                // Validate loyalty fields if checkbox is ticked (register form only)
                // Confirm password check (client-side only)
                if (form === 'register') {
                    const $pass    = $('#ap-reg-password');
                    const $confirm = $('#ap-reg-confirm-password');
                    if ($pass.length && $confirm.length && $confirm.val()) {
                        if ($pass.val() !== $confirm.val()) {
                            this.showAlert('error', this.i18n('Passwords do not match. Please check and try again.'));
                            return;
                        }
                    }
                }

                if (form === 'register' && !this.validateLoyaltyFields()) {
                    return;
                }

                $btn.addClass('ap-loading').prop('disabled', true);
                this.clearAlert();

                $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:  'auth_popup_send_otp',
                        nonce:   AuthPopup.nonce,
                        phone:   phone,
                        context: form,
                    },
                    success: (res) => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        if (res.success) {
                            this.goToOTPStep(form, phone, res.data.expiry_seconds || 300);
                            this.showAlert('success', res.data.message);
                        } else {
                            this.showAlert('error', res.data.message || this.i18n('Failed to send OTP.'));
                        }
                    },
                    error: () => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        this.showAlert('error', AuthPopup.i18n.error_network);
                    },
                });
            });
        },

        getPhoneFromForm(form) {
            if (form === 'login') {
                return $('#ap-lo-phone').val().trim();
            }
            return $('#ap-reg-phone').val().trim();
        },

        goToOTPStep(form, phone, expirySecs) {
            if (form === 'login') {
                this.showStep('#ap-login-otp-form', 2);
                $('#ap-login-otp-form .ap-otp-hint').text(
                    this.i18n('OTP sent to') + ' *****' + phone.slice(-4)
                );
                this.startTimer('login', expirySecs, '#ap-login-otp-form');
                $('#ap-login-otp-form .ap-otp-digit').first().focus();
            } else {
                this.showStep('#ap-register-form', 2, '.ap-reg-step');
                $('#ap-register-form .ap-otp-hint').text(
                    this.i18n('OTP sent to') + ' *****' + phone.slice(-4)
                );
                this.startTimer('register', expirySecs, '#ap-register-form');
                $('#ap-register-form .ap-otp-digit').first().focus();
            }
        },

        showStep(formSel, step, stepClass = '.ap-otp-step') {
            $(formSel).find(stepClass).removeClass('active');
            $(formSel).find(stepClass + '[data-step="' + step + '"]').addClass('active');
        },

        /* ── OTP digit inputs ───────────────────────────────────────── */
        bindOTPInputs() {
            this.$overlay.on('input keydown paste', '.ap-otp-digit', function (e) {
                const $inputs = $(this).closest('.ap-otp-inputs').find('.ap-otp-digit');
                const idx     = $inputs.index(this);

                if (e.type === 'paste') {
                    e.preventDefault();
                    const paste = (e.originalEvent.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
                    paste.split('').forEach((ch, i) => {
                        if ($inputs[i]) { $inputs.eq(i).val(ch).addClass('ap-filled'); }
                    });
                    AP.syncOTPHidden($(this));
                    $inputs.last().focus();
                    return;
                }

                if (e.type === 'keydown') {
                    if (e.key === 'Backspace' && !$(this).val() && idx > 0) {
                        $inputs.eq(idx - 1).val('').removeClass('ap-filled').focus();
                        AP.syncOTPHidden($(this));
                    }
                    return;
                }

                // input event
                const val = $(this).val().replace(/\D/, '').slice(-1);
                $(this).val(val);
                if (val) {
                    $(this).addClass('ap-filled');
                    if (idx < 5) $inputs.eq(idx + 1).focus();
                } else {
                    $(this).removeClass('ap-filled');
                }
                AP.syncOTPHidden($(this));
            });
        },

        syncOTPHidden($anyDigit) {
            const $form   = $anyDigit.closest('form');
            const $inputs = $anyDigit.closest('.ap-otp-inputs').find('.ap-otp-digit');
            const code    = $inputs.map((_, el) => $(el).val()).get().join('');
            $form.find('input[name="otp"]').val(code);
        },

        /* ── Timer ──────────────────────────────────────────────────── */
        startTimer(key, seconds, formSel) {
            this.clearTimer(key);
            let remaining = seconds;
            const $timer  = $(formSel).find('.ap-timer-text');
            const $resend = $(formSel).find('.ap-resend-btn');

            $resend.hide();
            const update = () => {
                if (remaining <= 0) {
                    $timer.text('');
                    $resend.show();
                    this.clearTimer(key);
                    return;
                }
                const m = Math.floor(remaining / 60);
                const s = remaining % 60;
                $timer.text(AuthPopup.i18n.resend_in + ' ' + m + ':' + String(s).padStart(2, '0'));
                remaining--;
            };
            update();
            this.otpTimers[key] = setInterval(update, 1000);
        },

        clearTimer(key) {
            if (this.otpTimers[key]) {
                clearInterval(this.otpTimers[key]);
                delete this.otpTimers[key];
            }
        },

        clearAllTimers() {
            Object.keys(this.otpTimers).forEach(k => this.clearTimer(k));
        },

        /* ── Resend OTP ─────────────────────────────────────────────── */
        bindBackBtns() {
            // Back button resets to step 1
            this.$overlay.on('click', '.ap-back-btn', (e) => {
                const $form = $(e.currentTarget).closest('form');
                $form.find('.ap-otp-step, .ap-reg-step').removeClass('active');
                $form.find('[data-step="1"]').addClass('active');
                $form.find('.ap-otp-digit').val('').removeClass('ap-filled');
                $form.find('input[name="otp"]').val('');
                this.clearAlert();
            });

            // Back from step 3 → step 2 in register flow
            this.$overlay.on('click', '.ap-back-to-step2-btn', () => {
                this.showStep('#ap-register-form', 2, '.ap-reg-step');
                this.clearAlert();
            });

            // Resend OTP
            this.$overlay.on('click', '.ap-resend-btn', (e) => {
                const form  = $(e.currentTarget).data('form');
                const phone = this.getPhoneFromForm(form);
                if (!phone) return;
                $(e.currentTarget).closest('form').find('.ap-otp-digit').val('').removeClass('ap-filled');
                $(e.currentTarget).closest('form').find('input[name="otp"]').val('');
                this.requestOTP(form, phone);
            });
        },

        requestOTP(form, phone) {
            this.clearAlert();
            $.ajax({
                url:    AuthPopup.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'auth_popup_send_otp',
                    nonce:  AuthPopup.nonce,
                    phone:  phone,
                },
                success: (res) => {
                    if (res.success) {
                        const formSel = form === 'login' ? '#ap-login-otp-form' : '#ap-register-form';
                        this.startTimer(form, res.data.expiry_seconds || 300, formSel);
                        this.showAlert('success', res.data.message);
                    } else {
                        this.showAlert('error', res.data.message);
                    }
                },
                error: () => this.showAlert('error', AuthPopup.i18n.error_network),
            });
        },

        /* ── Forms submit ───────────────────────────────────────────── */
        bindForms() {
            // Login: Password form
            this.$overlay.on('submit', '#ap-login-password-form', (e) => {
                e.preventDefault();
                this.submitForm($(e.currentTarget), 'auth_popup_login_password');
            });

            // Login: OTP form
            this.$overlay.on('submit', '#ap-login-otp-form', (e) => {
                e.preventDefault();
                const $form = $(e.currentTarget);
                const otp   = $form.find('input[name="otp"]').val();
                if (!otp || otp.length !== 6) {
                    this.showAlert('error', this.i18n('Please enter the 6-digit OTP.'));
                    return;
                }
                const phone = $('#ap-lo-phone').val().trim();
                this.submitForm($form, 'auth_popup_login_otp', { phone });
            });

            // Register form
            this.$overlay.on('submit', '#ap-register-form', (e) => {
                e.preventDefault();
                const $form = $(e.currentTarget);
                const otp   = $form.find('input[name="otp"]').val();
                if (!otp || otp.length !== 6) {
                    this.showAlert('error', this.i18n('Please enter the 6-digit OTP.'));
                    return;
                }
                if (!this.validateLoyaltyFields()) return;
                this.submitForm($form, 'auth_popup_register');
            });

            // Register: Verify OTP (step 2 → step 3)
            this.$overlay.on('click', '.ap-verify-reg-otp-btn', (e) => {
                const $form = $(e.currentTarget).closest('form');
                const otp   = $form.find('input[name="otp"]').val();
                if (!otp || otp.length !== 6) {
                    this.showAlert('error', this.i18n('Please enter the 6-digit OTP.'));
                    return;
                }
                this.clearAlert();
                this.showStep('#ap-register-form', 3, '.ap-reg-step');
                setTimeout(() => $('#ap-reg-password').focus(), 100);
            });
        },

        submitForm($form, action, extraData = {}) {
            const $btn = $form.find('.ap-submit-btn');
            $btn.addClass('ap-loading').prop('disabled', true);
            this.clearAlert();

            const formData = $form.serializeArray().reduce((o, f) => {
                o[f.name] = f.value; return o;
            }, {});

            $.ajax({
                url:    AuthPopup.ajaxUrl,
                method: 'POST',
                data: {
                    ...formData,
                    ...extraData,
                    action,
                    nonce:       AuthPopup.nonce,
                    redirect_to: AuthPopup.redirectUrl,
                },
                success: (res) => {
                    $btn.removeClass('ap-loading').prop('disabled', false);
                    if (res.success) {
                        this.showAlert('success', res.data.message || AuthPopup.i18n.success);
                        setTimeout(() => {
                            window.location.href = res.data.redirect || AuthPopup.redirectUrl;
                        }, 800);
                    } else {
                        this.showAlert('error', res.data.message || 'Error occurred.');
                    }
                },
                error: () => {
                    $btn.removeClass('ap-loading').prop('disabled', false);
                    this.showAlert('error', AuthPopup.i18n.error_network);
                },
            });
        },

        /* ── Password show/hide ─────────────────────────────────────── */
        bindPasswordToggle() {
            this.$overlay.on('click', '.ap-toggle-pass', function () {
                const $input = $(this).siblings('input');
                const type   = $input.attr('type') === 'password' ? 'text' : 'password';
                $input.attr('type', type);
            });
        },

        /* ── Phone availability check (registration) ────────────────── */
        bindPhoneCheck() {
            let debounceTimer;
            this.$overlay.on('input', '#ap-reg-phone', (e) => {
                clearTimeout(debounceTimer);
                const $input  = $(e.currentTarget);
                const $msg    = $input.closest('.ap-field').find('.ap-phone-check-msg');
                const $otpBtn = this.$overlay.find('.ap-send-otp-btn[data-form="register"]');
                const phone   = $input.val().trim();

                $msg.text('').removeClass('taken free');

                if (phone.length < 10) {
                    // Restore button while user is still typing
                    $otpBtn.prop('disabled', false);
                    return;
                }

                debounceTimer = setTimeout(() => {
                    $.ajax({
                        url:    AuthPopup.ajaxUrl,
                        method: 'POST',
                        data:   { action: 'auth_popup_check_phone', nonce: AuthPopup.nonce, phone },
                        success: (res) => {
                            if (!res.success || !res.data.valid) return;
                            if (res.data.exists) {
                                $msg.text('Account exists. Please login instead.').addClass('taken');
                                $otpBtn.prop('disabled', true);
                            } else {
                                $msg.text('Mobile number available.').addClass('free');
                                $otpBtn.prop('disabled', false);
                            }
                        },
                        error: () => {
                            // On network failure don't block the user
                            $otpBtn.prop('disabled', false);
                        },
                    });
                }, 600);
            });
        },

        /* ── Loyalty Programme toggle ───────────────────────────────── */
        bindLoyaltyToggle() {
            this.$overlay.on('change', '#ap-join-loyalty', function () {
                const $fields = $('#ap-loyalty-fields');
                if ($(this).is(':checked')) {
                    $('#ap-loyalty-benefits').hide();
                    $fields.slideDown(220);
                    $fields.find('select[name="gender"], input[name="dob"]').attr('required', true);
                } else {
                    $fields.slideUp(180);
                    $('#ap-loyalty-benefits').show();
                    $fields.find('select[name="gender"], input[name="dob"]').removeAttr('required').val('');
                    $fields.find('input[name="card_number"]').val('');
                }
            });

            // Show loyalty fields on load since checkbox is checked by default
            if (this.$overlay.find('#ap-join-loyalty').is(':checked')) {
                this.$overlay.find('#ap-loyalty-fields').show();
                this.$overlay.find('#ap-loyalty-benefits').hide();
                this.$overlay.find('select[name="gender"], input[name="dob"]').attr('required', true);
            }
        },

        /* ── Loyalty validation before OTP send ─────────────────────── */
        validateLoyaltyFields() {
            const $checkbox = this.$overlay.find('#ap-join-loyalty');
            if (!$checkbox.is(':checked')) return true; // not joining, skip

            const gender = this.$overlay.find('select[name="gender"]').val();
            const dob    = this.$overlay.find('input[name="dob"]').val();

            if (!gender) {
                this.showAlert('error', this.i18n('Please select your gender to join the Loyalty Programme.'));
                this.$overlay.find('select[name="gender"]').focus();
                return false;
            }
            if (!dob) {
                this.showAlert('error', this.i18n('Please enter your date of birth to join the Loyalty Programme.'));
                this.$overlay.find('input[name="dob"]').focus();
                return false;
            }
            return true;
        },

        /* ── Google Identity Services ───────────────────────────────── */
        initGoogle() {
            if (AuthPopup.enableGoogle !== '1') return;

            if (!AuthPopup.googleClientId) {
                this.$overlay.on('click', '.ap-btn-google', () => {
                    this.showAlert('error', 'Google Client ID is not configured. Go to Settings → Auth Popup.');
                });
                return;
            }

            let tokenClient = null;

            const tryInitGoogle = () => {
                if (typeof google !== 'undefined' && google.accounts && google.accounts.oauth2) {
                    tokenClient = google.accounts.oauth2.initTokenClient({
                        client_id: AuthPopup.googleClientId,
                        scope:     'openid email profile',
                        callback:  (tokenResponse) => {
                            if (tokenResponse.error) {
                                this.showAlert('error', 'Google sign-in failed: ' + tokenResponse.error);
                                return;
                            }
                            const $btns = this.$overlay.find('.ap-btn-google');
                            $btns.addClass('ap-loading').prop('disabled', true);
                            $.ajax({
                                url:    AuthPopup.ajaxUrl,
                                method: 'POST',
                                data: {
                                    action:        'auth_popup_google_auth',
                                    nonce:         AuthPopup.nonce,
                                    access_token:  tokenResponse.access_token,
                                    redirect_to:   AuthPopup.redirectUrl,
                                },
                                success: (res) => {
                                    $btns.removeClass('ap-loading').prop('disabled', false);
                                    if (res.success) {
                                        this.showAlert('success', res.data.message);
                                        setTimeout(() => { window.location.href = res.data.redirect || AuthPopup.redirectUrl; }, 600);
                                    } else {
                                        this.showAlert('error', res.data.message);
                                    }
                                },
                                error: () => {
                                    $btns.removeClass('ap-loading').prop('disabled', false);
                                    this.showAlert('error', AuthPopup.i18n.error_network);
                                },
                            });
                        },
                    });
                } else {
                    setTimeout(tryInitGoogle, 300);
                }
            };
            tryInitGoogle();

            this.$overlay.on('click', '.ap-btn-google', () => {
                if (!tokenClient) {
                    this.showAlert('error', 'Google sign-in library not loaded. Please refresh the page.');
                    return;
                }
                tokenClient.requestAccessToken();
            });
        },

        /* ── Facebook SDK ───────────────────────────────────────────── */
        initFacebook() {
            if (AuthPopup.enableFacebook !== '1') return;

            // Button click: guard against unconfigured state
            this.$overlay.on('click', '.ap-btn-facebook', () => {
                if (!AuthPopup.facebookAppId) {
                    this.showAlert('error', 'Facebook App ID is not configured. Go to Settings → Auth Popup.');
                    return;
                }
                if (typeof FB === 'undefined') {
                    this.showAlert('error', 'Facebook SDK not loaded. Please refresh the page.');
                    return;
                }
                this.doFacebookLogin();
            });

            if (!AuthPopup.facebookAppId) return; // skip SDK init if no key

            window.fbAsyncInit = () => {
                FB.init({
                    appId:   AuthPopup.facebookAppId,
                    cookie:  true,
                    xfbml:   true,
                    version: 'v19.0',
                });
            };
        },

        doFacebookLogin() {
            const $btns = this.$overlay.find('.ap-btn-facebook');
            $btns.addClass('ap-loading').prop('disabled', true);

            FB.login((loginRes) => {
                if (loginRes.status !== 'connected') {
                    $btns.removeClass('ap-loading').prop('disabled', false);
                    return;
                }
                $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:       'auth_popup_facebook_auth',
                        nonce:        AuthPopup.nonce,
                        access_token: loginRes.authResponse.accessToken,
                        redirect_to:  AuthPopup.redirectUrl,
                    },
                    success: (res) => {
                        $btns.removeClass('ap-loading').prop('disabled', false);
                        if (res.success) {
                            this.showAlert('success', res.data.message);
                            setTimeout(() => { window.location.href = res.data.redirect || AuthPopup.redirectUrl; }, 600);
                        } else {
                            this.showAlert('error', res.data.message);
                        }
                    },
                    error: () => {
                        $btns.removeClass('ap-loading').prop('disabled', false);
                        this.showAlert('error', AuthPopup.i18n.error_network);
                    },
                });
            }, { scope: 'public_profile,email' });
        },

        /* ── Checkout: auto-open popup + Continue as Guest ─────────── */
        initCheckout() {
            if (AuthPopup.isLoggedIn === '1') return;

            // Detect checkout by form element (most reliable — works even if theme
            // doesn't output body_class() correctly)
            const isCheckout = $('form.woocommerce-checkout').length > 0 &&
                               $('.woocommerce-order-received').length === 0;
            if (!isCheckout) return;

            // Show "Continue as Guest" button only on checkout
            $('#ap-guest-wrap').show();

            // Small delay so WooCommerce checkout scripts finish first
            setTimeout(() => this.open(), 400);

            // "Continue as Guest" closes popup and lets WooCommerce proceed normally
            $('#ap-guest-btn').on('click', () => {
                this.close();
            });
        },

        /* ── Alert helpers ──────────────────────────────────────────── */
        showAlert(type, msg) {
            this.$alert
                .removeClass('ap-alert-error ap-alert-success')
                .addClass('ap-alert-' + type)
                .text(msg)
                .show();
        },

        clearAlert() {
            this.$alert.hide().text('').removeClass('ap-alert-error ap-alert-success');
        },

        /* ── Switch panel links ("Don't have an account?" etc.) ─────── */
        bindSwitchLinks() {
            this.$overlay.on('click', '.ap-switch-link', (e) => {
                e.preventDefault();
                const to = $(e.currentTarget).data('switch-to');
                this.$overlay.find('.ap-tab[data-tab="' + to + '"]').trigger('click');
                this.clearAlert();
                this.$dialog.scrollTop(0);
            });
        },

        /* ── Forgot Password panel ───────────────────────────────────── */
        bindForgotPanel() {
            // "Forgot Password" trigger in login form → show forgot panel
            this.$overlay.on('click', '.ap-forgot-trigger', (e) => {
                e.preventDefault();
                this.$overlay.find('.ap-tab[data-tab="forgot"]').trigger('click');
                this.clearAlert();
                this.$dialog.scrollTop(0);
            });

            // "Continue" button in forgot panel → validate then redirect to WP lost-password page
            this.$overlay.on('click', '#ap-forgot-submit', (e) => {
                const $btn       = $(e.currentTarget);
                const email      = $('#ap-forgot-email').val().trim();
                const lostpassUrl = $btn.data('lostpass');

                if (!email) {
                    this.showAlert('error', this.i18n('Please enter your email address.'));
                    return;
                }
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    this.showAlert('error', this.i18n('Please enter a valid email address.'));
                    return;
                }

                $btn.addClass('ap-loading').prop('disabled', true);
                // Redirect to WordPress lost-password page
                window.location.href = lostpassUrl;
            });
        },

        /* ── Header back button ──────────────────────────────────────── */
        bindHeaderBack() {
            $('#ap-header-back-btn').on('click', () => {
                // Register panel: navigate between steps
                if ($('#ap-panel-register').hasClass('active')) {
                    const activeStep = parseInt($('#ap-register-form').find('.ap-reg-step.active').data('step')) || 1;
                    if (activeStep === 2) {
                        this.showStep('#ap-register-form', 1, '.ap-reg-step');
                        this.clearAlert();
                        return;
                    } else if (activeStep === 3) {
                        this.showStep('#ap-register-form', 2, '.ap-reg-step');
                        this.clearAlert();
                        return;
                    }
                }
                // Forgot panel → go back to login
                if ($('#ap-panel-forgot').hasClass('active')) {
                    this.$overlay.find('.ap-tab[data-tab="login"]').trigger('click');
                    this.clearAlert();
                    return;
                }
                // All other cases (login panel, register step 1) → close popup
                this.close();
            });
        },

        /* ── Mobile Account Content Modal ───────────────────────────── */
        initAccountDrawer() {
            if (AuthPopup.isLoggedIn !== '1') return;

            // Inject full-screen content modal
            $('body').append(
                '<div id="ap-acct-modal">' +
                    '<div class="ap-acct-modal-header">' +
                        '<button type="button" class="ap-acct-modal-back" id="ap-acct-modal-back">' +
                            '<svg width="9" height="16" viewBox="0 0 9 16" fill="none"><path d="M8 1.5L1.5 8L8 14.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                        '</button>' +
                        '<span class="ap-acct-modal-title" id="ap-acct-modal-title">My Account</span>' +
                        '<button type="button" class="ap-acct-modal-close" id="ap-acct-modal-close">' +
                            '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>' +
                        '</button>' +
                    '</div>' +
                    '<div class="ap-acct-modal-body" id="ap-acct-modal-body"></div>' +
                '</div>'
            );

            const isMobile = () => window.innerWidth <= 768;

            const openModal = (url, title) => {
                $('#ap-acct-modal-title').text(title || 'My Account');
                $('#ap-acct-modal-body').html(
                    '<div class="ap-acct-spinner-wrap"><span class="ap-acct-spinner"></span></div>'
                );
                $('#ap-acct-modal').addClass('open');
                $('body').addClass('ap-no-scroll');

                fetch(url, { credentials: 'same-origin' })
                    .then((r) => {
                        if (!r.ok) throw new Error('http_' + r.status);
                        return r.text();
                    })
                    .then((html) => {
                        const doc = new DOMParser().parseFromString(html, 'text/html');
                        const el  = doc.querySelector('.woocommerce-MyAccount-content');
                        $('#ap-acct-modal-body').html(
                            el ? el.innerHTML : '<p class="ap-acct-error">Content not available.</p>'
                        );
                    })
                    .catch(() => {
                        $('#ap-acct-modal-body').html(
                            '<p class="ap-acct-error">Failed to load. Please try again.</p>'
                        );
                    });
            };

            const closeModal = () => {
                $('#ap-acct-modal').removeClass('open');
                $('body').removeClass('ap-no-scroll');
            };

            // WooCommerce nav item click → show content in modal (mobile only)
            $(document).on('click', '.woocommerce-MyAccount-navigation a', (e) => {
                if (!isMobile()) return;
                e.preventDefault();
                const $a = $(e.currentTarget);
                openModal($a.attr('href'), $a.text().trim());
            });

            // Close modal (back or × button)
            $(document).on('click', '#ap-acct-modal-back, #ap-acct-modal-close', closeModal);

            // ESC key
            $(document).on('keydown.acct', (e) => {
                if (e.key === 'Escape' && $('#ap-acct-modal').hasClass('open')) closeModal();
            });
        },

        /* ── jQuery UI Datepicker ────────────────────────────────────── */
        initDatepicker() {
            if (typeof $.fn.datepicker === 'undefined') return;

            const maxDate = new Date();
            maxDate.setFullYear(maxDate.getFullYear() - 10);

            $('#ap-reg-dob').datepicker({
                dateFormat:  'yy-mm-dd',
                maxDate:     maxDate,
                changeMonth: true,
                changeYear:  true,
                yearRange:   '1940:' + (new Date().getFullYear() - 10),
                showAnim:    'fadeIn',
            });
        },

        /* ── Loyalty Rules table ─────────────────────────────────────── */
        loadLoyaltyRules() {
            const $tbody  = $('#ap-loyalty-rules-tbody');
            const $footer = $('#ap-loyalty-rules-footer');
            if (!$tbody.length) return;

            $.ajax({
                url:    AuthPopup.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'auth_popup_get_loyalty_rules',
                    nonce:  AuthPopup.nonce,
                },
                success: (res) => {
                    if (!res.success || !res.data.rules || !res.data.rules.length) {
                        $tbody.html('<tr><td colspan="3" class="ap-rule-empty">No rules found.</td></tr>');
                        return;
                    }
                    const rules = res.data.rules;
                    let html = '';
                    rules.forEach((rule, i) => {
                        const hidden = i >= 3 ? ' ap-rule-hidden' : '';
                        html += '<tr class="ap-rule-row' + hidden + '">'
                            + '<td class="ap-col-num">' + (i + 1) + '</td>'
                            + '<td>' + $('<div>').text(rule.name).html() + '</td>'
                            + '<td>' + $('<div>').text(rule.description).html() + '</td>'
                            + '</tr>';
                    });
                    $tbody.html(html);
                    if (rules.length > 3) $footer.show();
                },
                error: () => {
                    $tbody.html('<tr><td colspan="3" class="ap-rule-empty">Failed to load rules.</td></tr>');
                },
            });

            const viewMoreSvg = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            const viewLessSvg = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 8l4-4 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

            this.$overlay.on('click', '#ap-loyalty-view-more', () => {
                const $btn      = $('#ap-loyalty-view-more');
                const expanded  = $btn.hasClass('ap-expanded');
                if (expanded) {
                    $tbody.find('.ap-rule-row').each((i, el) => {
                        if (i >= 3) $(el).addClass('ap-rule-hidden');
                    });
                    $btn.removeClass('ap-expanded').html('View More ' + viewMoreSvg);
                } else {
                    $tbody.find('.ap-rule-hidden').removeClass('ap-rule-hidden');
                    $btn.addClass('ap-expanded').html('View Less ' + viewLessSvg);
                }
            });
        },

        /* ── i18n helper ────────────────────────────────────────────── */
        i18n(key) {
            if (AuthPopup.i18n[key]) return AuthPopup.i18n[key];
            return key; // fallback: return the key itself
        },
    };

    // ── Add no-scroll style ──────────────────────────────────────────
    $('<style>.ap-no-scroll{overflow:hidden!important}</style>').appendTo('head');

    // ── Boot ─────────────────────────────────────────────────────────
    $(document).ready(() => {
        AP.init();
    });

    // Expose for external use
    window.AuthPopupInstance = AP;

})(jQuery);
