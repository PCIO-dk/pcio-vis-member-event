/* ================================================================
   PCIO VIS Member Event — workgroups.js
   Workgroup CRUD: list + detail panel with volunteer membership.
   ================================================================ */

( function () {
    'use strict';

    const REST     = ( PCIO_ME.rest + '/workgroups' ).replace( /([^:]\/)\/+/g, '$1' );
    const REST_MBR = ( PCIO_ME.rest + '/members'   ).replace( /([^:]\/)\/+/g, '$1' );
    const NONCE    = PCIO_ME.nonce;
    const CAN_EDIT = PCIO_ME.canEdit;
    const T        = PCIO_ME.i18n;

    let workgroups   = [];
    let activeWg     = null; // currently selected workgroup (full object with .members)
    let volunteers   = [];   // cached volunteer list for the search picker
    let toastTimer   = null;

    // ── Bootstrap ─────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', () => {
        buildShell();
        loadWorkgroups();
    } );

    // ── Shell ─────────────────────────────────────────────────────
    function buildSubnav( items ) {
        if ( ! Array.isArray( items ) || items.length < 2 ) return '';
        return '<div class="me-fin-tabs">' +
            items.map( i =>
                '<a class="me-fin-tab' + ( i.active ? ' me-fin-tab-active' : '' ) +
                '" href="' + escAttr( i.url ) + '">' + escHtml( i.label ) + '</a>'
            ).join( '' ) + '</div>';
    }

    function buildShell() {
        const app = document.getElementById( 'pcio-me-app' );
        if ( ! app ) return;

        const newBtn = CAN_EDIT
            ? `<button class="me-btn me-btn-primary" id="wg-btn-new">${ escHtml( T.btnNew ) }</button>`
            : '';

        app.innerHTML = `
        <div class="me-page-header">
            <h1 class="me-page-title">${ escHtml( T.pageTitle ) }</h1>
            <div class="me-header-right">${ newBtn }</div>
        </div>
        ${ buildSubnav( PCIO_ME.subnav ) }
        <div class="wg-layout">
            <aside class="wg-list-panel" id="wg-list-panel">
                <div class="me-empty-state" id="wg-list-empty" style="display:none">
                    <p>${ escHtml( T.noWorkgroups ) }</p>
                </div>
                <ul class="wg-list" id="wg-list"></ul>
            </aside>
            <section class="wg-detail-panel me-hidden" id="wg-detail-panel">
                <div id="wg-detail-content"></div>
            </section>
        </div>

        <!-- Edit workgroup modal -->
        <div class="me-overlay me-hidden" id="wg-modal-overlay" role="dialog" aria-modal="true">
            <div class="me-modal">
                <div class="me-modal-header">
                    <h2 class="me-modal-title" id="wg-modal-title">${ escHtml( T.btnNew ) }</h2>
                    <button class="me-modal-close" id="wg-modal-close">&times;</button>
                </div>
                <div class="me-modal-body">
                    <div class="me-form-group">
                        <label class="me-form-label" for="wg-f-name">${ escHtml( T.fieldName ) } *</label>
                        <input class="me-form-input" type="text" id="wg-f-name" maxlength="120" required>
                        <span class="me-field-error" id="wg-err-name"></span>
                    </div>
                    <div class="me-form-group">
                        <label class="me-form-label" for="wg-f-desc">${ escHtml( T.fieldDesc ) }</label>
                        <textarea class="me-form-textarea" id="wg-f-desc" rows="3"></textarea>
                    </div>
                    <div class="me-form-group">
                        <label class="me-form-label" for="wg-f-color">${ escHtml( T.fieldColor ) }</label>
                        <input type="color" id="wg-f-color" value="#3b82f6">
                    </div>
                </div>
                <div class="me-modal-footer">
                    <button class="me-btn me-btn-secondary" id="wg-modal-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-primary"   id="wg-modal-save">${ escHtml( T.btnSave ) }</button>
                </div>
            </div>
        </div>

        <!-- Toast -->
        <div class="me-toast me-hidden" id="me-toast" role="status" aria-live="polite"></div>
        `;

        if ( CAN_EDIT ) {
            document.getElementById( 'wg-btn-new'      ).addEventListener( 'click', () => openModal( null ) );
            document.getElementById( 'wg-modal-close'  ).addEventListener( 'click', closeModal );
            document.getElementById( 'wg-modal-cancel' ).addEventListener( 'click', closeModal );
            document.getElementById( 'wg-modal-save'   ).addEventListener( 'click', saveWorkgroup );
        }
        document.getElementById( 'wg-modal-overlay' ).addEventListener( 'click', e => {
            if ( e.target === e.currentTarget ) closeModal();
        } );
    }

    // ── Load data ─────────────────────────────────────────────────
    async function loadWorkgroups() {
        try {
            const res = await fetch( REST, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            workgroups = await res.json();
            renderList();
        } catch ( err ) {
            showToast( T.errLoad + err.message, 'error' );
        }
    }

    async function loadWorkgroup( id ) {
        try {
            const res = await fetch( `${ REST }/${ id }`, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            activeWg = await res.json();
            renderDetail();
        } catch ( err ) {
            showToast( T.errLoad + err.message, 'error' );
        }
    }

    async function ensureVolunteers() {
        if ( volunteers.length ) return;
        try {
            const res = await fetch( REST_MBR + '?type=volunteers', { headers: { 'X-WP-Nonce': NONCE } } );
            if ( res.ok ) volunteers = await res.json();
        } catch ( _ ) { /* non-fatal */ }
    }

    // ── Render workgroup list ─────────────────────────────────────
    function renderList() {
        const listEl  = document.getElementById( 'wg-list' );
        const emptyEl = document.getElementById( 'wg-list-empty' );
        if ( ! listEl ) return;

        if ( workgroups.length === 0 ) {
            listEl.innerHTML = '';
            emptyEl.style.display = '';
            return;
        }
        emptyEl.style.display = 'none';
        listEl.innerHTML = workgroups.map( wg => `
            <li class="wg-list-item${ activeWg && activeWg.id === wg.id ? ' wg-list-item-active' : '' }"
                data-id="${ wg.id }">
                <span class="wg-color-dot" style="background:${ escAttr( wg.color || '#94a3b8' ) }"></span>
                <span class="wg-item-name">${ escHtml( wg.name ) }</span>
                <span class="wg-item-count">${ escHtml( String( wg.member_count || 0 ) ) } ${ escHtml( T.memberCount ) }</span>
            </li>` ).join( '' );

        listEl.querySelectorAll( '.wg-list-item' ).forEach( li => {
            li.addEventListener( 'click', () => {
                const id = parseInt( li.dataset.id, 10 );
                listEl.querySelectorAll( '.wg-list-item' ).forEach( x => x.classList.remove( 'wg-list-item-active' ) );
                li.classList.add( 'wg-list-item-active' );
                loadWorkgroup( id );
            } );
        } );
    }

    // ── Render workgroup detail ───────────────────────────────────
    function renderDetail() {
        if ( ! activeWg ) return;
        const panel = document.getElementById( 'wg-detail-panel' );
        const cont  = document.getElementById( 'wg-detail-content' );
        if ( ! panel || ! cont ) return;
        panel.classList.remove( 'me-hidden' );

        const memberRows = ( activeWg.members || [] ).map( m => `
            <tr>
                <td>${ escHtml( m.name ) }</td>
                <td>${ escHtml( m.email || '–' ) }</td>
                <td>
                    ${ m.is_chairman
                        ? `<span class="me-badge-system">${ escHtml( T.btnChairman ) }</span>`
                        : ( CAN_EDIT ? `<button class="me-btn me-btn-secondary me-btn-sm" data-action="chairman" data-id="${ m.id }">${ escHtml( T.btnChairman ) }</button>` : '' )
                    }
                </td>
                <td>
                    ${ CAN_EDIT ? `<button class="me-btn me-btn-danger me-btn-sm" data-action="remove" data-id="${ m.id }">${ escHtml( T.btnRemove ) }</button>` : '' }
                </td>
            </tr>` ).join( '' );

        cont.innerHTML = `
        <div class="wg-detail-header">
            <h2 style="display:flex;align-items:center;gap:10px">
                <span class="wg-color-dot wg-color-dot-lg" style="background:${ escAttr( activeWg.color || '#94a3b8' ) }"></span>
                ${ escHtml( activeWg.name ) }
            </h2>
            ${ CAN_EDIT ? `<div class="wg-detail-actions">
                <button class="me-btn me-btn-secondary" id="wg-btn-edit">${ escHtml( T.btnSave.replace( 'Save', 'Edit' ) ) || 'Edit' }</button>
                <button class="me-btn me-btn-danger"    id="wg-btn-del">${ escHtml( T.btnDelete ) }</button>
            </div>` : '' }
        </div>
        ${ activeWg.description ? `<p style="color:#475569;margin-bottom:16px">${ escHtml( activeWg.description ) }</p>` : '' }
        <h3 style="margin-bottom:8px;display:flex;align-items:center;gap:10px">
            Volunteers
            <button class="me-btn me-btn-secondary me-btn-sm" id="wg-btn-copy-emails"
                    title="${ escHtml( T.btnCopyEmails ) }">${ escHtml( T.btnCopyEmails ) }</button>
        </h3>
        ${ activeWg.members && activeWg.members.length ? `
        <table class="wp-list-table widefat striped" style="margin-bottom:16px">
            <tbody>${ memberRows }</tbody>
        </table>` : `<p style="color:#94a3b8">${ escHtml( T.noMembers ) }</p>` }
        ${ CAN_EDIT ? `
        <div class="wg-add-member">
            <input type="text" class="me-form-input" id="wg-volunteer-search"
                   placeholder="${ escAttr( T.searchPh ) }" style="max-width:280px">
            <div class="wg-volunteer-results me-hidden" id="wg-volunteer-results"></div>
        </div>` : '' }
        `;

        // Detail action bindings
        document.getElementById( 'wg-btn-copy-emails' )?.addEventListener( 'click', copyWorkgroupEmails );

        if ( CAN_EDIT ) {
            document.getElementById( 'wg-btn-edit' )?.addEventListener( 'click', () => openModal( activeWg ) );
            document.getElementById( 'wg-btn-del'  )?.addEventListener( 'click', deleteWorkgroup );

            cont.querySelectorAll( '[data-action="remove"]' ).forEach( btn => {
                btn.addEventListener( 'click', () => removeMember( parseInt( btn.dataset.id, 10 ) ) );
            } );
            cont.querySelectorAll( '[data-action="chairman"]' ).forEach( btn => {
                btn.addEventListener( 'click', () => setChairman( parseInt( btn.dataset.id, 10 ), true ) );
            } );

            const searchInput = document.getElementById( 'wg-volunteer-search' );
            if ( searchInput ) {
                searchInput.addEventListener( 'input', () => renderVolunteerResults( searchInput.value ) );
                searchInput.addEventListener( 'focus', () => {
                    ensureVolunteers().then( () => renderVolunteerResults( searchInput.value ) );
                } );
            }
        }
    }

    function copyWorkgroupEmails() {
        const emails = ( activeWg?.members || [] )
            .map( m => ( m.email || '' ).trim() )
            .filter( Boolean );

        if ( ! emails.length ) {
            showToast( T.toastCopyNone, 'info' );
            return;
        }

        navigator.clipboard.writeText( emails.join( ';' ) )
            .then( () => showToast( emails.length + ' ' + T.toastCopied, 'success' ) )
            .catch( () => showToast( T.toastCopyFail, 'error' ) );
    }

    function renderVolunteerResults( query ) {
        const res = document.getElementById( 'wg-volunteer-results' );
        if ( ! res ) return;
        const q         = query.toLowerCase().trim();
        const inGroup   = new Set( ( activeWg?.members || [] ).map( m => m.id ) );
        const filtered  = volunteers.filter( v =>
            ! inGroup.has( v.id ) &&
            ( ! q || v.name.toLowerCase().includes( q ) || ( v.email || '' ).toLowerCase().includes( q ) )
        ).slice( 0, 10 );

        if ( ! filtered.length ) { res.classList.add( 'me-hidden' ); return; }
        res.classList.remove( 'me-hidden' );
        res.innerHTML = filtered.map( v =>
            `<div class="wg-volunteer-opt" data-id="${ v.id }">${ escHtml( v.name ) } <small style="color:#94a3b8">${ escHtml( v.email || '' ) }</small></div>`
        ).join( '' );
        res.querySelectorAll( '.wg-volunteer-opt' ).forEach( opt => {
            opt.addEventListener( 'click', () => addMember( parseInt( opt.dataset.id, 10 ) ) );
        } );
    }

    // ── CRUD ──────────────────────────────────────────────────────
    let editingId = null;

    function openModal( wg ) {
        editingId = wg ? wg.id : null;
        document.getElementById( 'wg-modal-title' ).textContent = wg ? wg.name : T.btnNew;
        document.getElementById( 'wg-f-name'  ).value = wg?.name        || '';
        document.getElementById( 'wg-f-desc'  ).value = wg?.description || '';
        document.getElementById( 'wg-f-color' ).value = wg?.color       || '#3b82f6';
        document.getElementById( 'wg-err-name' ).textContent = '';
        document.getElementById( 'wg-modal-overlay' ).classList.remove( 'me-hidden' );
        document.getElementById( 'wg-f-name' ).focus();
    }

    function closeModal() {
        document.getElementById( 'wg-modal-overlay' ).classList.add( 'me-hidden' );
        editingId = null;
    }

    async function saveWorkgroup() {
        const name  = document.getElementById( 'wg-f-name' ).value.trim();
        const desc  = document.getElementById( 'wg-f-desc' ).value.trim();
        const color = document.getElementById( 'wg-f-color' ).value;
        if ( ! name ) {
            document.getElementById( 'wg-err-name' ).textContent = T.errNameReq;
            return;
        }
        try {
            const method = editingId ? 'PUT' : 'POST';
            const url    = editingId ? `${ REST }/${ editingId }` : REST;
            const res    = await fetch( url, {
                method,
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( { name, description: desc, color } ),
            } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            const saved = await res.json();
            closeModal();

            if ( editingId ) {
                workgroups = workgroups.map( w => w.id === editingId ? { ...w, name, color } : w );
                showToast( T.toastUpdated, 'success' );
                activeWg = saved;
            } else {
                workgroups.push( { id: saved.id, name, color, member_count: 0 } );
                showToast( T.toastCreated, 'success' );
                activeWg = saved;
            }
            renderList();
            renderDetail();
        } catch ( err ) {
            showToast( T.errSave + err.message, 'error' );
        }
    }

    async function deleteWorkgroup() {
        if ( ! activeWg || ! window.confirm( T.confirmDelete ) ) return;
        try {
            await fetch( `${ REST }/${ activeWg.id }`, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': NONCE },
            } );
            workgroups = workgroups.filter( w => w.id !== activeWg.id );
            activeWg   = null;
            renderList();
            document.getElementById( 'wg-detail-panel' )?.classList.add( 'me-hidden' );
            showToast( T.toastDeleted, 'success' );
        } catch ( err ) {
            showToast( T.errSave + err.message, 'error' );
        }
    }

    async function addMember( memberId ) {
        if ( ! activeWg ) return;
        try {
            const res = await fetch( `${ REST }/${ activeWg.id }/members`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( { member_id: memberId } ),
            } );
            if ( ! res.ok ) {
                const err = await res.json().catch( () => ( {} ) );
                throw new Error( err.message || `HTTP ${ res.status }` );
            }
            activeWg = await res.json();
            workgroups = workgroups.map( w => w.id === activeWg.id ? { ...w, member_count: activeWg.members.length } : w );
            renderList();
            renderDetail();
            showToast( T.toastMemberAdded, 'success' );
        } catch ( err ) {
            showToast( err.message, 'error' );
        }
    }

    async function removeMember( memberId ) {
        if ( ! activeWg ) return;
        try {
            const res = await fetch( `${ REST }/${ activeWg.id }/members/${ memberId }`, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            activeWg = await res.json();
            workgroups = workgroups.map( w => w.id === activeWg.id ? { ...w, member_count: activeWg.members.length } : w );
            renderList();
            renderDetail();
            showToast( T.toastMemberRemoved, 'success' );
        } catch ( err ) {
            showToast( err.message, 'error' );
        }
    }

    async function setChairman( memberId, isChairman ) {
        if ( ! activeWg ) return;
        try {
            const res = await fetch( `${ REST }/${ activeWg.id }/members/${ memberId }`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( { is_chairman: isChairman } ),
            } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            activeWg = await res.json();
            renderDetail();
        } catch ( err ) {
            showToast( err.message, 'error' );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────
    function showToast( msg, type ) {
        const toast = document.getElementById( 'me-toast' );
        if ( ! toast ) return;
        toast.textContent = msg;
        toast.className   = `me-toast ${ type }`;
        if ( toastTimer ) clearTimeout( toastTimer );
        toastTimer = setTimeout( () => toast.className = 'me-toast me-hidden', 4000 );
    }

    function escHtml( str ) {
        return String( str ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
    }

    function escAttr( str ) { return escHtml( str ); }

} )();
