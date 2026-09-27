/* ================================================================
   PCIO VIS Member Event — profile.js  v1.2.0
   Inline edit-profile form for plain members ([pcio_me_edit_profile]).
   ================================================================ */

( function () {
    'use strict';

    const CFG         = window.PCIO_ME_PROFILE || {};
    const REST        = ( ( CFG.rest || '' ) + '/members' ).replace( /([^:]\/)\/+/g, '$1' );
    const BOAT_REST   = ( CFG.boatRest || '' ).replace( /\/$/, '' );
    const HARBOURS_REST = CFG.harboursRest || '';
    const NONCE       = CFG.nonce    || '';
    const MEMBER_ID   = CFG.memberId || 0;
    const T           = CFG.i18n     || {};
    const ALL_FIELDS  = CFG.memberFields || [];
    const META_FIELDS = ALL_FIELDS.filter( f => f.meta );
    const BOATS       = CFG.boats    || [];
    const RENEW_URL         = CFG.renewUrl        || '';
    let   allHarbours = [];

    // ── Bootstrap ─────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', () => {
        const app = document.getElementById( 'pcio-me-profile-app' );
        if ( ! app || ! MEMBER_ID ) return;
        if ( HARBOURS_REST && BOATS.length ) { loadHarbours(); }
        buildForm( app );
    } );

    function buildForm( app ) {
        const m = CFG.member || {};

        const metaHtml = META_FIELDS.map( f => {
            const val = String( m[ f.key ] ?? '' );

            // Boat names: replaced by the detailed boats section rendered outside the form.
            if ( f.key === 'boat_names' ) {
                return '';
            }

            // Subscription: read-only status + renew button on the same row.
            if ( f.key === 'mep_subscription_status' ) {
                const renew = RENEW_URL
                    ? `<a href="${ escAttr( RENEW_URL ) }" class="me-btn me-btn-secondary"
                          style="text-decoration:none;white-space:nowrap;flex-shrink:0">
                          ${ escHtml( T.btnRenew ) }
                       </a>`
                    : '';
                return `
                <div class="me-form-row full">
                    <div class="me-form-group">
                        <label class="me-form-label">${ escHtml( f.label ) }</label>
                        <div style="display:flex;align-items:center;gap:.75rem">
                            <div style="flex:1;padding:.5rem .75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;color:#374151">
                                ${ escHtml( val || '–' ) }
                            </div>
                            ${ renew }
                        </div>
                    </div>
                </div>`;
            }

            // Generic read-only field: static display, no name attribute → excluded from PUT payload.
            if ( f.profile_readonly ) {
                return `
                <div class="me-form-row full">
                    <div class="me-form-group">
                        <label class="me-form-label">${ escHtml( f.label ) }</label>
                        <div style="padding:.5rem .75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;color:#374151">
                            ${ escHtml( val || '–' ) }
                        </div>
                    </div>
                </div>`;
            }

            // Editable meta field.
            const id  = `pcio-pf-${ escAttr( f.key ) }`;
            const inp = f.type === 'textarea'
                ? `<textarea class="me-form-textarea" id="${ id }" name="${ escAttr( f.key ) }">${ escHtml( val ) }</textarea>`
                : `<input class="me-form-input" type="${ escAttr( f.type ) }" id="${ id }" name="${ escAttr( f.key ) }" value="${ escAttr( val ) }">`;
            return `
            <div class="me-form-row full">
                <div class="me-form-group">
                    <label class="me-form-label" for="${ id }">${ escHtml( f.label ) }</label>
                    ${ inp }
                </div>
            </div>`;
        } ).join( '' );

        app.innerHTML = `
        <div class="me-profile-card me-table-card">
            <div class="me-modal-body" style="padding:1.5rem">
                <h2 style="margin:0 0 1.5rem;font-size:1.4rem;font-weight:700;color:#1e293b;border-left:4px solid #3b82f6;padding-left:.75rem;line-height:1.3">
                    ${ escHtml( T.pageTitle ) }
                </h2>
                <form id="pcio-profile-form" novalidate>
                    <div class="me-form-row">
                        <div class="me-form-group">
                            <label class="me-form-label" for="pcio-pf-member-number">${ escHtml( T.fieldMemberNum ) }</label>
                            <input class="me-form-input" type="text" id="pcio-pf-member-number"
                                   value="${ escAttr( m.member_number || '' ) }"
                                   maxlength="20" readonly
                                   style="background:#f8fafc;cursor:default;color:#64748b">
                        </div>
                        <div class="me-form-group">
                            <label class="me-form-label" for="pcio-pf-name">
                                ${ escHtml( T.fieldName ) } <span class="req">*</span>
                            </label>
                            <input class="me-form-input" type="text" id="pcio-pf-name"
                                   name="name" value="${ escAttr( m.name || '' ) }"
                                   placeholder="${ escAttr( T.phName ) }" maxlength="120" required>
                            <span class="me-field-error" id="pcio-pf-err-name"></span>
                        </div>
                    </div>
                    <div class="me-form-row">
                        <div class="me-form-group">
                            <label class="me-form-label" for="pcio-pf-email">${ escHtml( T.fieldEmail ) }</label>
                            <input class="me-form-input" type="email" id="pcio-pf-email"
                                   name="email" value="${ escAttr( m.email || '' ) }"
                                   placeholder="${ escAttr( T.phEmail ) }" maxlength="120">
                            <span class="me-field-error" id="pcio-pf-err-email"></span>
                        </div>
                        <div class="me-form-group">
                            <label class="me-form-label" for="pcio-pf-phone">${ escHtml( T.fieldPhone ) }</label>
                            <input class="me-form-input" type="tel" id="pcio-pf-phone"
                                   name="phone" value="${ escAttr( m.phone || '' ) }"
                                   placeholder="${ escAttr( T.phPhone ) }" maxlength="40">
                        </div>
                    </div>
                    <div class="me-form-row full">
                        <div class="me-form-group">
                            <label class="me-form-label" for="pcio-pf-address">${ escHtml( T.fieldAddress ) }</label>
                            <textarea class="me-form-textarea" id="pcio-pf-address"
                                      name="address" placeholder="${ escAttr( T.phAddress ) }">${ escHtml( m.address || '' ) }</textarea>
                        </div>
                    </div>
                    ${ metaHtml }
                </form>
                ${ BOATS.length ? renderBoatForms() : '' }
            </div>
            <div style="padding:1rem 1.5rem;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end">
                <button class="me-btn me-btn-primary" id="pcio-pf-btn-save">${ escHtml( T.btnSave ) }</button>
            </div>
        </div>
        <div class="me-toast me-hidden" id="pcio-pf-toast" role="status" aria-live="polite"></div>
        `;

        document.getElementById( 'pcio-pf-btn-save' ).addEventListener( 'click', saveProfile );

        // Wire boat save buttons and harbour pickers.
        document.querySelectorAll( '.pcio-boat-save' ).forEach( btn => {
            btn.addEventListener( 'click', () => saveBoat( +btn.dataset.boatId, btn.dataset.prefix ) );
        } );
        BOATS.forEach( ( b, idx ) => initBoatHarbourPicker( `pcio-bf-${ idx }` ) );
    }

    // ── Boat forms (editable, one per owned boat) ─────────────────
    function renderBoatForms() {
        const boatField    = ALL_FIELDS.find( f => f.key === 'boat_names' );
        const sectionLabel = boatField ? boatField.label : ( T.labelBoatName || 'Boat' );

        const forms = BOATS.map( ( b, idx ) => {
            const pid = `pcio-bf-${ idx }`;
            return `<div class="pcio-boat-form" data-boat-id="${ b.id }"
                style="margin-top:.5rem;padding:.75rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px">
                <div class="me-form-row">
                    <div class="me-form-group">
                        <label class="me-form-label" for="${ pid }-name">${ escHtml( T.labelBoatName || 'Boat name' ) }</label>
                        <input class="me-form-input" type="text" id="${ pid }-name" name="boat_name"
                               value="${ escAttr( b.boat_name || '' ) }" maxlength="200">
                    </div>
                    <div class="me-form-group">
                        <label class="me-form-label" for="${ pid }-type">${ escHtml( T.labelBoatType ) }</label>
                        <input class="me-form-input" type="text" id="${ pid }-type" name="boat_type"
                               value="${ escAttr( b.boat_type || '' ) }" maxlength="100">
                    </div>
                </div>
                <div class="me-form-row">
                    <div class="me-form-group">
                        <label class="me-form-label" for="${ pid }-model">${ escHtml( T.labelBoatModel ) }</label>
                        <input class="me-form-input" type="text" id="${ pid }-model" name="boat_model"
                               value="${ escAttr( b.boat_model || '' ) }" maxlength="100">
                    </div>
                    <div class="me-form-group">
                        <label class="me-form-label" for="${ pid }-year">${ escHtml( T.labelBuilt ) }</label>
                        <input class="me-form-input" type="number" id="${ pid }-year" name="build_year"
                               value="${ escAttr( b.build_year ? String( b.build_year ) : '' ) }"
                               min="1800" max="2100">
                    </div>
                </div>
                <div class="me-form-row">
                    <div class="me-form-group">
                        <label class="me-form-label" for="${ pid }-sail">${ escHtml( T.labelSailId ) }</label>
                        <input class="me-form-input" type="text" id="${ pid }-sail" name="sail_id"
                               value="${ escAttr( b.sail_id || '' ) }" maxlength="50">
                    </div>
                    <div class="me-form-group">
                        <label class="me-form-label" for="${ pid }-mmsi">${ escHtml( T.labelMMSI ) }</label>
                        <input class="me-form-input" type="text" id="${ pid }-mmsi" name="mmsi"
                               value="${ escAttr( b.mmsi || '' ) }" maxlength="20">
                    </div>
                </div>
                <div class="me-form-row">
                    <div class="me-form-group" style="flex:1">
                        <label class="me-form-label" for="${ pid }-harbour">${ escHtml( T.labelHarbour ) }</label>
                        <div style="position:relative">
                            <input class="me-form-input" type="text" id="${ pid }-harbour" name="home_harbour"
                                   value="${ escAttr( b.home_harbour || '' ) }" autocomplete="off" maxlength="200">
                            <input type="hidden" id="${ pid }-harbour-id" name="harbour_id"
                                   value="${ escAttr( String( b.harbour_id || 0 ) ) }">
                            <ul id="${ pid }-harbour-sugg" role="listbox"
                                style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;
                                       background:#fff;border:1px solid #cbd5e1;border-top:none;
                                       border-radius:0 0 6px 6px;list-style:none;margin:0;padding:0;
                                       max-height:200px;overflow-y:auto;box-shadow:0 4px 8px rgba(0,0,0,.10)"></ul>
                        </div>
                    </div>
                </div>
                <div class="me-form-row full">
                    <div class="me-form-group">
                        <label class="me-form-label" for="${ pid }-note">${ escHtml( T.labelNote ) }</label>
                        <textarea class="me-form-textarea" id="${ pid }-note" name="note">${ escHtml( b.note || '' ) }</textarea>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:.75rem">
                    <button type="button" class="me-btn me-btn-primary pcio-boat-save"
                            data-boat-id="${ b.id }" data-prefix="${ pid }">
                        ${ escHtml( T.btnSaveBoat || 'Save boat' ) }
                    </button>
                </div>
            </div>`;
        } ).join( '' );

        return `<div style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid #e2e8f0">
            <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:.25rem">
                ${ escHtml( sectionLabel ) }
            </div>
            ${ forms }
        </div>`;
    }

    // ── Harbour autocomplete for a boat form row ────────────────────
    function initBoatHarbourPicker( prefix ) {
        const input  = document.getElementById( prefix + '-harbour' );
        const hidden = document.getElementById( prefix + '-harbour-id' );
        const sugg   = document.getElementById( prefix + '-harbour-sugg' );
        if ( ! input || ! hidden || ! sugg ) { return; }

        input.addEventListener( 'input', () => {
            const q = input.value.trim().toLowerCase();
            hidden.value = '0';
            if ( ! q ) { hideSugg(); return; }
            const matches = allHarbours.filter( h => h.name.toLowerCase().includes( q ) ).slice( 0, 12 );
            if ( ! matches.length ) { hideSugg(); return; }
            sugg.innerHTML = '';
            matches.forEach( h => {
                const li = document.createElement( 'li' );
                li.textContent = h.name;
                li.style.cssText = 'padding:7px 12px;cursor:pointer;font-size:13.5px;color:#334155;border-bottom:1px solid #f1f5f9';
                li.addEventListener( 'mousedown', e => {
                    e.preventDefault();
                    input.value  = h.name;
                    hidden.value = String( h.id );
                    hideSugg();
                } );
                sugg.appendChild( li );
            } );
            sugg.style.display = 'block';
        } );
        input.addEventListener( 'blur',    () => setTimeout( hideSugg, 150 ) );
        input.addEventListener( 'keydown', e => { if ( e.key === 'Escape' ) hideSugg(); } );
        function hideSugg() { sugg.style.display = 'none'; sugg.innerHTML = ''; }
    }

    // ── Save a single boat ──────────────────────────────────────────
    async function saveBoat( boatId, prefix ) {
        if ( ! BOAT_REST || ! boatId ) { return; }
        const form = document.querySelector( `.pcio-boat-form[data-boat-id="${ boatId }"]` );
        if ( ! form ) { return; }
        const data = {};
        form.querySelectorAll( '[name]' ).forEach( el => {
            const v = el.value.trim();
            if ( el.name === 'build_year' ) {
                data[ el.name ] = v ? parseInt( v, 10 ) : null;
            } else if ( el.name === 'harbour_id' ) {
                data[ el.name ] = parseInt( v || '0', 10 );
            } else {
                data[ el.name ] = v;
            }
        } );
        const btn = form.querySelector( '.pcio-boat-save' );
        if ( btn ) { btn.disabled = true; }
        try {
            const res = await fetch( `${ BOAT_REST }/my-boat/${ boatId }`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( data ),
            } );
            if ( ! res.ok ) {
                const err = await res.json().catch( () => ( {} ) );
                throw new Error( err.message || `HTTP ${ res.status }` );
            }
            showToast( T.toastBoatSaved || 'Boat saved.', 'success' );
        } catch ( err ) {
            showToast( ( T.errBoatSave || 'Save failed: ' ) + err.message, 'error' );
        } finally {
            if ( btn ) { btn.disabled = false; }
        }
    }

    // ── Load harbour list for autocomplete ──────────────────────────
    async function loadHarbours() {
        try {
            const res  = await fetch( HARBOURS_REST );
            const data = await res.json();
            allHarbours = Array.isArray( data ) ? data : [];
        } catch {
            allHarbours = [];
        }
    }

    // ── Save ──────────────────────────────────────────────────────
    async function saveProfile() {
        clearErrors();

        const form = document.getElementById( 'pcio-profile-form' );
        if ( ! form ) return;

        // Collect only named editable fields (readonly fields have no name attribute).
        const data = {};
        form.querySelectorAll( '[name]' ).forEach( el => {
            data[ el.name ] = el.value.trim();
        } );

        // Client-side validation
        let valid = true;
        if ( ! data.name ) {
            setError( 'name', T.errName );
            valid = false;
        }
        if ( data.email && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( data.email ) ) {
            setError( 'email', T.errEmail );
            valid = false;
        }
        if ( ! valid ) return;

        const btn = document.getElementById( 'pcio-pf-btn-save' );
        btn.disabled    = true;
        btn.textContent = T.stateSaving;

        try {
            const res = await fetch( `${ REST }/${ MEMBER_ID }`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( data ),
            } );
            if ( ! res.ok ) {
                const err = await res.json().catch( () => ( {} ) );
                throw new Error( err.message || `HTTP ${ res.status }` );
            }
            showToast( T.toastSaved, 'success' );
        } catch ( err ) {
            showToast( T.errSave + err.message, 'error' );
        } finally {
            btn.disabled    = false;
            btn.textContent = T.btnSave;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────
    let toastTimer = null;

    function showToast( msg, type ) {
        const toast = document.getElementById( 'pcio-pf-toast' );
        if ( ! toast ) return;
        toast.textContent = msg;
        toast.className   = `me-toast ${ type }`;
        if ( toastTimer ) clearTimeout( toastTimer );
        toastTimer = setTimeout( () => toast.classList.add( 'me-hidden' ), 3500 );
    }

    function clearErrors() {
        [ 'name', 'email' ].forEach( f => {
            const el = document.getElementById( `pcio-pf-err-${ f }` );
            if ( el ) el.textContent = '';
            const inp = document.getElementById( `pcio-pf-${ f }` );
            if ( inp ) inp.classList.remove( 'is-invalid' );
        } );
    }

    function setError( field, msg ) {
        const errEl = document.getElementById( `pcio-pf-err-${ field }` );
        const inp   = document.getElementById( `pcio-pf-${ field }` );
        if ( errEl ) errEl.textContent = msg;
        if ( inp )   { inp.classList.add( 'is-invalid' ); inp.focus(); }
    }

    function escHtml( str ) {
        return String( str ?? '' )
            .replace( /&/g,  '&amp;'  )
            .replace( /</g,  '&lt;'   )
            .replace( />/g,  '&gt;'   )
            .replace( /"/g,  '&quot;' )
            .replace( /'/g,  '&#039;' );
    }

    function escAttr( str ) {
        return escHtml( str );
    }

} )();
