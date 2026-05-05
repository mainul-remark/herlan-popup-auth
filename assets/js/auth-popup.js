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
            this.cleanLoginRedirectParams();

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
            this.bindEmailCheck();
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

        cleanLoginRedirectParams() {
            if (AuthPopup.isLoggedIn !== '1' || !window.history || !window.history.replaceState) return;
            try {
                const url = new URL(window.location.href);
                if (!url.searchParams.has('ap_logged_in')) return;
                url.searchParams.delete('ap_logged_in');
                url.searchParams.delete('ap_login_ts');
                window.history.replaceState({}, document.title, url.toString());
            } catch (e) {}
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
                            this.redirectAfterLogin(res.data.redirect || AuthPopup.redirectUrl);
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
        redirectAfterLogin(url) {
            const target = url || AuthPopup.redirectUrl || window.location.href;
            try {
                const parsed = new URL(target, window.location.href);
                parsed.searchParams.set('ap_logged_in', '1');
                parsed.searchParams.set('ap_login_ts', Date.now().toString());
                window.location.replace(parsed.toString());
            } catch (e) {
                const joiner = target.indexOf('?') === -1 ? '?' : '&';
                window.location.replace(target + joiner + 'ap_logged_in=1&ap_login_ts=' + Date.now());
            }
        },

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
                                const emailTaken = $input.closest('form').find('.ap-email-check-msg').hasClass('taken');
                                $otpBtn.prop('disabled', emailTaken);
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

        /* ── Email availability check (registration) ────────────────── */
        bindEmailCheck() {
            let debounceTimer;
            this.$ctx.on('input', '#ap-reg-email', (e) => {
                clearTimeout(debounceTimer);
                const $input  = $(e.currentTarget);
                const $msg    = $input.closest('.ap-field').find('.ap-email-check-msg');
                const $otpBtn = this.$ctx.find('.ap-send-otp-btn[data-form="register"]');
                const email   = $input.val().trim();

                $msg.text('').removeClass('taken free');

                if (!email) {
                    const phoneTaken = $input.closest('form').find('.ap-phone-check-msg').hasClass('taken');
                    $otpBtn.prop('disabled', phoneTaken);
                    return;
                }

                debounceTimer = setTimeout(() => {
                    $.ajax({
                        url:    AuthPopup.ajaxUrl,
                        method: 'POST',
                        data:   { action: 'auth_popup_check_email', nonce: AuthPopup.nonce, email },
                        success: (res) => {
                            if (!res.success || !res.data.valid) return;
                            if (res.data.exists) {
                                $msg.text('An account with this email already exists. Please login instead.').addClass('taken');
                                $otpBtn.prop('disabled', true);
                            } else {
                                $msg.text('Email address available.').addClass('free');
                                const phoneTaken = $input.closest('form').find('.ap-phone-check-msg').hasClass('taken');
                                $otpBtn.prop('disabled', phoneTaken);
                            }
                        },
                        error: () => {
                            $otpBtn.prop('disabled', false);
                        },
                    });
                }, 600);
            });
        },

        /* ── Loyalty Programme toggle ───────────────────────────────── */
        bindLoyaltyToggle() {
            this.$ctx.on('change', '#ap-join-loyalty', (e) => {
                const $fields = $('#ap-loyalty-fields');
                if ($(e.target).is(':checked')) {
                    $('#ap-loyalty-benefits').hide();
                    $fields.slideDown(220);
                    $fields.find('select[name="gender"], input[name="dob"]').attr('required', true);
                    if (typeof $.fn.datepicker !== 'undefined') {
                        $('#ap-reg-dob').datepicker(this._dpOpts || {});
                    }
                } else {
                    $fields.slideUp(180);
                    $('#ap-loyalty-benefits').show();
                    $fields.find('select[name="gender"], input[name="dob"]').removeAttr('required').val('');
                    $fields.find('input[name="card_number"]').val('');
                    if (typeof $.fn.datepicker !== 'undefined') {
                        $('#ap-reg-dob').datepicker('destroy');
                    }
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
            let googleNonceRetrying = false;

            const googleOriginHelp = () => {
                const origin = window.location.origin || (window.location.protocol + '//' + window.location.host);
                return ' Make sure ' + origin + ' is added to Authorized JavaScript origins in Google Cloud Console.';
            };

            const showGoogleError = (error) => {
                const code = typeof error === 'string' ? error : (error && (error.type || error.error || error.message));
                let message = 'Google sign-in failed.';

                if (code === 'popup_failed_to_open') {
                    message = 'Google sign-in popup was blocked. Please allow popups and try again.';
                } else if (code === 'popup_closed') {
                    message = 'Google sign-in was cancelled before completion.';
                } else if (code) {
                    message = 'Google sign-in failed: ' + code + '.';
                }

                this.showAlert('error', message + googleOriginHelp());
            };

            const refreshNonce = () => {
                return $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data:   { action: 'auth_popup_refresh_nonce' },
                }).then((res) => {
                    if (res && res.success && res.data && res.data.nonce) {
                        AuthPopup.nonce = res.data.nonce;
                        return true;
                    }
                    return $.Deferred().reject().promise();
                });
            };

            const isNonceFailure = (res) => {
                const message = res && res.data && res.data.message ? String(res.data.message) : '';
                return message.indexOf('Security check failed') !== -1;
            };

            const sendGoogleToken = (accessToken, $btns) => {
                return $.ajax({
                    url:    AuthPopup.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:        'auth_popup_google_auth',
                        nonce:         AuthPopup.nonce,
                        access_token:  accessToken,
                        redirect_to:   AuthPopup.redirectUrl,
                    },
                    success: (res) => {
                        if (!res.success && isNonceFailure(res) && !googleNonceRetrying) {
                            googleNonceRetrying = true;
                            if (window.console && console.info) {
                                console.info('Auth Popup: refreshing expired login nonce and retrying Google sign-in.');
                            }
                            refreshNonce()
                                .then(() => sendGoogleToken(accessToken, $btns))
                                .fail(() => {
                                    $btns.removeClass('ap-loading').prop('disabled', false);
                                    this.showAlert('error', res.data.message);
                                })
                                .always(() => { googleNonceRetrying = false; });
                            return;
                        }

                        googleNonceRetrying = false;
                        $btns.removeClass('ap-loading').prop('disabled', false);
                        if (res.success) {
                            if (res.data.need_mobile) {
                                this.showSocialCompletePanel(res.data);
                            } else {
                                this.showAlert('success', res.data.message);
                                setTimeout(() => { this.redirectAfterLogin(res.data.redirect || AuthPopup.redirectUrl); }, 600);
                            }
                        } else {
                            this.showAlert('error', res.data.message);
                        }
                    },
                    error: () => {
                        googleNonceRetrying = false;
                        $btns.removeClass('ap-loading').prop('disabled', false);
                        this.showAlert('error', AuthPopup.i18n.error_network);
                    },
                });
            };

            const tryInitGoogle = () => {
                if (typeof google !== 'undefined' && google.accounts && google.accounts.oauth2) {
                    tokenClient = google.accounts.oauth2.initTokenClient({
                        client_id: AuthPopup.googleClientId,
                        scope:     'openid email profile',
                        prompt:    'select_account',
                        error_callback: showGoogleError,
                        callback:  (tokenResponse) => {
                            if (tokenResponse.error) {
                                showGoogleError(tokenResponse);
                                return;
                            }
                            const $btns = this.$ctx.find('.ap-btn-google');
                            $btns.addClass('ap-loading').prop('disabled', true);
                            sendGoogleToken(tokenResponse.access_token, $btns);
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
                                setTimeout(() => { this.redirectAfterLogin(res.data.redirect || AuthPopup.redirectUrl); }, 600);
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
            if (isMobile()) {
                this.enhanceMobileAccountHome();
            }

            $(window).on('resize.apAccountDrawer', () => {
                if (!isMobile() && $('.woocommerce-MyAccount-navigation').first().data('apEnhanced')) {
                    window.location.reload();
                }
            });

            // History stack: [{url, title}, ...] — index 0 is the root page
            const navHistory = [];

            const openModal = (url, title) => {
                const skeletonType = this.getAccountSkeletonType(url, title);
                $('#ap-acct-modal-title').text(title || 'My Account');
                $('#ap-acct-modal-body').html(this.renderAccountSkeleton(skeletonType));
                $('#ap-acct-modal')
                    .removeClass('ap-acct-modal--orders ap-acct-modal--order-detail ap-acct-modal--account-details')
                    .toggleClass('ap-acct-modal--orders', skeletonType === 'orders')
                    .toggleClass('ap-acct-modal--order-detail', skeletonType === 'order-detail')
                    .addClass('open');
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
                        if (el) {
                            this.removeAccountBreadcrumbs(el);
                        }
                        $('#ap-acct-modal-body').html(
                            el ? el.innerHTML : '<p class="ap-acct-error">Content not available.</p>'
                        );
                        this.enhanceMobileAccountContent(url, title);
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
                const navTitle = $a.find('.ap-mobile-account-text strong').text().trim() || $a.text().trim();
                navigateTo(href, navTitle === 'Orders' ? 'My Orders' : navTitle);
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
            $(document).on('click', '.ap-account-details-cancel', closeModal);
            $(document).on('click', '.ap-account-pass-toggle', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                const $input = $btn.siblings('input[type="password"], input[type="text"]').first();
                if (!$input.length) return;
                const isPassword = $input.attr('type') === 'password';
                $input.attr('type', isPassword ? 'text' : 'password');
                $btn.attr('aria-pressed', isPassword ? 'true' : 'false');
            });
            $(document).on('click', '.ap-account-photo-btn', (e) => {
                e.preventDefault();
                const $btn = $(e.currentTarget);
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/jpeg,image/png,image/gif,image/webp';
                input.onchange = () => {
                    const file = input.files && input.files[0];
                    if (!file) return;
                    const formData = new FormData();
                    formData.append('action', 'auth_popup_upload_avatar');
                    formData.append('nonce', AuthPopup.nonce);
                    formData.append('avatar', file);

                    const originalText = $btn.text();
                    $btn.prop('disabled', true).addClass('is-loading').contents().filter(function() {
                        return this.nodeType === 3;
                    }).last().replaceWith('Uploading...');

                    $.ajax({
                        url: AuthPopup.ajaxUrl,
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: (res) => {
                            if (!res.success || !res.data || !res.data.url) {
                                alert((res.data && res.data.message) || 'Upload failed. Please try again.');
                                return;
                            }
                            AuthPopup.accountSummary = AuthPopup.accountSummary || {};
                            AuthPopup.accountSummary.avatarUrl = res.data.url;
                            $('.ap-account-details-avatar, .ap-mobile-account-avatar').html('<img src="' + $('<div>').text(res.data.url).html() + '" alt="">');
                        },
                        error: (xhr) => {
                            const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                                ? xhr.responseJSON.data.message
                                : 'Upload failed. Please try again.';
                            alert(message);
                        },
                        complete: () => {
                            $btn.prop('disabled', false).removeClass('is-loading').contents().filter(function() {
                                return this.nodeType === 3;
                            }).last().replaceWith(originalText);
                        },
                    });
                };
                input.click();
            });

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
                    navigateTo(
                        href,
                        $a.find('.ap-mobile-order-main strong').text().trim() ||
                        $a.text().trim() ||
                        'My Account'
                    );
                }
            });
        },

        /* ── jQuery UI Datepicker ────────────────────────────────────── */
        removeAccountBreadcrumbs(root) {
            const selectors = [
                '.woocommerce-breadcrumb',
                '.rank-math-breadcrumb',
                '.yoast-breadcrumb',
                '.breadcrumb',
                '.breadcrumbs',
                '[typeof="BreadcrumbList"]',
                '[aria-label="breadcrumb"]',
                'nav.breadcrumb',
                'nav.breadcrumbs',
            ];

            root.querySelectorAll(selectors.join(',')).forEach((el) => el.remove());
        },

        enhanceMobileAccountHome() {
            const $nav = $('.woocommerce-MyAccount-navigation').first();
            if (!$nav.length || $nav.data('apEnhanced')) return;

            const summary = AuthPopup.accountSummary || {};
            const esc = (value) => $('<div>').text(value || '').html();
            const phone = summary.phone ? '+' + String(summary.phone).replace(/^\+/, '') : '';
            const avatar = summary.avatarUrl
                ? '<img src="' + esc(summary.avatarUrl) + '" alt="">'
                : '<span>' + esc((summary.name || 'U').charAt(0).toUpperCase()) + '</span>';

            $nav.data('apEnhanced', true).addClass('ap-mobile-account-nav');
            $nav.before(
                '<div class="ap-mobile-account-head">' +
                    '<div class="ap-mobile-account-avatar">' + avatar + '</div>' +
                    '<div class="ap-mobile-account-id">' +
                        '<strong>' + esc(summary.name || AuthPopup.displayName || 'My Account') + '</strong>' +
                        (phone ? '<span>' + esc(phone) + '</span>' : '') +
                    '</div>' +
                '</div>'
            );

            const iconMap = {
                dashboard: 'sparkles',
                memberships: 'sparkles',
                membership: 'sparkles',
                orders: 'bag',
                'edit-address': 'pin',
                addresses: 'pin',
                'edit-account': 'id-card',
                account: 'id-card',
                'my-coupons': 'coupon',
                coupons: 'coupon',
                'my-store-credits': 'credit',
                credits: 'credit',
                wishlist: 'heart',
                'customer-logout': 'logout',
            };
            const subtitleMap = {
                dashboard: 'Overview',
                memberships: 'Silver status',
                membership: 'Silver status',
                orders: 'Track & manage',
                'edit-address': (summary.addressCount || 0) + ' saved',
                addresses: (summary.addressCount || 0) + ' saved',
                'edit-account': 'Edit profile',
                account: 'Edit profile',
                wishlist: (summary.wishlistCount || 0) + ' saved items',
            };
            const iconSvg = {
                sparkles: '<svg viewBox="0 0 24 24"><path d="M12 3l1.7 4.6L18 9.3l-4.3 1.7L12 16l-1.7-5L6 9.3l4.3-1.7L12 3z"/><path d="M18 14l.9 2.1L21 17l-2.1.9L18 20l-.9-2.1L15 17l2.1-.9L18 14z"/></svg>',
                bag: '<svg viewBox="0 0 24 24"><path d="M6 8h12l-1 12H7L6 8z"/><path d="M9 8a3 3 0 016 0"/></svg>',
                pin: '<svg viewBox="0 0 24 24"><path d="M12 21s6-5.2 6-11a6 6 0 10-12 0c0 5.8 6 11 6 11z"/><circle cx="12" cy="10" r="2"/></svg>',
                user: '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0114 0"/></svg>',
                'id-card': '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.5 17a3.8 3.8 0 017 0"/><path d="M14 10h5M14 13h4M14 16h3"/></svg>',
                coupon: '<svg viewBox="0 0 24 24"><path d="M4 7a2 2 0 012-2h12a2 2 0 012 2v3a2 2 0 010 4v3a2 2 0 01-2 2H6a2 2 0 01-2-2v-3a2 2 0 010-4V7z"/><path d="M9 9h.01M15 15h.01M15 9l-6 6"/></svg>',
                credit: '<svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M7 15h4"/></svg>',
                heart: '<svg viewBox="0 0 24 24"><path d="M20.5 8.5c0 5-8.5 10-8.5 10s-8.5-5-8.5-10A4.7 4.7 0 018 4.8c1.6 0 3 .8 4 2 1-1.2 2.4-2 4-2a4.7 4.7 0 014.5 3.7z"/></svg>',
                logout: '<svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M14 4h5v16h-5"/></svg>',
            };

            $nav.find('li').each((_, li) => {
                const $li = $(li);
                const $a = $li.children('a').first();
                if (!$a.length) return;

                const endpointClass = ($li.attr('class') || '').split(/\s+/).filter((className) => {
                    return className.indexOf('woocommerce-MyAccount-navigation-link--') === 0;
                })[0] || '';
                const endpoint = endpointClass.replace('woocommerce-MyAccount-navigation-link--', '');
                const key = endpoint || $.trim($a.text()).toLowerCase().replace(/\s+/g, '-');
                const isLogout = key === 'customer-logout' || ($a.attr('href') || '').indexOf('customer-logout') !== -1;
                const rawLabel = $.trim($a.text());
                const iconKey = iconMap[key] || 'user';
                const subtitle = subtitleMap[key] || '';
                const badge = key === 'orders' && Number(summary.orderCount) > 0
                    ? '<span class="ap-mobile-account-badge">' + Number(summary.orderCount) + '</span>'
                    : '';

                $li.toggleClass('ap-mobile-account-logout', isLogout);
                $a.html(
                    '<span class="ap-mobile-account-icon ap-mobile-account-icon--' + esc(iconKey) + '">' + iconSvg[iconKey] + '</span>' +
                    '<span class="ap-mobile-account-text"><strong>' + esc(rawLabel) + '</strong>' +
                        (subtitle && !isLogout ? '<small>' + esc(subtitle) + '</small>' : '') +
                    '</span>' +
                    badge +
                    '<span class="ap-mobile-account-arrow">&rsaquo;</span>'
                );
            });

            const $accountItems = $nav.find('li').not('.woocommerce-MyAccount-navigation-link--dashboard, .woocommerce-MyAccount-navigation-link--wishlist, .ap-mobile-account-logout');
            $accountItems.first().addClass('ap-mobile-account-first');
            $accountItems.last().addClass('ap-mobile-account-last');

            $('body').addClass('ap-mobile-account-ready');
        },

        getAccountSkeletonType(url, title) {
            const rawUrl = String(url || '');
            const rawTitle = String(title || '').toLowerCase();

            if (rawUrl.indexOf('/view-order/') !== -1) return 'order-detail';
            if (rawUrl.indexOf('/orders') !== -1 || rawTitle === 'orders' || rawTitle === 'my orders') return 'orders';
            if (rawUrl.indexOf('/edit-address') !== -1 || rawTitle.indexOf('address') !== -1) return 'addresses';
            if (rawUrl.indexOf('/edit-account') !== -1 || rawTitle.indexOf('account') !== -1) return 'account';
            return 'default';
        },

        renderAccountSkeleton(type) {
            const line = (className = '') => '<span class="ap-skel-line ' + className + '"></span>';

            if (type === 'orders') {
                return '<div class="ap-skel ap-skel-orders">' +
                    [1, 2, 3, 4, 5].map(() =>
                        '<div class="ap-skel-order-card">' +
                            '<div>' + line('ap-skel-w-42') + line('ap-skel-w-30') + line('ap-skel-w-22 ap-skel-gap-lg') + '</div>' +
                            '<div class="ap-skel-order-side">' + line('ap-skel-pill') + line('ap-skel-w-24 ap-skel-gap-lg') + '</div>' +
                        '</div>'
                    ).join('') +
                '</div>';
            }

            if (type === 'order-detail') {
                const item = '<div class="ap-skel-detail-item"><span class="ap-skel-thumb"></span><div>' + line('ap-skel-w-44') + line('ap-skel-w-56') + '</div><span class="ap-skel-line ap-skel-w-20"></span></div>';
                return '<div class="ap-skel ap-skel-detail">' +
                    '<section>' + line('ap-skel-label') + '<div class="ap-skel-track-card"><div class="ap-skel-track-steps"><span></span><span></span><span></span><span></span><span></span></div>' + line('ap-skel-w-88') + line('ap-skel-w-64') + '</div></section>' +
                    '<section>' + line('ap-skel-label') + '<div class="ap-skel-detail-card">' + item + item + '</div></section>' +
                    '<section>' + line('ap-skel-label') + '<div class="ap-skel-summary-card">' + line('ap-skel-w-92') + line('ap-skel-w-86') + line('ap-skel-w-78') + line('ap-skel-w-94 ap-skel-total') + '</div></section>' +
                    '<section>' + line('ap-skel-label') + '<div class="ap-skel-address-card">' + line('ap-skel-w-34') + line('ap-skel-w-74') + line('ap-skel-w-68') + line('ap-skel-w-48') + '</div></section>' +
                '</div>';
            }

            if (type === 'addresses') {
                return '<div class="ap-skel ap-skel-addresses">' +
                    '<div class="ap-skel-address-head">' + line('ap-skel-w-38') + line('ap-skel-button') + '</div>' +
                    [1, 2].map(() =>
                        '<div class="ap-skel-address-card"><div class="ap-skel-address-top"><span class="ap-skel-icon"></span>' + line('ap-skel-w-36') + '</div>' + line('ap-skel-w-62') + line('ap-skel-w-78') + line('ap-skel-w-44') + '</div>'
                    ).join('') +
                '</div>';
            }

            return '<div class="ap-skel ap-skel-account">' +
                [1, 2, 3, 4].map(() =>
                    '<div class="ap-skel-menu-row"><span class="ap-skel-icon"></span><div>' + line('ap-skel-w-40') + line('ap-skel-w-28') + '</div><span class="ap-skel-dot"></span></div>'
                ).join('') +
            '</div>';
        },

        enhanceMobileAccountContent(url, title) {
            if (this.enhanceMobileOrderDetail(url, title)) return;
            if (this.enhanceMobileAccountDetails(url, title)) return;

            const isOrders = (url || '').indexOf('/orders') !== -1 || String(title || '').toLowerCase() === 'orders';
            if (!isOrders) return;

            const $body = $('#ap-acct-modal-body');
            const $table = $body.find('.woocommerce-orders-table').first();
            if (!$table.length) return;

            const esc = (value) => $('<div>').text(value || '').html();
            const cards = [];

            $table.find('tbody tr').each((_, row) => {
                const $row = $(row);
                const $numberCell = $row.find('.woocommerce-orders-table__cell-order-number').first();
                const $dateCell = $row.find('.woocommerce-orders-table__cell-order-date').first();
                const $statusCell = $row.find('.woocommerce-orders-table__cell-order-status').first();
                const $totalCell = $row.find('.woocommerce-orders-table__cell-order-total').first();
                const $orderLink = $numberCell.find('a').first();

                const href = $orderLink.attr('href') || $row.find('.woocommerce-button.view').attr('href') || '#';
                const number = $.trim($orderLink.text() || $numberCell.text());
                const date = $.trim($dateCell.find('time').text() || $dateCell.text());
                const status = $.trim($statusCell.text());
                const totalText = $.trim($totalCell.text()).replace(/\s+/g, ' ');
                const amount = $.trim($totalCell.find('.woocommerce-Price-amount').last().text()) || totalText.replace(/\s+for\s+\d+\s+items?.*$/i, '');
                const itemMatch = totalText.match(/(\d+)\s+items?/i);
                const itemCount = itemMatch ? itemMatch[1] + ' item' + (itemMatch[1] === '1' ? '' : 's') : '';
                const statusClass = status.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'default';

                if (!number) return;

                cards.push(
                    '<a class="ap-mobile-order-card" href="' + esc(href) + '">' +
                        '<span class="ap-mobile-order-main">' +
                            '<strong>' + esc(number) + '</strong>' +
                            (date ? '<small>' + esc(date) + '</small>' : '') +
                            (itemCount ? '<em>' + esc(itemCount) + '</em>' : '') +
                        '</span>' +
                        '<span class="ap-mobile-order-side">' +
                            (status ? '<small class="ap-mobile-order-status ap-mobile-order-status--' + esc(statusClass) + '">' + esc(status) + '</small>' : '') +
                            (amount ? '<strong>' + esc(amount) + '</strong>' : '') +
                        '</span>' +
                    '</a>'
                );
            });

            if (!cards.length) return;

            $('#ap-acct-modal').addClass('ap-acct-modal--orders');
            $body.html('<div class="ap-mobile-orders-list">' + cards.join('') + '</div>');
        },

        enhanceMobileOrderDetail(url, title) {
            const isDetail = (url || '').indexOf('/view-order/') !== -1;
            if (!isDetail) return false;

            const $body = $('#ap-acct-modal-body');
            const $details = $body.find('.woocommerce-order-details').first();
            const $itemsTable = $body.find('.woocommerce-table--order-details, .order_details').first();
            if (!$details.length && !$itemsTable.length) return false;

            const esc = (value) => $('<div>').text(value || '').html();
            const clean = (value) => $.trim(String(value || '').replace(/\s+/g, ' '));
            const pageText = clean($body.text());
            const orderMatch = pageText.match(/Order\s+#?([A-Z0-9-]+)/i) || String(url || '').match(/view-order\/(\d+)/i);
            const orderNo = clean(title).match(/^#/) ? clean(title) : '#' + (orderMatch ? orderMatch[1] : clean(title || 'Order'));
            const statusMatch = pageText.match(/currently\s+([A-Za-z -]+)\./i);
            const status = statusMatch ? clean(statusMatch[1]) : '';

            $('#ap-acct-modal-title').text(orderNo);

            const items = [];
            const fallbackThumb = '<svg viewBox="0 0 24 24"><path d="M9 4l3 2 3-2 4 2-2 5-2-1v10H9V10l-2 1-2-5 4-2z"/></svg>';
            $itemsTable.find('tbody tr, tr.woocommerce-table__line-item, tr.order_item').each((_, row) => {
                const $row = $(row);
                const $nameCell = $row.find('.woocommerce-table__product-name, .product-name').first();
                const $totalCell = $row.find('.woocommerce-table__product-total, .product-total').first();
                if (!$nameCell.length || !$totalCell.length) return;

                const name = clean($nameCell.find('a').first().text() || $nameCell.clone().children().remove().end().text() || $nameCell.text()).replace(/\s*[×x]\s*\d+\s*$/, '');
                const qtyMatch = $nameCell.text().match(/[×x]\s*(\d+)/);
                const qty = qtyMatch ? qtyMatch[1] : '';
                const meta = $nameCell.find('.wc-item-meta li, .wc-item-meta p, .variation dt, .variation dd').map((__, el) => clean($(el).text())).get().filter(Boolean).join(' · ');
                const total = clean($totalCell.find('.woocommerce-Price-amount').last().text() || $totalCell.text());
                if (!name) return;

                const $thumbImg = $row.find('.product-thumbnail img, .woocommerce-table__product-thumbnail img, td.product-name img, .woocommerce-table__product-name img').first();
                const thumbSrc = $thumbImg.attr('data-src') || $thumbImg.attr('src') || '';
                const thumbHtml = thumbSrc
                    ? '<img src="' + esc(thumbSrc) + '" alt="' + esc(name) + '" loading="lazy">'
                    : fallbackThumb;

                items.push(
                    '<div class="ap-mobile-detail-item">' +
                        '<span class="ap-mobile-detail-thumb">' + thumbHtml + '</span>' +
                        '<span class="ap-mobile-detail-item-main"><strong>' + esc(name) + '</strong>' +
                            '<small>' + esc([meta, qty ? 'Qty: ' + qty : ''].filter(Boolean).join(' · ')) + '</small></span>' +
                        '<span class="ap-mobile-detail-price">' + esc(total) + '</span>' +
                    '</div>'
                );
            });

            const totals = [];
            $itemsTable.find('tfoot tr').each((_, row) => {
                const $row = $(row);
                const label = clean($row.find('th').first().text()).replace(/:$/, '');
                const value = clean($row.find('td').first().text());
                if (!label || !value) return;
                const isTotal = label.toLowerCase() === 'total';
                totals.push(
                    '<div class="ap-mobile-summary-row' + (isTotal ? ' ap-mobile-summary-row--total' : '') + '">' +
                        '<span>' + esc(label) + '</span><strong>' + esc(value) + '</strong>' +
                    '</div>'
                );
            });

            const $address = $body.find('.woocommerce-customer-details address, address').first();
            const addressHtml = $address.length
                ? $address.html().replace(/<br\s*\/?>/gi, '\n').replace(/<\/?[^>]+>/g, '\n').split('\n').map(clean).filter(Boolean)
                : [];

            const trackingLine = pageText.match(/(Shipped via[^.]+Tracking:[^.]+)/i);
            const trackingHtml = trackingLine
                ? esc(trackingLine[1]).replace(/(Tracking:\s*)/i, '$1')
                : (status ? 'Current status: <strong>' + esc(status) + '</strong>' : 'Order is being prepared.');

            const baseSteps = ['Order Placed', 'Confirmed', 'Processing'];
            const statusAlias = {
                'pending': 0, 'pending payment': 0, 'placed': 0, 'order placed': 0, 'on hold': 0, 'on-hold': 0,
                'confirmed': 1, 'approved': 1,
                'processing': 2, 'in progress': 2, 'preparing': 2,
                'shipped': 2, 'out for delivery': 2, 'in transit': 2, 'dispatched': 2,
                'completed': 3, 'complete': 3, 'delivered': 3,
                'cancelled': 3, 'canceled': 3, 'failed': 3,
                'refunded': 3,
            };
            const resolveFinal = (key) => {
                if (!key) return { label: 'Completed', tone: 'completed' };
                if (key.indexOf('cancel') !== -1 || key === 'failed') return { label: 'Cancelled', tone: 'cancelled' };
                if (key.indexOf('refund') !== -1) return { label: 'Refunded', tone: 'refunded' };
                return { label: 'Completed', tone: 'completed' };
            };
            const statusKey = String(status || '').toLowerCase().trim();
            let activeIndex = -1;
            if (statusKey && Object.prototype.hasOwnProperty.call(statusAlias, statusKey)) {
                activeIndex = statusAlias[statusKey];
            } else if (statusKey) {
                Object.keys(statusAlias).forEach((alias) => {
                    if (statusKey.indexOf(alias) !== -1 && statusAlias[alias] > activeIndex) activeIndex = statusAlias[alias];
                });
                if (activeIndex === -1) {
                    baseSteps.forEach((step, idx) => {
                        if (statusKey.indexOf(step.toLowerCase()) !== -1 && idx > activeIndex) activeIndex = idx;
                    });
                }
            }
            if (activeIndex < 0) activeIndex = 0;
            const finalStep = resolveFinal(statusKey);
            const steps = baseSteps.concat([finalStep.label]);
            const buildStepHtml = (stepLabels, idxActive, finalTone) => stepLabels.map((step, index) => {
                const isLast = index === stepLabels.length - 1;
                const cls = ['ap-mobile-track-step'];
                if (index <= idxActive) cls.push('is-done');
                if (index === idxActive) cls.push('is-current');
                const isNegativeFinal = isLast && (finalTone === 'cancelled' || finalTone === 'refunded');
                if (isLast && finalTone) cls.push('is-' + finalTone);
                const mark = (index <= idxActive)
                    ? (isNegativeFinal && index === idxActive ? '&#10005;' : '&#10003;')
                    : '';
                return '<span class="' + cls.join(' ') + '">' +
                    '<i>' + mark + '</i><small>' + esc(step) + '</small>' +
                '</span>';
            }).join('');
            const stepHtml = buildStepHtml(steps, activeIndex, finalStep.tone);

            $('#ap-acct-modal').addClass('ap-acct-modal--order-detail');
            $body.html(
                '<div class="ap-mobile-detail-page">' +
                    '<section class="ap-mobile-detail-section"><h3>ORDER TRACKING</h3>' +
                        '<div class="ap-mobile-track-card"><div class="ap-mobile-track-steps">' + stepHtml + '</div>' +
                        '<div class="ap-mobile-track-note">' + trackingHtml + '</div></div></section>' +
                    (items.length ? '<section class="ap-mobile-detail-section"><h3>ITEMS ORDERED</h3><div class="ap-mobile-detail-card">' + items.join('') + '</div></section>' : '') +
                    (totals.length ? '<section class="ap-mobile-detail-section"><h3>ORDER SUMMARY</h3><div class="ap-mobile-summary-card">' + totals.join('') + '</div></section>' : '') +
                    (addressHtml.length ? '<section class="ap-mobile-detail-section"><h3>SHIPPING ADDRESS</h3><div class="ap-mobile-address-card">' + addressHtml.map((line, index) => index === 0 ? '<strong>' + esc(line) + '</strong>' : '<span>' + esc(line) + '</span>').join('') + '</div></section>' : '') +
                '</div>'
            );

            const orderIdMatch = String(url || '').match(/view-order\/(\d+)/i);
            const orderId = orderIdMatch ? parseInt(orderIdMatch[1], 10) : 0;
            if (orderId && AuthPopup.ajaxUrl && AuthPopup.nonce) {
                $.ajax({
                    url: AuthPopup.ajaxUrl,
                    method: 'POST',
                    data: { action: 'auth_popup_order_items', nonce: AuthPopup.nonce, order_id: orderId },
                }).done((res) => {
                    if (!res || !res.success || !res.data) return;
                    const data = res.data;

                    if (Array.isArray(data.items) && data.items.length) {
                        const $itemEls = $body.find('.ap-mobile-detail-item');
                        data.items.forEach((it, idx) => {
                            const $el = $itemEls.eq(idx);
                            if (!$el.length || !it.thumb) return;
                            $el.find('.ap-mobile-detail-thumb').html('<img src="' + esc(it.thumb) + '" alt="' + esc(it.name || '') + '" loading="lazy">');
                        });
                    }

                    const wcStatus = String(data.status || '').toLowerCase();
                    if (wcStatus) {
                        let next = -1;
                        if (Object.prototype.hasOwnProperty.call(statusAlias, wcStatus)) next = statusAlias[wcStatus];
                        else Object.keys(statusAlias).forEach((alias) => {
                            if (wcStatus.indexOf(alias) !== -1 && statusAlias[alias] > next) next = statusAlias[alias];
                        });
                        if (next < 0) next = 0;
                        const finalNext = resolveFinal(wcStatus);
                        const newSteps = baseSteps.concat([finalNext.label]);
                        const $track = $body.find('.ap-mobile-track-steps');
                        if ($track.length) {
                            $track.removeClass('is-completed is-cancelled is-refunded');
                            if (next === newSteps.length - 1) $track.addClass('is-' + finalNext.tone);
                            $track.html(buildStepHtml(newSteps, next, finalNext.tone));
                        }
                    }
                });
            }

            return true;
        },

        enhanceMobileAccountDetails(url, title) {
            const isAccount = (url || '').indexOf('/edit-account') !== -1 || String(title || '').toLowerCase().indexOf('account') !== -1;
            if (!isAccount) return false;

            const $body = $('#ap-acct-modal-body');
            const $form = $body.find('form.woocommerce-EditAccountForm, form.edit-account').first();
            if (!$form.length || $body.find('.ap-account-details-head').length) return false;

            const summary = AuthPopup.accountSummary || {};
            const esc = (value) => $('<div>').text(value || '').html();
            const initialsSource = summary.name || $form.find('#account_display_name').val() || 'User';
            const initials = initialsSource.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase() || 'U';
            const avatar = summary.avatarUrl
                ? '<img src="' + esc(summary.avatarUrl) + '" alt="">'
                : '<span>' + esc(initials) + '</span>';
            const name = summary.name || $form.find('#account_display_name').val() || '';
            const email = summary.email || $form.find('#account_email').val() || '';
            const eyeIcon = '<svg viewBox="0 0 24 24" fill="none"><path d="M1.5 12s3.8-6 10.5-6 10.5 6 10.5 6-3.8 6-10.5 6-10.5-6-10.5-6z" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="2.6" stroke="currentColor" stroke-width="1.7"/></svg>';

            $('#ap-acct-modal').addClass('ap-acct-modal--account-details');
            $form.before(
                '<div class="ap-account-details-head">' +
                    '<div class="ap-account-details-avatar-wrap">' +
                        '<div class="ap-account-details-avatar">' + avatar + '</div>' +
                        '<span class="ap-account-details-camera"><svg viewBox="0 0 24 24"><path d="M7 7l1.4-2h7.2L17 7h2a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V9a2 2 0 012-2h2z"/><circle cx="12" cy="13" r="3"/></svg></span>' +
                    '</div>' +
                    '<div class="ap-account-details-id">' +
                        '<strong>' + esc(name) + '</strong>' +
                        (email ? '<span>' + esc(email) + '</span>' : '') +
                        '<button type="button" class="ap-account-photo-btn"><svg viewBox="0 0 24 24"><path d="M12 5v10M8 9l4-4 4 4"/><path d="M5 19h14"/></svg>Upload photo</button>' +
                    '</div>' +
                '</div>'
            );

            $form.addClass('ap-account-details-form');
            $form.prepend('<div class="ap-account-section-title">Personal info</div>');
            $form.find('fieldset legend').text('Change password');

            $form.find('input[type="password"]').each((_, input) => {
                const $input = $(input);
                if ($input.parent('.ap-account-pass-wrap').length) return;
                $input.wrap('<span class="ap-account-pass-wrap"></span>');
                $input.after('<button type="button" class="ap-account-pass-toggle" aria-label="Show password" aria-pressed="false">' + eyeIcon + '</button>');
            });

            const $saveRow = $form.children('p').last();
            if ($saveRow.length && !$saveRow.find('.ap-account-details-cancel').length) {
                $saveRow.prepend('<button type="button" class="ap-account-details-cancel">Cancel</button>');
            }

            return true;
        },

        initDatepicker() {
            if (typeof $.fn.datepicker === 'undefined') return;

            const maxDate = new Date();
            maxDate.setFullYear(maxDate.getFullYear() - 10);

            this._dpOpts = {
                dateFormat:  'yy-mm-dd',
                maxDate:     maxDate,
                changeMonth: true,
                changeYear:  true,
                yearRange:   '1940:' + (new Date().getFullYear() - 10),
                showAnim:    'fadeIn',
            };

            // Only attach when the checkbox is already checked on page load
            if (this.$ctx.find('#ap-join-loyalty').is(':checked')) {
                $('#ap-reg-dob').datepicker(this._dpOpts);
            }
            if (this.$ctx.find('#ap-sc-join-loyalty').is(':checked')) {
                $('#ap-sc-dob').datepicker(this._dpOpts);
            }
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
            this.$ctx.on('change', '#ap-sc-join-loyalty', (e) => {
                const $loyaltyFields = $('#ap-sc-loyalty-fields');
                if ($(e.target).is(':checked')) {
                    $('#ap-sc-loyalty-benefits').hide();
                    $loyaltyFields.slideDown(220);
                    if (typeof $.fn.datepicker !== 'undefined') {
                        $('#ap-sc-dob').datepicker(this._dpOpts || {});
                    }
                } else {
                    $loyaltyFields.slideUp(180);
                    $('#ap-sc-loyalty-benefits').show();
                    $loyaltyFields.find('select[name="gender"]').val('');
                    $loyaltyFields.find('input[name="dob"]').val('');
                    if (typeof $.fn.datepicker !== 'undefined') {
                        $('#ap-sc-dob').datepicker('destroy');
                    }
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
