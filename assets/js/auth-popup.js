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

            // Support popup mode (all pages) and inline mode (my-account / shortcode)
            this.$overlay    = $('#auth-popup-overlay');
            this.$inlineWrap = $('.ap-inline-wrap');
            this.isInline    = this.$inlineWrap.length > 0;

            if (!this.$overlay.length && !this.isInline) return;

            // $ctx = event delegation root — overlay in popup mode, inline wrap on my-account
            this.$ctx = this.isInline ? this.$inlineWrap : this.$overlay;

            if (!this.isInline) {
                this.$dialog = this.$ctx.find('.ap-dialog');
            } else {
                this.$dialog = this.$inlineWrap;
            }
            this.$alert = $('#ap-alert');

            if (!this.isInline) {
                this.bindTriggers();
                this.bindClose();
            }
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
            this.initSocialComplete();
            if (!this.isInline) this.initCheckout();
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
            this.resetForgotPanel();
        },

        /* ── Close handlers ─────────────────────────────────────────── */
        bindClose() {
            $('#ap-close-btn').on('click', () => this.close());
            this.$ctx.find('.ap-mask').on('click', () => this.close());
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape' && this.$overlay.is(':visible')) this.close();
            });
        },

        /* ── Main tabs (Login / Register) ──────────────────────────── */
        bindMainTabs() {
            this.$ctx.on('click', '.ap-tab', (e) => {
                const $tab    = $(e.currentTarget);
                const $tabs   = $tab.closest('.ap-tabs').find('.ap-tab');
                const $panels = this.$ctx.find('.ap-panel');
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
            this.$ctx.on('click', '.ap-mode-tab', (e) => {
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
            this.$ctx.on('click', '.ap-send-otp-btn', (e) => {
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
            if (form === 'login')   return $('#ap-lo-phone').val().trim();
            if (form === 'social')  return $('#ap-sc-phone').val().trim();
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
            this.$ctx.on('input keydown paste', '.ap-otp-digit', function (e) {
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
            this.$ctx.on('click', '.ap-back-btn', (e) => {
                const $form = $(e.currentTarget).closest('form');
                $form.find('.ap-otp-step, .ap-reg-step').removeClass('active');
                $form.find('[data-step="1"]').addClass('active');
                $form.find('.ap-otp-digit').val('').removeClass('ap-filled');
                $form.find('input[name="otp"]').val('');
                this.clearAlert();
            });

            // Back from step 3 → step 2 in register flow
            this.$ctx.on('click', '.ap-back-to-step2-btn', () => {
                this.showStep('#ap-register-form', 2, '.ap-reg-step');
                this.clearAlert();
            });

            // Resend OTP
            this.$ctx.on('click', '.ap-resend-btn', (e) => {
                const form  = $(e.currentTarget).data('form');
                if (form === 'forgot') return; // handled by bindForgotPanel
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
                    action:  'auth_popup_send_otp',
                    nonce:   AuthPopup.nonce,
                    phone:   phone,
                    context: form,
                },
                success: (res) => {
                    if (res.success) {
                        let formSel = '#ap-register-form';
                        if (form === 'login')  formSel = '#ap-login-otp-form';
                        if (form === 'social') formSel = '#ap-sc-otp-section';
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
            this.$ctx.on('submit', '#ap-login-password-form', (e) => {
                e.preventDefault();
                this.submitForm($(e.currentTarget), 'auth_popup_login_password');
            });

            // Login: OTP form
            this.$ctx.on('submit', '#ap-login-otp-form', (e) => {
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
            this.$ctx.on('submit', '#ap-register-form', (e) => {
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
            this.$ctx.on('click', '.ap-verify-reg-otp-btn', (e) => {
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
            this.$ctx.on('click', '.ap-toggle-pass', function () {
                const $input = $(this).siblings('input');
                const type   = $input.attr('type') === 'password' ? 'text' : 'password';
                $input.attr('type', type);
            });
        },

        /* ── Phone availability check (registration) ────────────────── */
        bindPhoneCheck() {
            let debounceTimer;
            this.$ctx.on('input', '#ap-reg-phone', (e) => {
                clearTimeout(debounceTimer);
                const $input  = $(e.currentTarget);
                const $msg    = $input.closest('.ap-field').find('.ap-phone-check-msg');
                const $otpBtn = this.$ctx.find('.ap-send-otp-btn[data-form="register"]');
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
            this.$ctx.on('change', '#ap-join-loyalty', function () {
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
            if (this.$ctx.find('#ap-join-loyalty').is(':checked')) {
                this.$ctx.find('#ap-loyalty-fields').show();
                this.$ctx.find('#ap-loyalty-benefits').hide();
                this.$ctx.find('select[name="gender"], input[name="dob"]').attr('required', true);
            }
        },

        /* ── Loyalty validation before OTP send ─────────────────────── */
        validateLoyaltyFields() {
            const $checkbox = this.$ctx.find('#ap-join-loyalty');
            if (!$checkbox.is(':checked')) return true; // not joining, skip

            const gender = this.$ctx.find('select[name="gender"]').val();
            const dob    = this.$ctx.find('input[name="dob"]').val();

            if (!gender) {
                this.showAlert('error', this.i18n('Please select your gender to join the Loyalty Programme.'));
                this.$ctx.find('select[name="gender"]').focus();
                return false;
            }
            if (!dob) {
                this.showAlert('error', this.i18n('Please enter your date of birth to join the Loyalty Programme.'));
                this.$ctx.find('input[name="dob"]').focus();
                return false;
            }
            return true;
        },

        /* ── Google Identity Services ───────────────────────────────── */
        initGoogle() {
            if (AuthPopup.enableGoogle !== '1') return;

            if (!AuthPopup.googleClientId) {
                this.$ctx.on('click', '.ap-btn-google', () => {
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
                            const $btns = this.$ctx.find('.ap-btn-google');
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
                                        if (res.data.need_mobile) {
                                            this.showSocialCompletePanel(res.data);
                                        } else {
                                            this.showAlert('success', res.data.message);
                                            setTimeout(() => { window.location.href = res.data.redirect || AuthPopup.redirectUrl; }, 600);
                                        }
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

            this.$ctx.on('click', '.ap-btn-google', () => {
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
            this.$ctx.on('click', '.ap-btn-facebook', () => {
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
            const $btns = this.$ctx.find('.ap-btn-facebook');
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
                            if (res.data.need_mobile) {
                                this.showSocialCompletePanel(res.data);
                            } else {
                                this.showAlert('success', res.data.message);
                                setTimeout(() => { window.location.href = res.data.redirect || AuthPopup.redirectUrl; }, 600);
                            }
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

            // Intercept "Returning customer? Click here to login" — open auth popup
            // instead of the default WooCommerce login form (.woocommerce-form-login).
            // Uses capturing phase so it fires before WooCommerce's bubble-phase handler
            // on document.body, making stopImmediatePropagation effective.
            document.addEventListener('click', (e) => {
                const link = e.target.closest('a.showlogin');
                if (!link) return;
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                this.open();
            }, true);
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
            this.$ctx.on('click', '.ap-switch-link', (e) => {
                e.preventDefault();
                const to = $(e.currentTarget).data('switch-to');
                this.$ctx.find('.ap-tab[data-tab="' + to + '"]').trigger('click');
                this.clearAlert();
                this.$dialog.scrollTop(0);
            });
        },

        /* ── Forgot Password panel (3-step: email → OTP → new password) ── */
        bindForgotPanel() {
            // "Forgot Password" trigger → show forgot panel reset to step 1
            this.$ctx.on('click', '.ap-forgot-trigger', (e) => {
                e.preventDefault();
                this.resetForgotPanel();
                this.$ctx.find('.ap-tab[data-tab="forgot"]').trigger('click');
                this.clearAlert();
                this.$dialog.scrollTop(0);
            });

            // Step 1: Submit email → send OTP
            this.$ctx.on('click', '#ap-forgot-submit', (e) => {
                const $btn  = $(e.currentTarget);
                const email = $('#ap-forgot-email').val().trim();

                if (!email) {
                    this.showAlert('error', this.i18n('Please enter your email address.'));
                    return;
                }
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    this.showAlert('error', this.i18n('Please enter a valid email address.'));
                    return;
                }

                $btn.addClass('ap-loading').prop('disabled', true);
                this.clearAlert();

                $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'auth_popup_forgot_password',
                        nonce:  AuthPopup.nonce,
                        email:  email,
                    },
                    success: (res) => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        if (res.success) {
                            this.fpEmail = email;
                            this.showFpStep(2);
                            $('#ap-forgot-otp-hint').text(this.i18n('OTP sent to') + ' ' + email);
                            this.startTimer('forgot', res.data.expiry_seconds || 600, '#ap-fp-step-2');
                            $('#ap-forgot-otp-inputs .ap-otp-digit').first().focus();
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

            // Step 2: Verify OTP
            this.$ctx.on('click', '#ap-forgot-verify-otp-btn', (e) => {
                const $btn = $(e.currentTarget);
                const otp  = $('#ap-forgot-otp').val();

                if (!otp || otp.length !== 6) {
                    this.showAlert('error', this.i18n('Please enter the 6-digit OTP.'));
                    return;
                }

                $btn.addClass('ap-loading').prop('disabled', true);
                this.clearAlert();

                $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'auth_popup_verify_forgot_otp',
                        nonce:  AuthPopup.nonce,
                        email:  this.fpEmail,
                        otp:    otp,
                    },
                    success: (res) => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        if (res.success) {
                            this.fpResetToken = res.data.reset_token;
                            this.clearTimer('forgot');
                            this.showFpStep(3);
                            $('#ap-forgot-new-password').focus();
                            this.clearAlert();
                        } else {
                            this.showAlert('error', res.data.message);
                        }
                    },
                    error: () => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        this.showAlert('error', AuthPopup.i18n.error_network);
                    },
                });
            });

            // Step 2 resend OTP
            this.$ctx.on('click', '#ap-forgot-resend-btn', () => {
                if (!this.fpEmail) return;
                $('#ap-forgot-otp-inputs .ap-otp-digit').val('').removeClass('ap-filled');
                $('#ap-forgot-otp').val('');
                this.clearAlert();

                $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'auth_popup_forgot_password',
                        nonce:  AuthPopup.nonce,
                        email:  this.fpEmail,
                    },
                    success: (res) => {
                        if (res.success) {
                            this.startTimer('forgot', res.data.expiry_seconds || 600, '#ap-fp-step-2');
                            this.showAlert('success', res.data.message);
                        } else {
                            this.showAlert('error', res.data.message);
                        }
                    },
                    error: () => this.showAlert('error', AuthPopup.i18n.error_network),
                });
            });

            // Step 2 back → step 1
            this.$ctx.on('click', '#ap-forgot-back-to-email', () => {
                this.clearTimer('forgot');
                $('#ap-forgot-otp-inputs .ap-otp-digit').val('').removeClass('ap-filled');
                $('#ap-forgot-otp').val('');
                this.showFpStep(1);
                this.clearAlert();
            });

            // Step 3: Reset password
            this.$ctx.on('click', '#ap-forgot-reset-btn', (e) => {
                const $btn    = $(e.currentTarget);
                const newPass = $('#ap-forgot-new-password').val();
                const confPass = $('#ap-forgot-confirm-password').val();

                if (!newPass) {
                    this.showAlert('error', this.i18n('Password is required.'));
                    return;
                }
                if (newPass.length < 6) {
                    this.showAlert('error', this.i18n('Password must be at least 6 characters.'));
                    return;
                }
                if (newPass !== confPass) {
                    this.showAlert('error', this.i18n('Passwords do not match.'));
                    return;
                }

                $btn.addClass('ap-loading').prop('disabled', true);
                this.clearAlert();

                $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:           'auth_popup_reset_password',
                        nonce:            AuthPopup.nonce,
                        reset_token:      this.fpResetToken,
                        new_password:     newPass,
                        confirm_password: confPass,
                    },
                    success: (res) => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        if (res.success) {
                            this.showAlert('success', res.data.message);
                            // Switch to login tab after short delay
                            setTimeout(() => {
                                this.resetForgotPanel();
                                this.$ctx.find('.ap-tab[data-tab="login"]').trigger('click');
                                this.clearAlert();
                            }, 2200);
                        } else {
                            this.showAlert('error', res.data.message);
                        }
                    },
                    error: () => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        this.showAlert('error', AuthPopup.i18n.error_network);
                    },
                });
            });
        },

        showFpStep(step) {
            $('#ap-panel-forgot').find('.ap-fp-step').removeClass('active');
            $('#ap-panel-forgot').find('.ap-fp-step[data-step="' + step + '"]').addClass('active');
        },

        resetForgotPanel() {
            this.clearTimer('forgot');
            this.fpEmail      = '';
            this.fpResetToken = '';
            this.showFpStep(1);
            $('#ap-forgot-email').val('');
            $('#ap-forgot-otp-inputs .ap-otp-digit').val('').removeClass('ap-filled');
            $('#ap-forgot-otp').val('');
            $('#ap-forgot-new-password').val('');
            $('#ap-forgot-confirm-password').val('');
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
                    this.$ctx.find('.ap-tab[data-tab="login"]').trigger('click');
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

            // History stack: [{url, title}, ...] — index 0 is the root page
            const navHistory = [];

            const openModal = (url, title) => {
                $('#ap-acct-modal-title').text(title || 'My Account');
                $('#ap-acct-modal-body').html(
                    '<div class="ap-acct-spinner-wrap"><span class="ap-acct-spinner"></span></div>'
                );
                $('#ap-acct-modal').addClass('open');
                $('body').addClass('ap-no-scroll');
                // Show back-arrow only when we're deeper than the root page
                $('#ap-acct-modal-back').toggleClass('ap-modal-can-go-back', navHistory.length > 1);

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

            const navigateTo = (url, title) => {
                navHistory.push({ url, title });
                openModal(url, title);
            };

            const closeModal = () => {
                navHistory.length = 0;
                $('#ap-acct-modal').removeClass('open');
                $('body').removeClass('ap-no-scroll');
            };

            // WooCommerce nav item click → show content in modal (mobile only)
            $(document).on('click', '.woocommerce-MyAccount-navigation a', (e) => {
                if (!isMobile()) return;
                const $a = $(e.currentTarget);
                const href = $a.attr('href') || '';
                // Logout link: let the browser follow it directly (no modal)
                if (
                    href.indexOf('customer-logout') !== -1 ||
                    $a.closest('li').hasClass('woocommerce-MyAccount-navigation-link--customer-logout')
                ) {
                    return;
                }
                e.preventDefault();
                navHistory.length = 0; // Reset history for new top-level nav
                navigateTo(href, $a.text().trim());
            });

            // Back button: go to previous in-modal page, or close if at root
            $(document).on('click', '#ap-acct-modal-back', () => {
                if (navHistory.length > 1) {
                    navHistory.pop();
                    const prev = navHistory[navHistory.length - 1];
                    openModal(prev.url, prev.title);
                } else {
                    closeModal();
                }
            });

            // × button always closes
            $(document).on('click', '#ap-acct-modal-close', closeModal);

            // ESC key
            $(document).on('keydown.acct', (e) => {
                if (e.key === 'Escape' && $('#ap-acct-modal').hasClass('open')) closeModal();
            });

            // Links inside the modal body → intercept my-account sub-page links
            // so View / Invoice / etc. load within the modal instead of navigating away.
            const myAccBase = (AuthPopup.myAccountUrl || '').replace(/\/$/, '');
            $(document).on('click', '#ap-acct-modal-body a', (e) => {
                if (!isMobile()) return;
                const $a   = $(e.currentTarget);
                const href = $a.attr('href') || '';

                // Let these navigate normally: empty, hash, logout, javascript:,
                // existing new-tab links, and download links.
                if (
                    !href ||
                    href.startsWith('#') ||
                    href.indexOf('customer-logout') !== -1 ||
                    href.startsWith('javascript:') ||
                    $a.attr('target') === '_blank' ||
                    $a.attr('download') != null
                ) return;

                // PDF invoice links (WooCommerce PDF Invoices & Packing Slips plugin).
                // The plugin normally adds target="_blank" on page load, but that JS
                // won't re-run on AJAX-loaded modal content, so we force a new tab here.
                if ( href.indexOf('generate_wpo_wcpdf') !== -1 ) {
                    e.preventDefault();
                    window.open( href, '_blank' );
                    return;
                }

                // Intercept only same-origin my-account sub-pages
                // (e.g. /my-account/view-order/123/).
                // Invoice PDF links or other-domain links fall through and
                // navigate normally.
                const isSameOrigin = href.startsWith('/') ||
                    href.startsWith(window.location.origin);
                const isMyAccSub   = isSameOrigin && myAccBase &&
                    href.replace(/\/$/, '') !== myAccBase &&
                    (href.indexOf('/my-account/') !== -1 ||
                     (myAccBase && href.indexOf(myAccBase + '/') !== -1));

                if (isMyAccSub) {
                    e.preventDefault();
                    navigateTo(href, $a.text().trim() || 'My Account');
                }
            });
        },

        /* ── jQuery UI Datepicker ────────────────────────────────────── */
        initDatepicker() {
            if (typeof $.fn.datepicker === 'undefined') return;

            const maxDate = new Date();
            maxDate.setFullYear(maxDate.getFullYear() - 10);

            const dpOpts = {
                dateFormat:  'yy-mm-dd',
                maxDate:     maxDate,
                changeMonth: true,
                changeYear:  true,
                yearRange:   '1940:' + (new Date().getFullYear() - 10),
                showAnim:    'fadeIn',
            };

            $('#ap-reg-dob').datepicker(dpOpts);
            $('#ap-sc-dob').datepicker(dpOpts);
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

            this.$ctx.on('click', '#ap-loyalty-view-more', () => {
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

        /* ── Social Login Completion panel ──────────────────────────── */

        /**
         * Switch the popup to the social-complete panel and pre-populate it.
         * Called when Google/Facebook OAuth returns need_mobile:true.
         */
        showSocialCompletePanel(data) {
            // Hide all panels, show the social-complete one
            this.$ctx.find('.ap-panel').removeClass('active');
            $('#ap-panel-social-complete').addClass('active');

            // Store the temp token so it's submitted with the form
            $('#ap-sc-temp-token').val(data.temp_token || '');

            // Update heading with provider name and user's OAuth name
            const providerName = data.provider === 'google' ? 'Google' : 'Facebook';
            $('#ap-sc-title').text('Complete ' + providerName + ' Sign-in');
            if (data.name) {
                $('#ap-sc-subtitle').text(
                    'Hi ' + data.name + '! Please verify your mobile number to complete sign-in.'
                );
            } else {
                $('#ap-sc-subtitle').text('Please verify your mobile number to complete sign-in.');
            }

            // Reset: show only phase 1 (phone input)
            $('#ap-sc-phone-section').show();
            $('#ap-sc-otp-section').hide();
            $('#ap-sc-loyalty-section').hide();
            $('#ap-sc-phone').val('');
            $('#ap-sc-phone-msg').text('').removeClass('taken free error');
            $('#ap-sc-otp-inputs .ap-otp-digit').val('').removeClass('ap-filled');
            $('#ap-sc-otp').val('');
            this.clearTimer('social');
            this.clearAlert();
            this.$dialog.scrollTop(0);
        },

        // Returns true if phone matches Bangladeshi format: 01[3-9]XXXXXXXX (11 digits)
        isValidBDPhone(phone) {
            return /^01[3-9]\d{8}$/.test(phone);
        },

        initSocialComplete() {
            // Phase 1: blur validation on phone field
            this.$ctx.on('blur', '#ap-sc-phone', () => {
                const phone = $('#ap-sc-phone').val().trim();
                const $msg  = $('#ap-sc-phone-msg');

                $msg.text('').removeClass('taken free error');

                if (!phone) return;

                if (!this.isValidBDPhone(phone)) {
                    $msg.text('Please enter a valid Bangladeshi mobile number (e.g. 01712345678).').addClass('error');
                    return;
                }

                // Check if this number is already linked to an account
                $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data:   { action: 'auth_popup_check_phone', nonce: AuthPopup.nonce, phone },
                    success: (res) => {
                        if (!res.success || !res.data.valid) return;
                        if (res.data.exists) {
                            $msg.text('This mobile number is already registered. Please use a different number or sign in with your existing account.').addClass('taken');
                            $('#ap-sc-send-otp-btn').prop('disabled', true);
                        } else {
                            $msg.text('Mobile number is available.').addClass('free');
                            $('#ap-sc-send-otp-btn').prop('disabled', false);
                        }
                    },
                });
            });

            // Clear message and re-enable button while user is typing
            this.$ctx.on('input', '#ap-sc-phone', () => {
                $('#ap-sc-phone-msg').text('').removeClass('taken free error');
                $('#ap-sc-send-otp-btn').prop('disabled', false);
            });

            // Phase 1 → 2: Send OTP
            this.$ctx.on('click', '#ap-sc-send-otp-btn', () => {
                const phone = $('#ap-sc-phone').val().trim();
                const $msg  = $('#ap-sc-phone-msg');

                $msg.text('').removeClass('taken free error');

                if (!phone) {
                    $msg.text('Please enter your mobile number.').addClass('error');
                    return;
                }
                if (!this.isValidBDPhone(phone)) {
                    $msg.text('Please enter a valid Bangladeshi mobile number (e.g. 01712345678).').addClass('error');
                    return;
                }

                const $btn = $('#ap-sc-send-otp-btn');
                $btn.addClass('ap-loading').prop('disabled', true);
                this.clearAlert();

                $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:  'auth_popup_send_otp',
                        nonce:   AuthPopup.nonce,
                        phone:   phone,
                        context: 'social',
                    },
                    success: (res) => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        if (res.success) {
                            // Update subtitle for phase 2
                            $('#ap-sc-subtitle').text(
                                'Enter the 6-digit OTP sent to +880' + phone.slice(1) + '.'
                            );
                            // Show OTP section, hide phone section
                            $('#ap-sc-phone-section').hide();
                            $('#ap-sc-otp-section').show();
                            $('#ap-sc-otp-hint').text(
                                this.i18n('OTP sent to') + ' *****' + phone.slice(-4)
                            );
                            this.startTimer('social', res.data.expiry_seconds || 300, '#ap-sc-otp-section');
                            $('#ap-sc-otp-inputs .ap-otp-digit').first().focus();
                            this.clearAlert();
                        } else {
                            // Show backend error under the phone field
                            $('#ap-sc-phone-msg').text(res.data.message || this.i18n('Failed to send OTP.')).addClass('taken');
                            $('#ap-sc-send-otp-btn').prop('disabled', true);
                        }
                    },
                    error: () => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        this.showAlert('error', AuthPopup.i18n.error_network);
                    },
                });
            });

            // Phase 2: Verify OTP server-side → reveal phase 3 (loyalty + submit)
            this.$ctx.on('click', '#ap-sc-verify-otp-btn', () => {
                const otp   = $('#ap-sc-otp').val();
                const phone = $('#ap-sc-phone').val().trim();

                if (!otp || otp.length !== 6) {
                    this.showAlert('error', this.i18n('Please enter the 6-digit OTP.'));
                    return;
                }

                const $btn = $('#ap-sc-verify-otp-btn');
                $btn.addClass('ap-loading').prop('disabled', true);
                this.clearAlert();

                $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'auth_popup_verify_otp',
                        nonce:  AuthPopup.nonce,
                        phone:  phone,
                        otp:    otp,
                    },
                    success: (res) => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        if (res.success) {
                            // Update subtitle for phase 3
                            $('#ap-sc-subtitle').text(
                                'Join the Herlan Star Member Program today and unlock exclusive discounts, exciting bonuses, and special rewards every time you shop.'
                            );
                            $('#ap-sc-otp-section').hide();
                            $('#ap-sc-loyalty-section').show();
                            this.clearAlert();
                            this.$dialog.scrollTop(0);
                            this.loadSocialLoyaltyRules();
                        } else {
                            this.showAlert('error', res.data.message);
                        }
                    },
                    error: () => {
                        $btn.removeClass('ap-loading').prop('disabled', false);
                        this.showAlert('error', AuthPopup.i18n.error_network);
                    },
                });
            });

            // Phase 2 back: return to phone input
            this.$ctx.on('click', '#ap-sc-back-btn', () => {
                // Restore phase 1 subtitle
                $('#ap-sc-subtitle').text('Please verify your mobile number to complete sign-in.');
                $('#ap-sc-otp-section').hide();
                $('#ap-sc-phone-section').show();
                $('#ap-sc-otp-inputs .ap-otp-digit').val('').removeClass('ap-filled');
                $('#ap-sc-otp').val('');
                this.clearTimer('social');
                this.clearAlert();
            });

            // Loyalty Programme toggle (phase 3)
            this.$ctx.on('change', '#ap-sc-join-loyalty', function () {
                const $loyaltyFields = $('#ap-sc-loyalty-fields');
                if ($(this).is(':checked')) {
                    $('#ap-sc-loyalty-benefits').hide();
                    $loyaltyFields.slideDown(220);
                } else {
                    $loyaltyFields.slideUp(180);
                    $('#ap-sc-loyalty-benefits').show();
                    $loyaltyFields.find('select[name="gender"]').val('');
                    $loyaltyFields.find('input[name="dob"]').val('');
                }
            });

            // Form submission (phase 3)
            this.$ctx.on('submit', '#ap-social-complete-form', (e) => {
                e.preventDefault();
                if (!this.validateSocialLoyaltyFields()) return;
                this.submitForm($(e.currentTarget), 'auth_popup_social_complete');
            });
        },

        validateSocialLoyaltyFields() {
            const $checkbox = this.$ctx.find('#ap-sc-join-loyalty');
            if (!$checkbox.is(':checked')) return true;

            const gender = this.$ctx.find('#ap-sc-gender').val();
            const dob    = this.$ctx.find('#ap-sc-dob').val();

            if (!gender) {
                this.showAlert('error', this.i18n('Please select your gender to join the Loyalty Programme.'));
                this.$ctx.find('#ap-sc-gender').focus();
                return false;
            }
            if (!dob) {
                this.showAlert('error', this.i18n('Please enter your date of birth to join the Loyalty Programme.'));
                this.$ctx.find('#ap-sc-dob').focus();
                return false;
            }
            return true;
        },

        loadSocialLoyaltyRules() {
            const $tbody = $('#ap-sc-loyalty-rules-tbody');
            if (!$tbody.length || $tbody.data('ap-loaded')) return;

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
                    let html = '';
                    res.data.rules.forEach((rule, i) => {
                        html += '<tr class="ap-rule-row">'
                            + '<td class="ap-col-num">' + (i + 1) + '</td>'
                            + '<td>' + $('<div>').text(rule.name).html() + '</td>'
                            + '<td>' + $('<div>').text(rule.description).html() + '</td>'
                            + '</tr>';
                    });
                    $tbody.html(html).data('ap-loaded', true);
                },
                error: () => {
                    $tbody.html('<tr><td colspan="3" class="ap-rule-empty">Failed to load rules.</td></tr>');
                },
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
