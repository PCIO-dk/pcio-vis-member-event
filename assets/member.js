/* ================================================================
   PCIO VIS Member Event — member.js
   Member detail page (/vis/members/{id}/): Settings + History tabs.
   ================================================================ */

( function () {
    'use strict';

    const CFG         = window.PCIO_ME || {};
    const REST        = ( CFG.rest + '/members'   ).replace( /([^:]\/)\/+/g, '$1' );
    const REST_ROLES  = ( CFG.rest + '/vis-roles' ).replace( /([^:]\/)\/+/g, '$1' );
    const NONCE       = CFG.nonce;
    const MEMBER_ID   = CFG.memberId;
    const LIST_URL    = CFG.listUrl;
    const T           = CFG.i18n || {};
    const FIELDS      = ( CFG.memberFields || [] ).filter( f => f && f.key && f.key !== 'is_volunteer' );
    const EXTRA_TABS  = Array.isArray( CFG.extraTabs ) ? CFG.extraTabs : [];

    const TABS = [ { id: 'settings', label: T.tabSettings } ];
    if ( CFG.canHistory ) {
        TABS.push( { id: 'history', label: T.tabHistory } );
    }
    EXTRA_TABS.forEach( t => {
        if ( t && t.id ) {
            TABS.push( { id: t.id, label: t.label || t.id } );
        }
    } );

    let member     = null;
    let visRoles   = null;
    let activeTab  = 'settings';
    let snapshot   = null;
    let toastTimer = null;
    let shellBuilt = false;

    document.addEventListener( 'DOMContentLoaded', () => {
        if ( ! MEMBER_ID ) {
            renderError( T.errNoId );
            return;
        }
        window.addEventListener( 'beforeunload', ( e ) => {
            if ( isDirty() ) {
                e.preventDefault();
                e.returnValue = T.confirmDiscard;
            }
        } );
        loadMember();
    } );

    // ── Data ──────────────────────────────────────────────────────
    async function loadMember() {
        try {
            const [ res, roles ] = await Promise.all( [
                fetch( `${ REST }/${ MEMBER_ID }`, { headers: { 'X-WP-Nonce': NONCE } } ),
                visRoles ? Promise.resolve( visRoles ) : fetchRoles(),
            ] );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            member   = await res.json();
            visRoles = roles;
            buildShell();
            renderTab( activeTab );
        } catch ( err ) {
            renderError( escHtml( T.errLoad ) + escHtml( err.message ) );
        }
    }

    async function fetchRoles() {
        const res = await fetch( REST_ROLES, { headers: { 'X-WP-Nonce': NONCE } } );
        if ( ! res.ok ) return [];
        return res.json();
    }

    // ── Shell ─────────────────────────────────────────────────────
    function buildShell() {
        const app = document.getElementById( 'pcio-me-app' );
        if ( ! app ) return;

        if ( shellBuilt ) {
            document.getElementById( 'me-mbr-heading' ).textContent = member.name || '';
            return;
        }
        shellBuilt = true;

        const tabItems = TABS.map( t =>
            `<button class="me-tab-btn${ t.id === activeTab ? ' active' : '' }"
                     data-tab="${ escAttr( t.id ) }" type="button">${ escHtml( t.label ) }</button>`
        ).join( '' );

        app.innerHTML = `
        <div class="me-page-header">
            <div>
                <a class="me-back-link" href="${ escAttr( LIST_URL ) }">
                    <svg viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
                    ${ escHtml( T.backToList ) }
                </a>
                <h1 class="me-page-title" style="margin-top:6px">
                    <span id="me-mbr-heading">${ escHtml( member.name ) }</span>
                </h1>
            </div>
            <div class="me-header-right" style="align-items:flex-end;gap:10px">
                <button class="me-btn me-btn-danger" id="me-mbr-del-btn" type="button">${ escHtml( T.btnDelete ) }</button>
            </div>
        </div>

        <div class="me-tab-bar" role="tablist" aria-label="${ escAttr( T.tabsLabel ) }">${ tabItems }</div>
        <div class="me-tab-content" id="me-tab-content"></div>

        <div class="me-overlay me-hidden" id="me-del-overlay" role="alertdialog" aria-modal="true">
            <div class="me-modal me-modal-sm">
                <div class="me-modal-header">
                    <h2 class="me-modal-title">${ escHtml( T.titleDelete ) }</h2>
                    <button class="me-modal-close" id="me-del-close" type="button"
                            aria-label="${ escAttr( T.closeDialog ) }">&times;</button>
                </div>
                <div class="me-delete-body">
                    <p class="me-delete-title">${ escHtml( T.confirmDel ) }</p>
                    <p class="me-delete-name" id="me-del-name"></p>
                    <p class="me-delete-warning">${ escHtml( T.deleteHint ) }</p>
                </div>
                <div class="me-delete-footer">
                    <button class="me-btn me-btn-secondary" id="me-del-cancel" type="button">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-danger"    id="me-del-confirm" type="button">${ escHtml( T.btnDelete ) }</button>
                </div>
            </div>
        </div>

        <div class="me-toast me-hidden" id="me-toast" role="status" aria-live="polite"></div>
        `;

        app.querySelectorAll( '.me-tab-btn' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                if ( isDirty() && ! window.confirm( T.confirmDiscard ) ) return;
                snapshot = null;
                setActiveTab( btn.dataset.tab );
            } );
        } );

        document.getElementById( 'me-mbr-del-btn' ).addEventListener( 'click', openDeleteModal );
        document.getElementById( 'me-del-close'   ).addEventListener( 'click', closeDeleteModal );
        document.getElementById( 'me-del-cancel'  ).addEventListener( 'click', closeDeleteModal );
        document.getElementById( 'me-del-confirm' ).addEventListener( 'click', confirmDelete );
        document.getElementById( 'me-del-overlay' ).addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closeDeleteModal();
        } );
        document.addEventListener( 'keydown', ( e ) => {
            if ( e.key === 'Escape' ) closeDeleteModal();
        } );
    }

    function setActiveTab( tabId ) {
        activeTab = tabId;
        document.querySelectorAll( '.me-tab-btn' ).forEach( btn => {
            btn.classList.toggle( 'active', btn.dataset.tab === tabId );
        } );
        renderTab( tabId );
    }

    function renderTab( tabId ) {
        const container = document.getElementById( 'me-tab-content' );
        if ( ! container ) return;
        // Replace the node so listeners bound by a previous tab module are dropped.
        const fresh = container.cloneNode( false );
        container.replaceWith( fresh );

        if ( tabId === 'settings' ) {
            renderSettingsTab( fresh );
        } else if ( tabId === 'history' ) {
            renderHistoryTab( fresh );
        } else {
            const tab = EXTRA_TABS.find( t => t && t.id === tabId );
            fresh.innerHTML = tab ? ( tab.html || '' ) : '';
        }
    }

    // ── Settings tab ──────────────────────────────────────────────
    function fieldHtml( f ) {
        const id  = `f-${ f.key }`;
        const val = member[ f.key ] ?? '';
        const req = f.key === 'name' ? ' required' : '';
        let input;
        if ( f.type === 'textarea' ) {
            input = `<textarea class="me-form-textarea" id="${ escAttr( id ) }" name="${ escAttr( f.key ) }">${ escHtml( val ) }</textarea>`;
        } else {
            const type = [ 'text', 'email', 'tel', 'date', 'number' ].includes( f.type ) ? f.type : 'text';
            input = `<input class="me-form-input" type="${ type }" id="${ escAttr( id ) }"
                            name="${ escAttr( f.key ) }" value="${ escAttr( val ) }"${ req }>`;
        }
        return `
        <div class="me-form-group">
            <label class="me-form-label" for="${ escAttr( id ) }">${ escHtml( f.label ) }${ req ? ' <span class="req">*</span>' : '' }</label>
            ${ input }
            <span class="me-field-error" id="err-${ escAttr( f.key ) }"></span>
        </div>`;
    }

    function renderSettingsTab( container ) {
        const roleOptions = ( visRoles || [] ).map( r =>
            `<option value="${ escAttr( r.slug ) }"${ r.slug === member.vis_role ? ' selected' : '' }>${ escHtml( r.name ) }</option>`
        ).join( '' );

        const short = FIELDS.filter( f => f.type !== 'textarea' );
        const long  = FIELDS.filter( f => f.type === 'textarea' );

        container.innerHTML = `
        <div class="me-tab-card">
            <form id="me-mbr-form" novalidate>
                <div class="me-mbr-grid">${ short.map( fieldHtml ).join( '' ) }</div>
                ${ long.map( f => `<div class="me-form-row full">${ fieldHtml( f ) }</div>` ).join( '' ) }

                <div class="me-form-section-header"><span>${ escHtml( T.roleSectionHdr ) }</span></div>
                <div class="me-form-row">
                    <div class="me-form-group">
                        <label class="me-form-label" for="f-vis-role">${ escHtml( T.fieldVisRole ) }</label>
                        <select class="me-form-input" id="f-vis-role" name="vis_role">
                            <option value="">${ escHtml( T.noRole ) }</option>
                            ${ roleOptions }
                        </select>
                    </div>
                    <div class="me-form-group" style="align-self:flex-end;padding-bottom:8px">
                        <label class="me-check-row">
                            <input type="checkbox" id="f-is-volunteer" name="is_volunteer"${ Number( member.is_volunteer ) ? ' checked' : '' }>
                            <span>${ escHtml( T.fieldVolunteer ) }</span>
                        </label>
                    </div>
                </div>
                <p class="me-mbr-wp-user">${ escHtml( member.wp_user_id ? T.wpLinked.replace( '%d', member.wp_user_id ) : T.wpNotLinked ) }</p>

                <div class="me-mbr-actions">
                    <button type="submit" class="me-btn me-btn-primary" id="me-mbr-save">${ escHtml( T.btnSave ) }</button>
                </div>
            </form>
        </div>`;

        const form = container.querySelector( '#me-mbr-form' );
        form.addEventListener( 'submit', ( e ) => {
            e.preventDefault();
            saveSettings();
        } );
        snapshot = collectForm();
    }

    function collectForm() {
        const form = document.getElementById( 'me-mbr-form' );
        if ( ! form ) return null;
        const data = {};
        FIELDS.forEach( f => {
            const el = form.elements.namedItem( f.key );
            if ( el ) data[ f.key ] = el.value.trim();
        } );
        data.is_volunteer = form.elements.namedItem( 'is_volunteer' ).checked ? 1 : 0;
        data.vis_role     = form.elements.namedItem( 'vis_role' ).value;
        return data;
    }

    function isDirty() {
        if ( ! snapshot ) return false;
        return JSON.stringify( collectForm() ) !== JSON.stringify( snapshot );
    }

    async function saveSettings() {
        clearErrors();
        const data = collectForm();
        let valid  = true;
        if ( ! data.name ) {
            setError( 'name', T.errName );
            valid = false;
        }
        if ( data.email && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( data.email ) ) {
            setError( 'email', T.errEmail );
            valid = false;
        }
        if ( ! valid ) return;

        const btn = document.getElementById( 'me-mbr-save' );
        btn.disabled    = true;
        btn.textContent = T.stateSaving;

        try {
            const roleChanged = data.vis_role !== ( snapshot.vis_role || '' );
            const payload     = Object.assign( {}, data );
            delete payload.vis_role;

            const res = await fetch( `${ REST }/${ MEMBER_ID }`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( payload ),
            } );
            if ( ! res.ok ) {
                const err = await res.json().catch( () => ( {} ) );
                throw new Error( err.message || `HTTP ${ res.status }` );
            }
            member = await res.json();

            // Separate request only when the role actually changed, so an
            // unchanged role does not add a history entry.
            if ( roleChanged ) {
                const roleRes = await fetch( `${ REST }/${ MEMBER_ID }/role`, {
                    method:  'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body:    JSON.stringify( { vis_role: data.vis_role } ),
                } );
                if ( ! roleRes.ok ) {
                    const err = await roleRes.json().catch( () => ( {} ) );
                    throw new Error( T.errRoleSave + ( err.message || `HTTP ${ roleRes.status }` ) );
                }
                member = await roleRes.json();
            }

            buildShell();
            renderTab( 'settings' );
            showToast( T.toastUpdated, 'success' );
        } catch ( err ) {
            showToast( T.errSave + err.message, 'error' );
            btn.disabled    = false;
            btn.textContent = T.btnSave;
        }
    }

    // ── History tab ───────────────────────────────────────────────
    function renderHistoryTab( container ) {
        if ( ! window.PCIO_ME_History ) return;
        window.PCIO_ME_History.render( container, {
            rest:  CFG.rest,
            nonce: NONCE,
            query: { member_id: MEMBER_ID },
            onUndone: async ( data ) => {
                await loadMember();
                showToast( data.message || '', 'success' );
            },
        } );
    }

    // ── Delete ────────────────────────────────────────────────────
    function openDeleteModal() {
        document.getElementById( 'me-del-name' ).textContent = member.name || '';
        document.getElementById( 'me-del-overlay' ).classList.remove( 'me-hidden' );
    }

    function closeDeleteModal() {
        document.getElementById( 'me-del-overlay' )?.classList.add( 'me-hidden' );
    }

    async function confirmDelete() {
        const btn = document.getElementById( 'me-del-confirm' );
        btn.disabled    = true;
        btn.textContent = T.stateDeleting;
        try {
            const res = await fetch( `${ REST }/${ MEMBER_ID }`, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok ) {
                const err = await res.json().catch( () => ( {} ) );
                throw new Error( err.message || `HTTP ${ res.status }` );
            }
            snapshot = null;
            window.location.href = LIST_URL;
        } catch ( err ) {
            showToast( T.errDelete + err.message, 'error' );
            btn.disabled    = false;
            btn.textContent = T.btnDelete;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────
    function clearErrors() {
        document.querySelectorAll( '.me-field-error' ).forEach( el => { el.textContent = ''; } );
        document.querySelectorAll( '.is-invalid' ).forEach( el => el.classList.remove( 'is-invalid' ) );
    }

    function setError( key, msg ) {
        const err = document.getElementById( `err-${ key }` );
        const inp = document.getElementById( `f-${ key }` );
        if ( err ) err.textContent = msg;
        if ( inp ) { inp.classList.add( 'is-invalid' ); inp.focus(); }
    }

    function showToast( msg, type ) {
        const toast = document.getElementById( 'me-toast' );
        if ( ! toast || ! msg ) return;
        toast.textContent = msg;
        toast.className   = `me-toast ${ type }`;
        if ( toastTimer ) clearTimeout( toastTimer );
        toastTimer = setTimeout( () => toast.classList.add( 'me-hidden' ), 3000 );
    }

    function renderError( msg ) {
        const app = document.getElementById( 'pcio-me-app' );
        if ( app ) app.innerHTML = `<div class="me-empty-state" style="padding:80px 20px"><p>${ msg }</p></div>`;
    }

    function escHtml( str ) {
        return String( str ?? '' )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
    }
    function escAttr( str ) { return escHtml( str ); }
} )();
