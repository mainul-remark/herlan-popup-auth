/**
 * Auth Popup — Address Manager
 *
 * Two modes:
 *   1. Checkout  — inline address selector injected before order review
 *   2. My Account (/my-account/edit-address/) — full address book with
 *      create / edit / delete / set-default support
 */
( function ( $ ) {
    'use strict';

    var cfg  = window.AuthAddressManager || {};
    var i18n = cfg.i18n || {};

    /* BD mobile number validation — mirrors Auth_Popup_SMS_Service::is_valid_phone() */
    function isValidBDPhone( phone ) {
        var clean = phone.replace( /\D/g, '' );
        return /^(880|00880)?0?1[3-9]\d{8}$/.test( clean );
    }

    /**
     * All 64 Bangladesh districts — codes match WooCommerce's i18n/states.php exactly
     * so values stored in our table drop straight into #billing_state Select2.
     */
    var BD_DISTRICTS = [
        { code: 'BD-05', name: 'Bagerhat' },
        { code: 'BD-01', name: 'Bandarban' },
        { code: 'BD-02', name: 'Barguna' },
        { code: 'BD-06', name: 'Barishal' },
        { code: 'BD-07', name: 'Bhola' },
        { code: 'BD-03', name: 'Bogura' },
        { code: 'BD-04', name: 'Brahmanbaria' },
        { code: 'BD-09', name: 'Chandpur' },
        { code: 'BD-10', name: 'Chattogram' },
        { code: 'BD-12', name: 'Chuadanga' },
        { code: 'BD-11', name: "Cox's Bazar" },
        { code: 'BD-08', name: 'Cumilla' },
        { code: 'BD-13', name: 'Dhaka' },
        { code: 'BD-14', name: 'Dinajpur' },
        { code: 'BD-15', name: 'Faridpur' },
        { code: 'BD-16', name: 'Feni' },
        { code: 'BD-19', name: 'Gaibandha' },
        { code: 'BD-18', name: 'Gazipur' },
        { code: 'BD-17', name: 'Gopalganj' },
        { code: 'BD-20', name: 'Habiganj' },
        { code: 'BD-21', name: 'Jamalpur' },
        { code: 'BD-22', name: 'Jashore' },
        { code: 'BD-25', name: 'Jhalokati' },
        { code: 'BD-23', name: 'Jhenaidah' },
        { code: 'BD-24', name: 'Joypurhat' },
        { code: 'BD-29', name: 'Khagrachhari' },
        { code: 'BD-27', name: 'Khulna' },
        { code: 'BD-26', name: 'Kishoreganj' },
        { code: 'BD-28', name: 'Kurigram' },
        { code: 'BD-30', name: 'Kushtia' },
        { code: 'BD-31', name: 'Lakshmipur' },
        { code: 'BD-32', name: 'Lalmonirhat' },
        { code: 'BD-36', name: 'Madaripur' },
        { code: 'BD-37', name: 'Magura' },
        { code: 'BD-33', name: 'Manikganj' },
        { code: 'BD-39', name: 'Meherpur' },
        { code: 'BD-38', name: 'Moulvibazar' },
        { code: 'BD-35', name: 'Munshiganj' },
        { code: 'BD-34', name: 'Mymensingh' },
        { code: 'BD-48', name: 'Naogaon' },
        { code: 'BD-43', name: 'Narail' },
        { code: 'BD-40', name: 'Narayanganj' },
        { code: 'BD-42', name: 'Narsingdi' },
        { code: 'BD-44', name: 'Natore' },
        { code: 'BD-45', name: 'Nawabganj' },
        { code: 'BD-41', name: 'Netrakona' },
        { code: 'BD-46', name: 'Nilphamari' },
        { code: 'BD-47', name: 'Noakhali' },
        { code: 'BD-49', name: 'Pabna' },
        { code: 'BD-52', name: 'Panchagarh' },
        { code: 'BD-51', name: 'Patuakhali' },
        { code: 'BD-50', name: 'Pirojpur' },
        { code: 'BD-53', name: 'Rajbari' },
        { code: 'BD-54', name: 'Rajshahi' },
        { code: 'BD-56', name: 'Rangamati' },
        { code: 'BD-55', name: 'Rangpur' },
        { code: 'BD-58', name: 'Satkhira' },
        { code: 'BD-62', name: 'Shariatpur' },
        { code: 'BD-57', name: 'Sherpur' },
        { code: 'BD-59', name: 'Sirajganj' },
        { code: 'BD-61', name: 'Sunamganj' },
        { code: 'BD-60', name: 'Sylhet' },
        { code: 'BD-63', name: 'Tangail' },
        { code: 'BD-64', name: 'Thakurgaon' },
    ];

    /* SVG icons */
    var HOUSE_SVG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1H4a1 1 0 01-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>';
    var EDIT_SVG    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    var TRASH_SVG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>';
    var STAR_SVG    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    var STAR_FILLED = '<svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    var PLUS_SVG    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';

    /* ── State ─────────────────────────────────────────────────────── */
    var $modal;
    var cachedAddresses  = [];
    var selectedId       = 0;
    var isCheckout       = cfg.isCheckout === '1';
    var isMyAccount      = cfg.isMyAccount === '1';

    /* ── Boot ───────────────────────────────────────────────────────── */
    $( function () {
        injectModal();
        bindEvents();

        if ( isCheckout ) {
            waitForCheckout( function () {
                injectInlineSections();
                loadAddresses( true );
            } );
        }

        if ( isMyAccount ) {
            // Full page load of /my-account/edit-address/ (desktop)
            loadMyAccountAddresses();
        } else {
            // Mobile: auth-popup.js fetches the page and injects only
            // .woocommerce-MyAccount-content HTML into a modal. The PHP
            // flag isMyAccount is '0' on the parent page, so we watch
            // for #aab-my-account-addresses appearing in the DOM instead.
            watchForMyAccountContainer();
        }
    } );

    /**
     * Use a MutationObserver to detect when the address book container is
     * injected into the DOM by the mobile modal (auth-popup.js fetch flow).
     * Fires loadMyAccountAddresses() exactly once when the node appears.
     */
    function watchForMyAccountContainer() {
        if ( ! window.MutationObserver ) return;

        var observer = new MutationObserver( function ( mutations ) {
            for ( var i = 0; i < mutations.length; i++ ) {
                var nodes = mutations[ i ].addedNodes;
                for ( var j = 0; j < nodes.length; j++ ) {
                    var node = nodes[ j ];
                    if ( node.nodeType !== 1 ) continue;
                    var found = node.id === 'aab-my-account-addresses'
                        ? node
                        : node.querySelector( '#aab-my-account-addresses' );
                    if ( found ) {
                        observer.disconnect();
                        loadMyAccountAddresses();
                        return;
                    }
                }
            }
        } );

        observer.observe( document.body, { childList: true, subtree: true } );
    }

    /* ══════════════════════════════════════════════════════════════════
       MY ACCOUNT ADDRESS BOOK
    ══════════════════════════════════════════════════════════════════ */

    function loadMyAccountAddresses() {
        setMyAccountWrap( '<div class="aab-inline-loading">Loading addresses&hellip;</div>' );

        $.post( cfg.ajaxUrl, { action: 'auth_popup_get_addresses', nonce: cfg.nonce } )
            .done( function ( res ) {
                if ( res.success ) {
                    cachedAddresses = res.data.addresses || [];
                    renderMyAccountList( cachedAddresses );
                } else {
                    setMyAccountWrap( '<div class="aab-inline-empty">Could not load addresses.</div>' );
                }
            } )
            .fail( function () {
                setMyAccountWrap( '<div class="aab-inline-empty">Network error. Please reload the page.</div>' );
            } );
    }

    function setMyAccountWrap( html ) {
        var el = document.getElementById( 'aab-my-account-addresses' );
        if ( el ) {
            el.innerHTML = html;
        }
    }

    function renderMyAccountList( addresses ) {
        var addBtn = '<button type="button" class="aab-ma-add-btn" title="' + esc( i18n.add_new || 'Add New Address' ) + '">'
            + '<span class="aab-ma-add-btn__icon">' + PLUS_SVG + '</span>'
            + '<span>' + esc( i18n.add_new || 'Add New Address' ) + '</span>'
            + '</button>';

        var html = '<div class="aab-ma-header">'
            + '<h2 class="aab-ma-title">' + esc( i18n.my_addresses || 'My Addresses' ) + '</h2>'
            + addBtn
            + '</div>';

        if ( ! addresses.length ) {
            html += '<div class="aab-ma-empty">'
                + '<div class="aab-ma-empty__icon">' + HOUSE_SVG + '</div>'
                + '<p class="aab-ma-empty__text">' + esc( i18n.no_addresses || 'No saved addresses yet.' ) + '</p>'
                + '</div>';
        } else {
            html += '<div class="aab-ma-grid">'
                + addresses.map( renderMyAccountCard ).join( '' )
                + '</div>';
        }

        setMyAccountWrap( html );
    }

    function renderMyAccountCard( addr ) {
        var isDefault = +addr.is_default === 1;
        var cardLabel = addr.label ? esc( addr.label ) : esc( addr.first_name );
        var fullName  = esc( addr.first_name + ( addr.last_name ? ' ' + addr.last_name : '' ) );
        var district  = codeToDistrict( addr.state );

        var lines = [];
        if ( fullName )      lines.push( fullName );
        if ( addr.phone )    lines.push( esc( addr.phone ) );
        if ( addr.address_1) lines.push( esc( addr.address_1 ) );
        if ( addr.address_2) lines.push( esc( addr.address_2 ) );
        var distLine = [ district, addr.postcode ].filter( Boolean ).map( esc ).join( ', ' );
        if ( distLine )      lines.push( distLine );

        var defaultBadge = isDefault
            ? '<span class="aab-default-badge">' + STAR_FILLED + esc( i18n.default_badge || 'Default' ) + '</span>'
            : '';

        var starBtn = ! isDefault
            ? '<button type="button" class="aab-ma-icon-btn aab-ma-default-btn" data-id="' + addr.id + '" title="' + esc( i18n.set_default || 'Set as Default' ) + '" aria-label="' + esc( i18n.set_default || 'Set as Default' ) + '">' + STAR_SVG + '</button>'
            : '';

        return [
            '<div class="aab-ma-card' + ( isDefault ? ' aab-ma-card--default' : '' ) + '" data-id="' + addr.id + '">',
            '  <div class="aab-ma-card-head">',
            '    <div class="aab-ma-card-label-wrap">',
            '      <span class="aab-ma-card-icon' + ( isDefault ? ' aab-ma-card-icon--default' : '' ) + '">' + HOUSE_SVG + '</span>',
            '      <span class="aab-ma-card-label">' + cardLabel + '</span>',
            '    </div>',
            '    <div class="aab-ma-card-actions">',
            starBtn,
            '      <button type="button" class="aab-ma-icon-btn aab-ma-edit-btn" data-id="' + addr.id + '" title="Edit" aria-label="Edit address">' + EDIT_SVG + '</button>',
            '      <button type="button" class="aab-ma-icon-btn aab-ma-delete-btn" data-id="' + addr.id + '" title="Delete" aria-label="Delete address">' + TRASH_SVG + '</button>',
            '    </div>',
            '  </div>',
            '  <div class="aab-ma-card-body">',
            lines.map( function ( l ) { return '<p class="aab-ma-addr-line">' + l + '</p>'; } ).join( '' ),
            '  </div>',
            defaultBadge ? '  <div class="aab-ma-card-foot">' + defaultBadge + '</div>' : '',
            '</div>',
        ].join( '' );
    }

    function deleteAddress( id ) {
        $.post( cfg.ajaxUrl, {
            action:     'auth_popup_delete_address',
            nonce:      cfg.nonce,
            address_id: id,
        } ).done( function ( res ) {
            if ( res.success ) {
                cachedAddresses = res.data.addresses || [];
                renderMyAccountList( cachedAddresses );
            }
        } );
    }

    function setDefaultAddress( id ) {
        /* Disable the star button immediately for instant feedback */
        var $btn = $( '.aab-ma-default-btn[data-id="' + id + '"]' );
        $btn.prop( 'disabled', true ).addClass( 'aab-ma-icon-btn--loading' );

        $.post( cfg.ajaxUrl, {
            action:     'auth_popup_set_default_address',
            nonce:      cfg.nonce,
            address_id: id,
        } ).done( function ( res ) {
            if ( res.success ) {
                cachedAddresses = res.data.addresses || [];
                renderMyAccountList( cachedAddresses );
            }
        } ).fail( function () {
            /* Re-enable on network error so user can retry */
            $btn.prop( 'disabled', false ).removeClass( 'aab-ma-icon-btn--loading' );
        } );
    }

    /* ══════════════════════════════════════════════════════════════════
       CHECKOUT INLINE SECTIONS
    ══════════════════════════════════════════════════════════════════ */

    /* ── Wait for WooCommerce checkout DOM ──────────────────────────── */
    function waitForCheckout( cb ) {
        var attempts = 0;
        var interval = setInterval( function () {
            if ( $( '.woocommerce-checkout' ).length || $( 'form.checkout' ).length ) {
                clearInterval( interval );
                cb();
            }
            if ( ++attempts > 30 ) { clearInterval( interval ); }
        }, 200 );
    }

    /* ── Inject two placeholder sections into the DOM ───────────────── */
    function injectInlineSections() {
        var sectionHtml = function ( id ) {
            return [
                '<div id="' + id + '" class="aab-inline-section">',
                '  <h3 class="aab-section-title">Addresses</h3>',
                '  <div class="aab-card-list-wrap"></div>',
                '  <button type="button" class="aab-add-new-inline">+ ' + esc( i18n.add_new ) + '</button>',
                '</div>',
            ].join( '' );
        };

        /* Desktop: before #order_review so it occupies the left column */
        var $orderReview = $( '#order_review' ).first();
        if ( $orderReview.length && ! $( '#aab-section-desktop' ).length ) {
            $orderReview.before( sectionHtml( 'aab-section-desktop' ) );
        }

        /* Mobile: after #customer_details */
        var $customerDetails = $( '#customer_details' ).first();
        if ( $customerDetails.length && ! $( '#aab-section-mobile' ).length ) {
            $customerDetails.after( sectionHtml( 'aab-section-mobile' ) );
        }
    }

    /* ── Load addresses from server ─────────────────────────────────── */
    function loadAddresses( applyDefault ) {
        setWrap( '<div class="aab-inline-loading">Loading addresses&hellip;</div>' );

        $.post( cfg.ajaxUrl, { action: 'auth_popup_get_addresses', nonce: cfg.nonce } )
            .done( function ( res ) {
                if ( res.success ) {
                    cachedAddresses = res.data.addresses || [];
                    renderInlineList( cachedAddresses );

                    /* Auto-select and auto-apply the default address on first load */
                    if ( applyDefault && cachedAddresses.length ) {
                        var def = cachedAddresses.filter( function ( a ) { return +a.is_default; } )[ 0 ]
                                  || cachedAddresses[ 0 ];
                        selectedId = +def.id;
                        applyToCheckout( def );
                        markSelected( selectedId );
                    }

                    /* Hide billing form when user has saved addresses (if setting enabled) */
                    if ( isCheckout && cfg.hideShippingForm === '1' ) {
                        manageBillingFormVisibility( cachedAddresses.length > 0 );
                    }
                }
            } );
    }

    /* ── Render the card list into both sections ────────────────────── */
    function renderInlineList( addresses ) {
        if ( ! addresses.length ) {
            setWrap( '<div class="aab-inline-empty">No saved addresses. Add one below.</div>' );
            return;
        }

        var html = '<div class="aab-card-list">'
            + addresses.map( renderCard ).join( '' )
            + '</div>';

        setWrap( html );
    }

    function setWrap( html ) {
        $( '#aab-section-desktop .aab-card-list-wrap, #aab-section-mobile .aab-card-list-wrap' ).html( html );
    }

    function markSelected( id ) {
        $( '.aab-addr-card' ).each( function () {
            var isThis = +$( this ).data( 'id' ) === +id;
            $( this ).toggleClass( 'aab-addr-card--selected', isThis );
        } );
    }

    /* ── Render a single address card (checkout inline) ─────────────── */
    function renderCard( addr ) {
        var isSelected = +addr.id === +selectedId;
        var cardName   = addr.label
            ? esc( addr.label )
            : esc( addr.first_name );
        var district   = codeToDistrict( addr.state );
        var metaLines  = [ addr.phone, [ addr.address_1, district ].filter( Boolean ).join( ', ' ) ]
                            .filter( Boolean )
                            .join( '<br>' );

        return [
            '<div class="aab-addr-card' + ( isSelected ? ' aab-addr-card--selected' : '' ) + '" data-id="' + addr.id + '">',
            '  <div class="aab-radio-dot"></div>',
            '  <div class="aab-addr-icon">' + HOUSE_SVG + '</div>',
            '  <div class="aab-addr-info">',
            '    <div class="aab-addr-name">' + cardName + '</div>',
            '    <div class="aab-addr-meta">' + metaLines + '</div>',
            '  </div>',
            '  <button type="button" class="aab-edit-inline-btn" data-id="' + addr.id + '">Edit</button>',
            '</div>',
        ].join( '' );
    }

    /* ── Event Binding ──────────────────────────────────────────────── */
    function bindEvents() {
        $( document )

            /* Select a card (checkout inline — clicking anywhere except Edit) */
            .on( 'click', '.aab-addr-card', function ( e ) {
                if ( $( e.target ).closest( '.aab-edit-inline-btn' ).length ) return;
                var id   = +$( this ).data( 'id' );
                var addr = findAddress( id );
                if ( ! addr ) return;

                selectedId = id;
                markSelected( id );
                applyToCheckout( addr );
            } )

            /* Edit button (checkout inline) */
            .on( 'click', '.aab-edit-inline-btn', function ( e ) {
                e.stopPropagation();
                openForm( +$( this ).data( 'id' ) );
            } )

            /* Add new address (checkout inline section buttons) */
            .on( 'click', '.aab-add-new-inline', function () {
                openForm( 0 );
            } )

            /* ── My Account events ── */

            /* Add new address (my-account header button) */
            .on( 'click', '.aab-ma-add-btn', function () {
                openForm( 0 );
            } )

            /* Edit button (my-account card) */
            .on( 'click', '.aab-ma-edit-btn', function ( e ) {
                e.stopPropagation();
                openForm( +$( this ).data( 'id' ) );
            } )

            /* Set as Default button (my-account card) */
            .on( 'click', '.aab-ma-default-btn', function ( e ) {
                e.stopPropagation();
                setDefaultAddress( +$( this ).data( 'id' ) );
            } )

            /* Delete button (my-account card) */
            .on( 'click', '.aab-ma-delete-btn', function ( e ) {
                e.stopPropagation();
                var id  = +$( this ).data( 'id' );
                var msg = i18n.delete_confirm || 'Delete this address? This cannot be undone.';
                if ( ! window.confirm( msg ) ) return;
                deleteAddress( id );
            } )

            /* Modal close */
            .on( 'click', '.aab-overlay, .aab-modal-close', closeModal )

            /* Form submit */
            .on( 'submit', '.aab-form', function ( e ) {
                e.preventDefault();
                submitForm( $( this ) );
            } )

            /* Cancel button */
            .on( 'click', '.aab-cancel-btn', closeModal );

        /* Phone field — validate on blur */
        $( document ).on( 'blur', '.aab-form [name="phone"]', function () {
            validatePhoneField( $( this ) );
        } );

        /* Clear phone error as user types */
        $( document ).on( 'input', '.aab-form [name="phone"]', function () {
            if ( $( this ).val().replace( /\D/g, '' ).length >= 11 ) {
                validatePhoneField( $( this ) );
            } else {
                clearFieldError( $( this ) );
            }
        } );

        $( document ).on( 'keyup', function ( e ) {
            if ( e.key === 'Escape' ) closeModal();
        } );
    }

    /* ── Modal (form only) ──────────────────────────────────────────── */
    function injectModal() {
        var districtOptions = '<option value="">— Select District —</option>'
            + BD_DISTRICTS.map( function ( d ) {
                return '<option value="' + d.code + '">' + esc( d.name ) + '</option>';
            } ).join( '' );

        var html = [
            '<div id="auth-address-modal" class="aab-modal" style="display:none">',
            '  <div class="aab-overlay"></div>',
            '  <div class="aab-panel">',
            '    <div class="aab-modal-header">',
            '      <h3 class="aab-modal-title"></h3>',
            '      <button type="button" class="aab-modal-close" aria-label="Close">&times;</button>',
            '    </div>',
            '    <div class="aab-modal-body">',
            '      <form class="aab-form" novalidate>',
            '        <input type="hidden" name="address_id" value="0">',

            '        <div class="aab-field">',
            '          <label>Label <span class="aab-optional">(e.g. Home, Office)</span></label>',
            '          <input type="text" name="label" placeholder="My Home" maxlength="100">',
            '        </div>',

            '        <div class="aab-field">',
            '          <label>Full Name <span class="aab-req">*</span></label>',
            '          <input type="text" name="first_name" required maxlength="100" placeholder="নাম / Name">',
            '        </div>',

            '        <div class="aab-field">',
            '          <label>Mobile Number <span class="aab-req">*</span></label>',
            '          <input type="tel" name="phone" required maxlength="20" placeholder="01XXXXXXXXX">',
            '        </div>',

            '        <div class="aab-field">',
            '          <label>Address <span class="aab-req">*</span></label>',
            '          <textarea name="address_1" required rows="3" placeholder="House/Flat, Road, Area"></textarea>',
            '        </div>',

            '        <div class="aab-field">',
            '          <label>District <span class="aab-req">*</span></label>',
            '          <select name="state" required>' + districtOptions + '</select>',
            '        </div>',

            '        <input type="hidden" name="country" value="BD">',

            '        <div class="aab-form-error" style="display:none"></div>',

            '        <div class="aab-form-actions">',
            '          <button type="submit" class="aab-submit-btn">' + esc( i18n.save ) + '</button>',
            '          <button type="button" class="aab-cancel-btn">' + esc( i18n.cancel ) + '</button>',
            '        </div>',

            '      </form>',
            '    </div>',
            '  </div>',
            '</div>',
        ].join( '\n' );

        $( 'body' ).append( html );
        $modal = $( '#auth-address-modal' );
    }

    function openForm( id ) {
        var $form = $modal.find( '.aab-form' );

        $form[ 0 ].reset();
        $form.find( '[name="address_id"]' ).val( id );
        $form.find( '.aab-form-error' ).hide().text( '' );
        $modal.find( '.aab-modal-title' ).text( id > 0 ? i18n.edit_address : i18n.add_address );

        if ( id > 0 ) {
            var addr = findAddress( id );
            if ( addr ) {
                $.each( addr, function ( key, val ) {
                    $form.find( '[name="' + key + '"]' ).val( val );
                } );
            }
        }

        $modal.fadeIn( 180 );
        $( 'body' ).addClass( 'aab-modal-open' );
    }

    function closeModal() {
        $modal.fadeOut( 180 );
        $( 'body' ).removeClass( 'aab-modal-open' );
    }

    function submitForm( $form ) {
        var $btn      = $form.find( '.aab-submit-btn' );
        var $err      = $form.find( '.aab-form-error' );
        var $phoneEl  = $form.find( '[name="phone"]' );

        // Client-side phone validation before hitting the server
        if ( ! validatePhoneField( $phoneEl ) ) {
            $phoneEl[ 0 ].focus();
            return;
        }

        $btn.prop( 'disabled', true ).text( i18n.saving );
        $err.hide();

        var data = { action: 'auth_popup_save_address', nonce: cfg.nonce };
        $.each( $form.serializeArray(), function ( _, p ) { data[ p.name ] = p.value; } );
        data.address_1 = $form.find( '[name="address_1"]' ).val();

        $.post( cfg.ajaxUrl, data )
            .done( function ( res ) {
                if ( res.success ) {
                    cachedAddresses = res.data.addresses || [];

                    if ( isMyAccount || document.getElementById( 'aab-my-account-addresses' ) ) {
                        closeModal();
                        renderMyAccountList( cachedAddresses );
                    } else {
                        /* Checkout mode: select the newly saved address */
                        var savedId = +res.data.address_id;
                        if ( savedId ) selectedId = savedId;

                        renderInlineList( cachedAddresses );
                        markSelected( selectedId );

                        var saved = findAddress( selectedId );
                        if ( saved ) applyToCheckout( saved );

                        closeModal();
                    }
                } else {
                    var msg = res.data && res.data.message ? res.data.message : 'Error saving address.';
                    $err.text( msg ).show();
                }
            } )
            .fail( function () { $err.text( 'Network error. Please try again.' ).show(); } )
            .always( function () { $btn.prop( 'disabled', false ).text( i18n.save ); } );
    }

    /* ── Billing form hide / show ────────────────────────────────────── */

    var billingFormHidden = false;

    /**
     * Hide the billing field wrapper and inject a toggle button when the
     * user has at least one saved address. Does nothing if already set up.
     */
    function manageBillingFormVisibility( hasAddresses ) {
        var $wrapper = $( '.woocommerce-billing-fields__field-wrapper' );
        var $heading = $( '.woocommerce-billing-fields h3' ).first();
        if ( ! $wrapper.length ) return;

        if ( ! hasAddresses ) {
            /* No saved addresses — ensure form is always visible */
            $wrapper.show();
            $heading.show();
            billingFormHidden = false;
            return;
        }

        /* Already hidden — nothing to do */
        if ( billingFormHidden ) return;

        /* Hide the heading and form fields */
        $heading.hide();
        $wrapper.hide();
        billingFormHidden = true;
    }

    /* ── Apply address to WooCommerce checkout fields ────────────────── */
    function applyToCheckout( addr ) {
        if ( ! isCheckout ) return;

        fillField( '#billing_first_name', addr.first_name );
        fillField( '#billing_phone',      addr.phone );
        fillField( '#billing_address_1',  addr.address_1 );

        /* billing_state is a Select2 dropdown.
           Set the underlying <select> value first, then notify Select2. */
        if ( addr.state ) {
            applyStateField( addr.state );
        }

        $( 'body' ).trigger( 'update_checkout' );
    }

    function applyStateField( code ) {
        var $state = $( '#billing_state' );
        if ( ! $state.length ) return;

        // Plain <select> (no Select2 yet, or after WC destroys/rebuilds it)
        $state.val( code );

        // Notify Select2 if it has been initialised on this element
        if ( $state.hasClass( 'select2-hidden-accessible' ) ) {
            $state.trigger( 'change' );
        } else {
            // Select2 may not be ready — wait one tick then trigger
            setTimeout( function () {
                $state.val( code ).trigger( 'change' );
            }, 50 );
        }
    }

    function fillField( selector, value ) {
        if ( ! value ) return;
        var $el = $( selector );
        if ( $el.length ) {
            $el.val( value ).trigger( 'input' ).trigger( 'change' );
        }
    }

    /* ── Field validation helpers ────────────────────────────────────── */

    /**
     * Validate the phone field and show/clear an inline error beneath it.
     * Returns true if valid.
     */
    function validatePhoneField( $input ) {
        var val = $input.val().trim();

        if ( val === '' ) {
            setFieldError( $input, 'Mobile number is required.' );
            return false;
        }

        if ( ! isValidBDPhone( val ) ) {
            setFieldError( $input, 'Enter a valid BD number (e.g. 01712345678).' );
            return false;
        }

        clearFieldError( $input );
        return true;
    }

    function setFieldError( $input, message ) {
        $input.addClass( 'aab-field-invalid' );
        var $wrap = $input.closest( '.aab-field' );
        $wrap.find( '.aab-field-msg' ).remove();
        $wrap.append( '<span class="aab-field-msg aab-field-msg--error">' + esc( message ) + '</span>' );
    }

    function clearFieldError( $input ) {
        $input.removeClass( 'aab-field-invalid' );
        $input.closest( '.aab-field' ).find( '.aab-field-msg' ).remove();
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */
    function findAddress( id ) {
        return cachedAddresses.filter( function ( a ) { return +a.id === +id; } )[ 0 ] || null;
    }

    function codeToDistrict( code ) {
        var match = BD_DISTRICTS.filter( function ( d ) { return d.code === code; } )[ 0 ];
        return match ? match.name : ( code || '' );
    }

    function esc( str ) {
        return String( str || '' )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

} )( jQuery );
