/* ================================================================
   PCIO VIS Member Event — documents.js
   Document list: REST-backed table with CRUD dialogs and
   WordPress Media Library integration for file picking/uploading.
   ================================================================ */

( function () {
    'use strict';

    // ── State ─────────────────────────────────────────────────────
    let docs       = [];
    let sortCol    = 'document_date';
    let sortDir    = 'desc';
    let editId     = null;
    let deleteId   = null;
    let toastTimer = null;

    const REST      = ( PCIO_ME.rest + '/documents'      ).replace( /([^:]\/)\/+/g, '$1' );
    const REST_TYPES = ( PCIO_ME.rest + '/document-types' ).replace( /([^:]\/)\/+/g, '$1' );
    const NONCE     = PCIO_ME.nonce;
    const CAN_EDIT  = PCIO_ME.canEdit;
    const T         = PCIO_ME.i18n;

    // Column definitions
    const COLUMNS = [
        { key: 'type_title',    label: T.colType,    sortable: true  },
        { key: 'display_name',  label: T.colName,    sortable: true  },
        { key: 'document_date', label: T.colDate,    sortable: true  },
        { key: 'attachment',    label: T.colFile,    sortable: false },
    ];

    const ICON_EDIT  = `<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
    const ICON_DEL   = `<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>`;
    const ICON_EMPTY = `<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>`;
    const ICON_DL    = `<svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>`;

    // ── Bootstrap ─────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', () => {
        buildShell();
        loadDocs();
    } );

    // ── Page shell ────────────────────────────────────────────────
    function buildShell() {
        const app = document.getElementById( 'pcio-me-app' );
        if ( ! app ) return;

        const newBtn = CAN_EDIT
            ? `<button class="me-btn me-btn-primary" id="me-btn-new">${ escHtml( T.btnNew ) }</button>`
            : '';

        app.innerHTML = `
        <div class="me-page-header">
            <h1 class="me-page-title">${ escHtml( T.pageTitle ) }</h1>
            <div class="me-header-right">
                <span class="me-member-count" id="me-count"></span>
                ${ newBtn }
            </div>
        </div>

        <div class="me-table-card">
            <div class="me-toolbar">
                <span class="me-toolbar-info" id="me-visible-count"></span>
                <div class="me-search-box">
                    <input type="search" id="me-global-search"
                           placeholder="${ escAttr( T.searchPh ) }"
                           autocomplete="off">
                </div>
            </div>
            <div class="me-table-wrap">
                <table id="me-table">
                    <thead>
                        <tr class="me-head-row" id="me-head-row"></tr>
                        <tr class="me-filter-row" id="me-filter-row"></tr>
                    </thead>
                    <tbody id="me-tbody"></tbody>
                </table>
            </div>
            <div class="me-empty-state me-hidden" id="me-empty">
                ${ ICON_EMPTY }
                <p id="me-empty-msg">${ escHtml( T.emptyNone ) }</p>
            </div>
        </div>

        <!-- Create / Edit modal -->
        <div class="me-overlay me-hidden" id="me-overlay"
             role="dialog" aria-modal="true" aria-labelledby="me-modal-title">
            <div class="me-modal">
                <div class="me-modal-header">
                    <h2 class="me-modal-title" id="me-modal-title">${ escHtml( T.titleCreate ) }</h2>
                    <button class="me-modal-close" id="me-modal-close"
                            aria-label="${ escAttr( T.closeDialog ) }">&times;</button>
                </div>
                <div class="me-modal-body">
                    <form id="me-form" novalidate>

                        <div class="me-form-row">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-type">
                                    ${ escHtml( T.fieldType ) } <span class="req">*</span>
                                </label>
                                <select class="me-form-input" id="f-type" name="type_id">
                                    <option value="">${ escHtml( T.phType ) }</option>
                                </select>
                                <span class="me-field-error" id="err-type"></span>
                            </div>
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-date">
                                    ${ escHtml( T.fieldDate ) }
                                </label>
                                <input class="me-form-input" type="date"
                                       id="f-date" name="document_date">
                            </div>
                        </div>

                        <div class="me-form-row full">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-name">
                                    ${ escHtml( T.fieldName ) } <span class="req">*</span>
                                </label>
                                <input class="me-form-input" type="text"
                                       id="f-name" name="display_name"
                                       placeholder="${ escAttr( T.phName ) }" maxlength="200">
                                <span class="me-field-error" id="err-name"></span>
                            </div>
                        </div>

                        <div class="me-form-row full">
                            <div class="me-form-group">
                                <label class="me-form-label">
                                    ${ escHtml( T.fieldFile ) } <span class="req">*</span>
                                </label>
                                <div class="me-media-picker">
                                    <input type="hidden" id="f-attachment-id" name="wp_attachment_id" value="0">
                                    <span class="me-media-filename" id="me-media-filename">
                                        ${ escHtml( T.noFileChosen ) }
                                    </span>
                                    <button type="button" class="me-btn me-btn-secondary"
                                            id="me-media-pick-btn">
                                        ${ escHtml( T.btnChooseFile ) }
                                    </button>
                                </div>
                                <span class="me-field-error" id="err-file"></span>
                            </div>
                        </div>

                    </form>
                </div>
                <div class="me-modal-footer">
                    <button class="me-btn me-btn-secondary" id="me-modal-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-primary"   id="me-modal-save">${ escHtml( T.btnSave ) }</button>
                </div>
            </div>
        </div>

        <!-- Delete confirm modal -->
        <div class="me-overlay me-hidden" id="me-del-overlay"
             role="alertdialog" aria-modal="true">
            <div class="me-modal me-modal-sm">
                <div class="me-modal-header">
                    <h2 class="me-modal-title">${ escHtml( T.titleDelete ) }</h2>
                    <button class="me-modal-close" id="me-del-close"
                            aria-label="${ escAttr( T.closeDialog ) }">&times;</button>
                </div>
                <div class="me-delete-body">
                    <div class="me-delete-icon">${ ICON_DEL }</div>
                    <p class="me-delete-title">${ escHtml( T.confirmDel ) }</p>
                    <p class="me-delete-name" id="me-del-name"></p>
                    <p class="me-delete-warning">${ escHtml( T.cannotUndo ) }</p>
                </div>
                <div class="me-delete-footer">
                    <button class="me-btn me-btn-secondary" id="me-del-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-danger"    id="me-del-confirm">${ escHtml( T.btnDelete ) }</button>
                </div>
            </div>
        </div>

        <div class="me-toast me-hidden" id="me-toast" role="status" aria-live="polite"></div>
        `;

        // Wire events
        document.getElementById( 'me-global-search' ).addEventListener( 'input', renderTable );
        if ( CAN_EDIT ) {
            document.getElementById( 'me-btn-new'       ).addEventListener( 'click', () => openModal( null ) );
            document.getElementById( 'me-media-pick-btn').addEventListener( 'click', openMediaPicker );
            document.getElementById( 'me-modal-close'   ).addEventListener( 'click', closeModal );
            document.getElementById( 'me-modal-cancel'  ).addEventListener( 'click', closeModal );
            document.getElementById( 'me-modal-save'    ).addEventListener( 'click', saveDoc );
            document.getElementById( 'me-del-close'     ).addEventListener( 'click', closeDeleteModal );
            document.getElementById( 'me-del-cancel'    ).addEventListener( 'click', closeDeleteModal );
            document.getElementById( 'me-del-confirm'   ).addEventListener( 'click', confirmDelete );
        }
        document.getElementById( 'me-overlay'     ).addEventListener( 'click', e => { if ( e.target === e.currentTarget ) closeModal(); } );
        document.getElementById( 'me-del-overlay' ).addEventListener( 'click', e => { if ( e.target === e.currentTarget ) closeDeleteModal(); } );
        document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' ) { closeModal(); closeDeleteModal(); } } );

        buildHeadRow();
    }

    // ── Table head ─────────────────────────────────────────────────
    function buildHeadRow() {
        const headRow   = document.getElementById( 'me-head-row' );
        const filterRow = document.getElementById( 'me-filter-row' );

        COLUMNS.forEach( col => {
            const th = document.createElement( 'th' );
            th.dataset.col = col.key;
            if ( col.sortable ) {
                th.classList.add( 'sortable' );
                th.innerHTML = `${ escHtml( col.label ) }<span class="sort-icon" aria-hidden="true"></span>`;
                th.addEventListener( 'click', () => toggleSort( col.key ) );
            } else {
                th.textContent = col.label;
            }
            headRow.appendChild( th );

            const td    = document.createElement( 'td' );
            if ( col.key !== 'attachment' ) {
                const input = document.createElement( 'input' );
                input.type         = 'text';
                input.classList.add( 'me-filter-input' );
                input.dataset.col  = col.key;
                input.placeholder  = `${ T.filterWord } ${ col.label.toLowerCase() }\u2026`;
                input.autocomplete = 'off';
                input.addEventListener( 'input', renderTable );
                td.appendChild( input );
            }
            filterRow.appendChild( td );
        } );

        if ( CAN_EDIT ) {
            headRow.appendChild( document.createElement( 'th' ) );
            filterRow.appendChild( document.createElement( 'td' ) );
        }

        updateSortClasses();
    }

    function updateSortClasses() {
        document.querySelectorAll( '#me-head-row th[data-col]' ).forEach( th => {
            th.classList.remove( 'sort-asc', 'sort-desc' );
            if ( th.dataset.col === sortCol ) {
                th.classList.add( sortDir === 'asc' ? 'sort-asc' : 'sort-desc' );
            }
        } );
    }

    function toggleSort( col ) {
        sortDir = sortCol === col ? ( sortDir === 'asc' ? 'desc' : 'asc' ) : 'desc';
        sortCol = col;
        updateSortClasses();
        renderTable();
    }

    // ── Load ──────────────────────────────────────────────────────
    async function loadDocs() {
        try {
            const res = await fetch( REST, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            docs = await res.json();
            renderTable();
        } catch ( err ) {
            showToast( T.errorLoad + err.message, 'error' );
        }
    }

    // ── Render table ──────────────────────────────────────────────
    function renderTable() {
        const tbody  = document.getElementById( 'me-tbody' );
        const empty  = document.getElementById( 'me-empty' );
        const emptyMsg = document.getElementById( 'me-empty-msg' );
        const count  = document.getElementById( 'me-count' );
        const vis    = document.getElementById( 'me-visible-count' );
        if ( ! tbody ) return;

        const q = ( document.getElementById( 'me-global-search' )?.value || '' ).toLowerCase().trim();
        const colFilters = {};
        document.querySelectorAll( '.me-filter-input' ).forEach( input => {
            const v = input.value.trim().toLowerCase();
            if ( v ) colFilters[ input.dataset.col ] = v;
        } );

        let filtered = docs.filter( d => {
            if ( q ) {
                const haystack = [
                    d.type_title, d.display_name, d.document_date || '', d.attachment_name || ''
                ].join( ' ' ).toLowerCase();
                if ( ! haystack.includes( q ) ) return false;
            }
            for ( const [ col, val ] of Object.entries( colFilters ) ) {
                const cell = String( d[ col ] ?? '' ).toLowerCase();
                if ( ! cell.includes( val ) ) return false;
            }
            return true;
        } );

        filtered = filtered.slice().sort( ( a, b ) => {
            const av = String( a[ sortCol ] ?? '' );
            const bv = String( b[ sortCol ] ?? '' );
            const cmp = av.localeCompare( bv, undefined, { numeric: true, sensitivity: 'base' } );
            return sortDir === 'asc' ? cmp : -cmp;
        } );

        if ( count ) {
            const word = docs.length !== 1 ? T.docPlural : T.docSingular;
            count.textContent = `${ docs.length } ${ word }`;
        }
        if ( vis ) {
            vis.textContent = filtered.length < docs.length
                ? `${ T.showing } ${ filtered.length } ${ T.of } ${ docs.length } ${ T.docPlural }`
                : `${ docs.length } ${ docs.length !== 1 ? T.docPlural : T.docSingular } ${ T.totalSuffix }`;
        }

        if ( filtered.length === 0 ) {
            tbody.innerHTML = '';
            if ( emptyMsg ) emptyMsg.textContent = docs.length === 0 ? T.emptyNone : T.emptyFiltered;
            empty.classList.remove( 'me-hidden' );
            return;
        }
        empty.classList.add( 'me-hidden' );

        tbody.innerHTML = filtered.map( d => {
            const fileCell = d.attachment_url
                ? `<a href="${ escAttr( d.attachment_url ) }" target="_blank" rel="noopener"
                       class="me-doc-dl-link" title="${ escAttr( T.download ) }">
                       ${ ICON_DL }
                       ${ escHtml( d.attachment_name || d.display_name ) }
                   </a>`
                : '<span style="color:#94a3b8">\u2014</span>';

            const actions = CAN_EDIT ? `
                <td class="me-col-actions">
                    <button class="me-icon-btn" data-action="edit"   data-id="${ d.id }"
                            title="${ escAttr( T.btnEdit ) }">${ ICON_EDIT }</button>
                    <button class="me-icon-btn me-icon-btn-danger" data-action="delete" data-id="${ d.id }"
                            title="${ escAttr( T.btnDelete ) }">${ ICON_DEL }</button>
                </td>` : '';

            return `<tr>
                <td>${ escHtml( d.type_title ) }</td>
                <td>${ escHtml( d.display_name ) }</td>
                <td class="me-col-date">${ d.document_date ? fmtDate( d.document_date ) : '\u2014' }</td>
                <td>${ fileCell }</td>
                ${ actions }
            </tr>`;
        } ).join( '' );

        if ( CAN_EDIT ) {
            tbody.querySelectorAll( '[data-action]' ).forEach( btn => {
                btn.addEventListener( 'click', () => {
                    const id = parseInt( btn.dataset.id, 10 );
                    if ( btn.dataset.action === 'edit'   ) openModal( id );
                    if ( btn.dataset.action === 'delete' ) openDeleteModal( id );
                } );
            } );
        }
    }

    // ── WordPress Media Library picker ────────────────────────────
    let mediaFrame = null;

    function openMediaPicker() {
        if ( typeof wp === 'undefined' || ! wp.media ) {
            showToast( T.noMediaLib, 'error' );
            return;
        }
        if ( ! mediaFrame ) {
            mediaFrame = wp.media( {
                title:    T.mediaTitle,
                button:   { text: T.mediaBtn },
                multiple: false,
            } );
            mediaFrame.on( 'select', () => {
                const att = mediaFrame.state().get( 'selection' ).first().toJSON();
                document.getElementById( 'f-attachment-id' ).value = att.id;
                document.getElementById( 'me-media-filename' ).textContent =
                    att.filename || att.title || String( att.id );
                document.getElementById( 'err-file' ).textContent = '';
            } );
        }
        mediaFrame.open();
    }

    // ── Modal ─────────────────────────────────────────────────────
    async function openModal( id ) {
        editId = id;
        mediaFrame = null; // reset picker each time

        // Populate type <select>
        const sel = document.getElementById( 'f-type' );
        if ( sel.options.length <= 1 ) {
            try {
                const res = await fetch( REST_TYPES, { headers: { 'X-WP-Nonce': NONCE } } );
                if ( res.ok ) {
                    const types = await res.json();
                    types.forEach( t => {
                        const opt = document.createElement( 'option' );
                        opt.value       = t.id;
                        opt.textContent = t.title;
                        sel.appendChild( opt );
                    } );
                }
            } catch ( _ ) { /* non-fatal */ }
        }

        document.getElementById( 'me-modal-title' ).textContent =
            id ? T.titleEdit : T.titleCreate;
        document.getElementById( 'err-type'   ).textContent = '';
        document.getElementById( 'err-name'   ).textContent = '';
        document.getElementById( 'err-file'   ).textContent = '';

        if ( id ) {
            const d = docs.find( x => x.id === id );
            sel.value = d?.type_id ?? '';
            document.getElementById( 'f-name'          ).value       = d?.display_name    || '';
            document.getElementById( 'f-date'          ).value       = d?.document_date   || '';
            document.getElementById( 'f-attachment-id' ).value       = d?.wp_attachment_id || 0;
            document.getElementById( 'me-media-filename' ).textContent =
                d?.attachment_name || ( d?.wp_attachment_id ? `#${ d.wp_attachment_id }` : T.noFileChosen );
        } else {
            sel.value = '';
            document.getElementById( 'f-name'          ).value = '';
            document.getElementById( 'f-date'          ).value = '';
            document.getElementById( 'f-attachment-id' ).value = '0';
            document.getElementById( 'me-media-filename' ).textContent = T.noFileChosen;
        }

        document.getElementById( 'me-overlay' ).classList.remove( 'me-hidden' );
        document.getElementById( 'f-name' ).focus();
    }

    function closeModal() {
        document.getElementById( 'me-overlay' ).classList.add( 'me-hidden' );
        editId = null;
    }

    async function saveDoc() {
        const typeId = document.getElementById( 'f-type'          ).value;
        const name   = document.getElementById( 'f-name'          ).value.trim();
        const date   = document.getElementById( 'f-date'          ).value;
        const attId  = parseInt( document.getElementById( 'f-attachment-id' ).value, 10 );

        let valid = true;
        document.getElementById( 'err-type' ).textContent = '';
        document.getElementById( 'err-name' ).textContent = '';
        document.getElementById( 'err-file' ).textContent = '';

        if ( ! typeId ) {
            document.getElementById( 'err-type' ).textContent = T.errTypeReq;
            valid = false;
        }
        if ( ! name ) {
            document.getElementById( 'err-name' ).textContent = T.errNameReq;
            valid = false;
        }
        if ( ! attId ) {
            document.getElementById( 'err-file' ).textContent = T.errFileReq;
            valid = false;
        }
        if ( ! valid ) return;

        const btn = document.getElementById( 'me-modal-save' );
        btn.disabled    = true;
        btn.textContent = T.stateSaving;

        try {
            const method = editId ? 'PUT' : 'POST';
            const url    = editId ? `${ REST }/${ editId }` : REST;
            const res = await fetch( url, {
                method,
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body: JSON.stringify( {
                    type_id:          parseInt( typeId, 10 ),
                    display_name:     name,
                    document_date:    date || null,
                    wp_attachment_id: attId,
                } ),
            } );
            if ( ! res.ok ) {
                const err = await res.json().catch( () => ( {} ) );
                throw new Error( err.message || `HTTP ${ res.status }` );
            }
            const saved = await res.json();

            if ( editId ) {
                const idx = docs.findIndex( d => d.id === editId );
                if ( idx !== -1 ) docs[ idx ] = saved;
                showToast( T.toastUpdated, 'success' );
            } else {
                docs.unshift( saved );
                showToast( T.toastCreated, 'success' );
            }
            renderTable();
            closeModal();
        } catch ( err ) {
            showToast( T.errSave + err.message, 'error' );
        } finally {
            btn.disabled    = false;
            btn.textContent = T.btnSave;
        }
    }

    // ── Delete modal ──────────────────────────────────────────────
    function openDeleteModal( id ) {
        deleteId = id;
        const d = docs.find( x => x.id === id );
        document.getElementById( 'me-del-name' ).innerHTML =
            `<strong>${ escHtml( d?.display_name || '' ) }</strong>`;
        document.getElementById( 'me-del-overlay' ).classList.remove( 'me-hidden' );
    }

    function closeDeleteModal() {
        document.getElementById( 'me-del-overlay' ).classList.add( 'me-hidden' );
        deleteId = null;
    }

    async function confirmDelete() {
        if ( ! deleteId ) return;
        const btn = document.getElementById( 'me-del-confirm' );
        btn.disabled    = true;
        btn.textContent = T.stateDeleting;
        try {
            const res = await fetch( `${ REST }/${ deleteId }`, {
                method: 'DELETE', headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok && res.status !== 204 ) throw new Error( `HTTP ${ res.status }` );
            docs = docs.filter( d => d.id !== deleteId );
            renderTable();
            closeDeleteModal();
            showToast( T.toastDeleted, 'success' );
        } catch ( err ) {
            showToast( T.errDelete + err.message, 'error' );
        } finally {
            btn.disabled    = false;
            btn.textContent = T.btnDelete;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────
    function fmtDate( iso ) {
        if ( ! iso ) return '\u2014';
        const d = new Date( iso.replace( ' ', 'T' ) + ( iso.length === 10 ? 'T00:00:00' : '' ) );
        return d.toLocaleDateString( undefined, { day: '2-digit', month: 'short', year: 'numeric' } );
    }

    function showToast( msg, type ) {
        const toast = document.getElementById( 'me-toast' );
        if ( ! toast ) return;
        toast.textContent = msg;
        toast.className   = `me-toast ${ type }`;
        if ( toastTimer ) clearTimeout( toastTimer );
        toastTimer = setTimeout( () => toast.classList.add( 'me-hidden' ), 3500 );
    }

    function escHtml( str ) {
        return String( str ?? '' )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }
    function escAttr( str ) { return escHtml( str ); }

} )();
