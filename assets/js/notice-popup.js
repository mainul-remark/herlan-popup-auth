(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var overlay  = document.getElementById('ap-notice-overlay');
        var closeBtn = document.getElementById('apn-close-btn');
        var loginBtn = document.getElementById('apn-login-btn');

        if (!overlay) {
            return;
        }

        var mask = overlay.querySelector('.apn-mask');

        function show() {
            overlay.style.display = 'flex';
        }

        function hide() {
            overlay.style.display = 'none';
        }

        closeBtn && closeBtn.addEventListener('click', hide);
        mask && mask.addEventListener('click', hide);

        loginBtn && loginBtn.addEventListener('click', function () {
            hide();
            if (window.AuthPopupInstance && typeof window.AuthPopupInstance.open === 'function') {
                window.AuthPopupInstance.open();
            }
        });

        // Exposed so auth-popup.js can trigger this from the
        // "Continue as Guest" click handler.
        window.APNotice = { show: show, hide: hide };
    });
})();
