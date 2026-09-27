/* ================================================================
   PCIO VIS Member Event — finance.js
   Finance section: a collection of REST-backed lists (data tables).
   Config-driven so extensions can register additional lists via the
   `pcio_me_finance_lists` PHP filter. The core list is the transaction
   journal (Kasseklade); the Tickets plugin adds a read-only Orders list.
   ================================================================ */

( function () {
    'use strict';

    const CFG = window.PCIO_VIS_FINANCE;
    if ( ! CFG ) return;

    const NONCE = CFG.nonce;
    const T     = CFG.i18n || {};
    const LISTS = Array.isArray( CFG.lists ) ? CFG.lists : [];

    // Per-list runtime state, keyed by list id.
    const state = {};   // { [id]: { rows, sortCol, sortDir, list } }
    let activeId   = LISTS.length ? LISTS[ 0 ].id : null;
    let editId     = null;   // null = create, number = update (active list)
    let deleteId   = null;
    let toastTimer = null;
    let dirtySnapshot = null;
    let mediaFrame = null;
    let actionCtx  = null;   // { action, row } for the generic action modal

    const money = new Intl.NumberFormat( CFG.locale || 'da-DK', {
        style: 'currency', currency: CFG.currency || 'DKK',
    } );

    // SVG icons (stroke-based, currentColor).
    const ICON_EDIT  = `<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
    const ICON_DEL   = `<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>`;
    const ICON_EMPTY = `<svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>`;
    const ICON_REFUND = `<svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>`;
    const ICON_MAIL  = `<svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>`;
    const ACTION_ICONS = { refund: ICON_REFUND, mail: ICON_MAIL };


    // ── Bootstrap ─────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', () => {
        buildShell();
        LISTS.forEach( ( list ) => {
            state[ list.id ] = { rows: [], sortCol: defaultSortCol( list ), sortDir: defaultSortDir( list ), list };
            buildListSection( list );
            loadList( list.id );
        } );
        if ( activeId ) activateTab( activeId );
    } );

    function defaultSortCol( list ) {
        const dt = ( list.columns || [] ).find( ( c ) => c.type === 'datetime' && c.sortable );
        if ( dt ) return dt.key;
        const first = ( list.columns || [] ).find( ( c ) => c.sortable );
        return first ? first.key : '';
    }
    function defaultSortDir( list ) {
        return ( list.columns || [] ).some( ( c ) => c.type === 'datetime' && c.sortable ) ? 'desc' : 'asc';
    }

    // ── Page shell (runs once) ────────────────────────────────────
    function buildShell() {
        const app = document.getElementById( 'pcio-me-app' );
        if ( ! app ) return;

        const tabs = LISTS.map( ( l ) =>
            `<button class="me-fin-tab" data-list="${ escAttr( l.id ) }" type="button">${ escHtml( l.label ) }</button>`
        ).join( '' );

        const sections = LISTS.map( ( l ) =>
            `<div class="me-fin-section me-hidden" id="me-fin-section-${ escAttr( l.id ) }"></div>`
        ).join( '' );

        app.innerHTML = `
        <div class="me-page-header">
            <h1 class="me-page-title">${ escHtml( T.pageTitle || 'Finance' ) }</h1>
            <div class="me-header-right">
                <span class="me-member-count" id="me-fin-count"></span>
                <button class="me-btn me-btn-primary me-hidden" id="me-fin-new"></button>
            </div>
        </div>

        <div class="me-fin-tabs" role="tablist">${ tabs }</div>
        <div class="me-fin-sections">${ sections }</div>

        <!-- Create / Edit modal -->
        <div class="me-overlay me-hidden" id="me-modal-overlay"
             role="dialog" aria-modal="true" aria-labelledby="me-modal-title-text">
            <div class="me-modal">
                <div class="me-modal-header">
                    <h2 class="me-modal-title" id="me-modal-title-text"></h2>
                    <button class="me-modal-close" id="me-modal-close"
                            aria-label="${ escAttr( T.closeDialog || 'Close dialog' ) }">&times;</button>
                </div>
                <div class="me-modal-body">
                    <form id="me-form" novalidate></form>
                </div>
                <div class="me-modal-footer">
                    <button class="me-btn me-btn-secondary" id="me-btn-cancel" type="button">${ escHtml( T.btnCancel || 'Cancel' ) }</button>
                    <button class="me-btn me-btn-primary" id="me-btn-save" type="button">${ escHtml( T.btnSave || 'Save' ) }</button>
                </div>
            </div>
        </div>

        <!-- Delete confirm modal -->
        <div class="me-overlay me-hidden" id="me-delete-overlay"
             role="dialog" aria-modal="true" aria-labelledby="me-delete-title">
            <div class="me-modal me-modal-sm">
                <div class="me-modal-header">
                    <h2 class="me-modal-title" id="me-delete-title">${ escHtml( T.titleDelete || 'Delete entry' ) }</h2>
                    <button class="me-modal-close" id="me-delete-close" aria-label="${ escAttr( T.closeDialog || 'Close dialog' ) }">&times;</button>
                </div>
                <div class="me-modal-body">
                    <p id="me-delete-text"></p>
                    <p class="me-muted">${ escHtml( T.cannotUndo || 'This action cannot be undone.' ) }</p>
                </div>
                <div class="me-modal-footer">
                    <button class="me-btn me-btn-secondary" id="me-delete-cancel" type="button">${ escHtml( T.btnCancel || 'Cancel' ) }</button>
                    <button class="me-btn me-btn-danger" id="me-delete-confirm" type="button">${ escHtml( T.btnDelete || 'Delete' ) }</button>
                </div>
            </div>
        </div>

        <!-- Generic row-action modal (e.g. Orders refund / resend) -->
        <div class="me-overlay me-hidden" id="me-action-overlay"
             role="dialog" aria-modal="true" aria-labelledby="me-action-title">
            <div class="me-modal me-modal-sm">
                <div class="me-modal-header">
                    <h2 class="me-modal-title" id="me-action-title"></h2>
                    <button class="me-modal-close" id="me-action-close" aria-label="${ escAttr( T.closeDialog || 'Close dialog' ) }">&times;</button>
                </div>
                <div class="me-modal-body">
                    <p id="me-action-text" class="me-muted"></p>
                    <div class="me-form-row me-hidden" id="me-action-amount-row">
                        <div class="me-form-group">
                            <label class="me-form-label" id="me-action-amount-label" for="me-action-amount"></label>
                            <input class="me-form-input" type="number" step="0.01" min="0" id="me-action-amount" inputmode="decimal">
                            <span class="me-field-error" id="me-action-amount-err"></span>
                            <span class="me-form-hint" id="me-action-amount-hint"></span>
                        </div>
                    </div>
                </div>
                <div class="me-modal-footer">
                    <button class="me-btn me-btn-secondary" id="me-action-cancel" type="button">${ escHtml( T.btnCancel || 'Cancel' ) }</button>
                    <button class="me-btn me-btn-primary" id="me-action-confirm" type="button"></button>
                </div>
            </div>
        </div>

        <div class="me-toast me-hidden" id="me-toast" role="status" aria-live="polite"></div>
        `;

        // Tab switching.
        app.querySelectorAll( '.me-fin-tab' ).forEach( ( btn ) => {
            btn.addEventListener( 'click', () => activateTab( btn.dataset.list ) );
        } );

        // Modal wiring.
        document.getElementById( 'me-fin-new' ).addEventListener( 'click', () => openModal( null ) );
        document.getElementById( 'me-modal-close' ).addEventListener( 'click', maybeCloseModal );
        document.getElementById( 'me-btn-cancel' ).addEventListener( 'click', maybeCloseModal );
        document.getElementById( 'me-btn-save' ).addEventListener( 'click', submitForm );
        document.getElementById( 'me-delete-close' ).addEventListener( 'click', closeDeleteModal );
        document.getElementById( 'me-delete-cancel' ).addEventListener( 'click', closeDeleteModal );
        document.getElementById( 'me-delete-confirm' ).addEventListener( 'click', confirmDelete );
        document.getElementById( 'me-action-close' ).addEventListener( 'click', closeActionModal );
        document.getElementById( 'me-action-cancel' ).addEventListener( 'click', closeActionModal );
        document.getElementById( 'me-action-confirm' ).addEventListener( 'click', confirmAction );
        document.addEventListener( 'keydown', ( e ) => {
            if ( e.key === 'Escape' ) { maybeCloseModal(); closeDeleteModal(); closeActionModal(); }
        } );
    }

    function activateTab( id ) {
        if ( ! state[ id ] ) return;
        activeId = id;
        document.querySelectorAll( '.me-fin-tab' ).forEach( ( b ) => {
            b.classList.toggle( 'me-fin-tab-active', b.dataset.list === id );
        } );
        document.querySelectorAll( '.me-fin-section' ).forEach( ( s ) => {
            s.classList.toggle( 'me-hidden', s.id !== `me-fin-section-${ id }` );
        } );

        const list   = state[ id ].list;
        const newBtn = document.getElementById( 'me-fin-new' );
        if ( list.editable && list.canEdit ) {
            newBtn.textContent = T.btnNew || '+ New entry';
            newBtn.classList.remove( 'me-hidden' );
        } else {
            newBtn.classList.add( 'me-hidden' );
        }
        updateCountBadge();
    }

    // ── List section (table) ──────────────────────────────────────
    function buildListSection( list ) {
        const section = document.getElementById( `me-fin-section-${ list.id }` );
        if ( ! section ) return;

        section.innerHTML = `
        <div class="me-table-card">
            <div class="me-toolbar">
                <span class="me-toolbar-info" id="me-fin-visible-${ escAttr( list.id ) }"></span>
                <div class="me-search-box">
                    <input type="search" class="me-fin-search" data-list="${ escAttr( list.id ) }"
                           placeholder="${ escAttr( T.searchPh || 'Quick search…' ) }" autocomplete="off">
                </div>
            </div>
            <table>
                <thead>
                    <tr class="me-head-row" id="me-fin-head-${ escAttr( list.id ) }"></tr>
                    <tr class="me-filter-row" id="me-fin-filter-${ escAttr( list.id ) }"></tr>
                </thead>
                <tbody id="me-fin-tbody-${ escAttr( list.id ) }"></tbody>
            </table>
        </div>`;

        const headRow   = document.getElementById( `me-fin-head-${ list.id }` );
        const filterRow = document.getElementById( `me-fin-filter-${ list.id }` );

        ( list.columns || [] ).forEach( ( col ) => {
            const th = document.createElement( 'th' );
            th.dataset.col = col.key;
            if ( col.align ) th.style.textAlign = col.align;
            if ( col.sortable ) {
                th.classList.add( 'sortable' );
                th.innerHTML = `${ escHtml( col.label ) }<span class="sort-icon" aria-hidden="true"></span>`;
                th.addEventListener( 'click', () => toggleSort( list.id, col.key ) );
            } else {
                th.textContent = col.label;
            }
            headRow.appendChild( th );

            const td    = document.createElement( 'td' );
            const input = document.createElement( 'input' );
            input.type        = 'text';
            input.className    = 'me-filter-input';
            input.dataset.col  = col.key;
            input.placeholder  = `${ T.filterWord || 'Filter' } ${ col.label.toLowerCase() }…`;
            input.autocomplete = 'off';
            input.addEventListener( 'input', () => renderRows( list.id ) );
            td.appendChild( input );
            filterRow.appendChild( td );
        } );

        // Actions column (editable lists or lists with custom row actions).
        if ( hasActions( list ) ) {
            headRow.appendChild( document.createElement( 'th' ) );
            filterRow.appendChild( document.createElement( 'td' ) );
        }

        section.querySelector( '.me-fin-search' )
            .addEventListener( 'input', () => renderRows( list.id ) );

        updateSortClasses( list.id );
    }

    function updateSortClasses( id ) {
        const st = state[ id ];
        document.querySelectorAll( `#me-fin-head-${ id } th[data-col]` ).forEach( ( th ) => {
            th.classList.remove( 'sort-asc', 'sort-desc' );
            if ( th.dataset.col === st.sortCol ) {
                th.classList.add( st.sortDir === 'asc' ? 'sort-asc' : 'sort-desc' );
            }
        } );
    }

    function toggleSort( id, col ) {
        const st = state[ id ];
        if ( st.sortCol === col ) {
            st.sortDir = st.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            st.sortCol = col;
            st.sortDir = 'asc';
        }
        updateSortClasses( id );
        renderRows( id );
    }

    // ── Data loading ──────────────────────────────────────────────
    async function loadList( id ) {
        const st = state[ id ];
        try {
            const res = await fetch( st.list.rest, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            st.rows = await res.json();
            if ( id === activeId ) updateCountBadge();
            renderRows( id );
        } catch ( err ) {
            const tbody = document.getElementById( `me-fin-tbody-${ id }` );
            if ( tbody ) {
                tbody.innerHTML = `<tr><td colspan="${ colSpan( st.list ) }">
                    <div class="me-empty-state">${ ICON_EMPTY }
                        <p>${ escHtml( T.errorLoad || 'Failed to load: ' ) }${ escHtml( err.message ) }</p>
                    </div></td></tr>`;
            }
        }
    }

    function colSpan( list ) {
        return ( list.columns || [] ).length + ( hasActions( list ) ? 1 : 0 );
    }

    // True when the list shows an actions column: either CRUD (edit/delete) or
    // custom per-row actions registered by an extension (e.g. Orders refund).
    function hasActions( list ) {
        return ( list.editable && list.canEdit )
            || ( Array.isArray( list.rowActions ) && list.rowActions.length > 0 );
    }

    function updateCountBadge() {
        const el = document.getElementById( 'me-fin-count' );
        if ( ! el || ! activeId ) return;
        const n = state[ activeId ].rows.length;
        el.textContent = `${ n } ${ T.totalSuffix || 'total' }`;
    }

    // ── Row rendering ─────────────────────────────────────────────
    function renderRows( id ) {
        const st   = state[ id ];
        const list = st.list;
        const searchEl = document.querySelector( `.me-fin-search[data-list="${ id }"]` );
        const q = ( searchEl?.value || '' ).toLowerCase().trim();

        const colFilters = {};
        document.querySelectorAll( `#me-fin-section-${ id } .me-filter-input` ).forEach( ( input ) => {
            const v = input.value.trim().toLowerCase();
            if ( v ) colFilters[ input.dataset.col ] = v;
        } );

        let rows = st.rows.filter( ( r ) => {
            if ( q && ! Object.values( r ).some( ( v ) => String( v ?? '' ).toLowerCase().includes( q ) ) ) {
                return false;
            }
            for ( const [ col, val ] of Object.entries( colFilters ) ) {
                if ( ! String( r[ col ] ?? '' ).toLowerCase().includes( val ) ) return false;
            }
            return true;
        } );

        const sortType = colType( list, st.sortCol );
        rows = rows.slice().sort( ( a, b ) => {
            let cmp;
            if ( sortType === 'money' ) {
                cmp = ( Number( a[ st.sortCol ] ) || 0 ) - ( Number( b[ st.sortCol ] ) || 0 );
            } else {
                cmp = String( a[ st.sortCol ] ?? '' )
                    .localeCompare( String( b[ st.sortCol ] ?? '' ), undefined, { numeric: true, sensitivity: 'base' } );
            }
            return st.sortDir === 'asc' ? cmp : -cmp;
        } );

        const tbody = document.getElementById( `me-fin-tbody-${ id }` );
        if ( ! tbody ) return;

        const visEl = document.getElementById( `me-fin-visible-${ id }` );
        if ( visEl ) {
            visEl.textContent = `${ T.showing || 'Showing' } ${ rows.length } ${ T.of || 'of' } ${ st.rows.length }`;
        }

        if ( rows.length === 0 ) {
            const msg = st.rows.length === 0 ? ( T.emptyNone || 'No entries yet.' ) : ( T.emptyFiltered || 'No entries match the current filters.' );
            tbody.innerHTML = `<tr><td colspan="${ colSpan( list ) }">
                <div class="me-empty-state">${ ICON_EMPTY }<p>${ escHtml( msg ) }</p></div></td></tr>`;
            return;
        }

        const editable = list.editable && list.canEdit;
        const showActions = hasActions( list );
        tbody.innerHTML = rows.map( ( r ) => {
            const cells = ( list.columns || [] ).map( ( col ) => renderCell( col, r, list ) ).join( '' );
            const actions = showActions
                ? `<td class="me-row-actions">${ rowActionButtons( list, r, editable ) }</td>`
                : '';
            return `<tr>${ cells }${ actions }</tr>`;
        } ).join( '' );

        if ( editable ) {
            tbody.querySelectorAll( '[data-edit]' ).forEach( ( b ) =>
                b.addEventListener( 'click', () => openModal( Number( b.dataset.edit ) ) ) );
            tbody.querySelectorAll( '[data-del]' ).forEach( ( b ) =>
                b.addEventListener( 'click', () => openDeleteModal( Number( b.dataset.del ) ) ) );
            tbody.querySelectorAll( '.me-bool-toggle:not([disabled])' ).forEach( ( cb ) =>
                cb.addEventListener( 'change', () => toggleBool( list, cb ) ) );
        }

        // Custom per-row actions (e.g. Orders refund / resend).
        if ( Array.isArray( list.rowActions ) && list.rowActions.length ) {
            tbody.querySelectorAll( '[data-action]' ).forEach( ( b ) => {
                b.addEventListener( 'click', () => {
                    const action = list.rowActions.find( ( a ) => a.id === b.dataset.action );
                    const row    = st.rows.find( ( r ) => String( r.id ) === b.dataset.row );
                    if ( action && row ) handleRowAction( action, row );
                } );
            } );
        }
    }

    // Build the inner HTML of the per-row actions cell.
    function rowActionButtons( list, row, editable ) {
        let html = '';
        if ( editable ) {
            html += `<button class="me-icon-btn" data-edit="${ escAttr( row.id ) }" title="${ escAttr( T.btnEdit || 'Edit' ) }">${ ICON_EDIT }</button>`
                +  `<button class="me-icon-btn me-icon-danger" data-del="${ escAttr( row.id ) }" title="${ escAttr( T.btnDelete || 'Delete' ) }">${ ICON_DEL }</button>`;
        }
        ( list.rowActions || [] ).forEach( ( action ) => {
            if ( action.visibleField && ! row[ action.visibleField ] ) return;
            const icon = ACTION_ICONS[ action.icon ];
            const danger = action.kind === 'danger' ? ' me-icon-danger' : '';
            html += `<button class="me-icon-btn${ danger }" data-action="${ escAttr( action.id ) }" data-row="${ escAttr( row.id ) }" title="${ escAttr( action.label ) }">${ icon || escHtml( action.label ) }</button>`;
        } );
        return html;
    }

    function renderCell( col, row, list ) {
        const val = row[ col.key ];
        const align = col.align === 'center' ? ' me-cell-center' : ( col.align === 'right' ? ' me-cell-right' : '' );
        switch ( col.type ) {
            case 'money': {
                const cents = Number( val ) || 0;
                const cls = cents < 0 ? ' me-amount-neg' : '';
                return `<td class="me-amount${ cls }">${ escHtml( money.format( cents / 100 ) ) }</td>`;
            }
            case 'datetime':
                return `<td class="me-cell${ align }">${ escHtml( fmtDate( val ) ) }</td>`;
            case 'bool': {
                const canToggle = !!( list && list.editable && list.canEdit );
                const checked = val ? ' checked' : '';
                const dis = canToggle ? '' : ' disabled';
                return `<td class="me-bool-cell"><input type="checkbox" class="me-bool-toggle"${ checked }${ dis } data-row="${ escAttr( row.id ) }" data-key="${ escAttr( col.key ) }"></td>`;
            }
            case 'extlink': {
                const url = String( val ?? '' );
                if ( ! url ) return `<td class="me-cell${ align }"></td>`;
                return `<td class="me-cell${ align }"><a class="me-btn-xs" href="${ escAttr( url ) }" target="_blank" rel="noopener">${ escHtml( col.linkLabel || T.viewLabel || 'Open' ) }</a></td>`;
            }
            case 'media': {
                const url = row[ col.key === 'media_id' ? 'media_url' : col.key + '_url' ];
                return url
                    ? `<td class="me-cell${ align }"><a class="me-link" href="${ escAttr( url ) }" target="_blank" rel="noopener">${ escHtml( T.viewLabel || 'Open' ) }</a></td>`
                    : `<td class="me-cell${ align }"></td>`;
            }
            case 'link': {
                const url = row[ col.linkKey || ( col.key + '_url' ) ];
                const text = String( val ?? '' );
                return url
                    ? `<td class="me-cell${ align }"><a class="me-link" href="${ escAttr( url ) }" target="_blank" rel="noopener">${ escHtml( text ) }</a></td>`
                    : `<td class="me-cell${ align }">${ escHtml( text ) }</td>`;
            }
            default:
                return `<td class="me-cell${ align }">${ escHtml( val ?? '' ) }</td>`;
        }
    }

    function colType( list, key ) {
        const c = ( list.columns || [] ).find( ( x ) => x.key === key );
        return c ? c.type : 'text';
    }

    // ── Create / Edit modal ───────────────────────────────────────
    function formColumns( list ) {
        return ( list.columns || [] ).filter( ( c ) => c.form );
    }

    function openModal( id ) {
        const list = state[ activeId ].list;
        if ( ! list.editable || ! list.canEdit ) return;
        editId = id;

        const row = id !== null ? state[ activeId ].rows.find( ( r ) => Number( r.id ) === id ) : null;
        document.getElementById( 'me-modal-title-text' ).textContent =
            id === null ? ( T.titleCreate || 'New entry' ) : ( T.titleEdit || 'Edit entry' );

        const form = document.getElementById( 'me-form' );
        form.innerHTML = formColumns( list ).map( ( col ) => fieldHtml( col, row ) ).join( '' );

        // Wire media pickers.
        form.querySelectorAll( '.me-media-pick' ).forEach( ( btn ) =>
            btn.addEventListener( 'click', () => pickMedia( btn.dataset.field ) ) );
        form.querySelectorAll( '.me-media-remove' ).forEach( ( btn ) =>
            btn.addEventListener( 'click', () => clearMedia( btn.dataset.field ) ) );

        document.getElementById( 'me-modal-overlay' ).classList.remove( 'me-hidden' );
        dirtySnapshot = captureFormSnapshot();
        const first = form.querySelector( 'input, select, textarea' );
        if ( first ) first.focus();
    }

    function fieldHtml( col, row ) {
        const id  = `f-${ col.key }`;
        const lbl = escHtml( col.label );
        if ( col.type === 'money' ) {
            const v = row ? ( ( Number( row[ col.key ] ) || 0 ) / 100 ) : '';
            return `<div class="me-form-row"><div class="me-form-group">
                <label class="me-form-label" for="${ id }">${ lbl }</label>
                <input class="me-form-input" type="number" step="0.01" id="${ id }" name="${ col.key }"
                       value="${ escAttr( v ) }" inputmode="decimal">
                <span class="me-field-error" id="err-${ col.key }"></span>
                <span class="me-form-hint">${ escHtml( T.hintNegative || 'Use a negative amount for money paid out.' ) }</span>
            </div></div>`;
        }
        if ( col.type === 'datetime' ) {
            const v = row ? toLocalInput( row[ col.key ] ) : toLocalInput( nowMysql() );
            return `<div class="me-form-row"><div class="me-form-group">
                <label class="me-form-label" for="${ id }">${ lbl }</label>
                <input class="me-form-input" type="datetime-local" id="${ id }" name="${ col.key }" value="${ escAttr( v ) }">
            </div></div>`;
        }
        if ( col.type === 'media' ) {
            const mediaId  = row ? ( row[ col.key ] || '' ) : '';
            const mediaUrl = row ? ( row.media_url || '' ) : '';
            const name = mediaUrl ? decodeURIComponent( mediaUrl.split( '/' ).pop() ) : ( T.noFileChosen || 'No file chosen' );
            return `<div class="me-form-row"><div class="me-form-group">
                <label class="me-form-label">${ escHtml( T.fieldAttach || lbl ) }</label>
                <input type="hidden" name="${ col.key }" id="${ id }" value="${ escAttr( mediaId ) }">
                <div class="me-media-field">
                    <span class="me-media-name" id="${ id }-name">${ escHtml( name ) }</span>
                    <button type="button" class="me-btn me-btn-secondary me-media-pick" data-field="${ col.key }">${ escHtml( T.btnChooseFile || 'Choose…' ) }</button>
                    <button type="button" class="me-btn me-btn-link me-media-remove${ mediaId ? '' : ' me-hidden' }" data-field="${ col.key }">${ escHtml( T.btnRemoveFile || 'Remove' ) }</button>
                </div>
            </div></div>`;
        }
        if ( col.type === 'bool' ) {
            const on = row ? !! row[ col.key ] : false;
            return `<div class="me-form-row"><div class="me-form-group">
                <label class="me-check-label">
                    <input type="checkbox" id="${ id }" name="${ col.key }"${ on ? ' checked' : '' }>
                    <span>${ lbl }</span>
                </label>
            </div></div>`;
        }
        // Default: text input.
        const v = row ? ( row[ col.key ] ?? '' ) : '';
        return `<div class="me-form-row"><div class="me-form-group">
            <label class="me-form-label" for="${ id }">${ lbl }</label>
            <input class="me-form-input" type="text" id="${ id }" name="${ col.key }" value="${ escAttr( v ) }">
        </div></div>`;
    }
    function maybeCloseModal() {
        if ( isDirty() && ! window.confirm( T.confirmDiscard || 'Discard changes?' ) ) return;
        closeModal();
    }
    function closeModal() {
        document.getElementById( 'me-modal-overlay' )?.classList.add( 'me-hidden' );
        editId = null;
        dirtySnapshot = null;
    }

    async function submitForm() {
        const list = state[ activeId ].list;
        const cols = formColumns( list );
        const data = {};
        let amountInvalid = false;

        cols.forEach( ( col ) => {
            const el = document.getElementById( `f-${ col.key }` );
            if ( ! el ) return;
            if ( col.type === 'money' ) {
                const raw = el.value.trim().replace( ',', '.' );
                if ( raw === '' || isNaN( Number( raw ) ) ) { amountInvalid = true; return; }
                data[ col.key ] = Math.round( Number( raw ) * 100 );
            } else if ( col.type === 'media' ) {
                data[ col.key ] = el.value ? Number( el.value ) : null;
            } else if ( col.type === 'bool' ) {
                data[ col.key ] = el.checked ? 1 : 0;
            } else {
                data[ col.key ] = el.value;
            }
        } );

        clearErrors();
        if ( amountInvalid ) { setError( 'amount_cents', T.errAmount || 'Enter a valid amount.' ); return; }

        const saveBtn = document.getElementById( 'me-btn-save' );
        saveBtn.disabled = true;
        saveBtn.textContent = T.stateSaving || 'Saving…';

        try {
            const isCreate = editId === null;
            const url = isCreate ? list.rest : `${ list.rest }/${ editId }`;
            const res = await fetch( url, {
                method:  isCreate ? 'POST' : 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( data ),
            } );
            if ( ! res.ok ) {
                const e = await res.json().catch( () => ( {} ) );
                throw new Error( e.message || `HTTP ${ res.status }` );
            }
            const saved = await res.json();
            const st = state[ activeId ];
            if ( isCreate ) {
                showToast( T.toastCreated || 'Entry created.', 'success' );
            } else {
                showToast( T.toastUpdated || 'Entry updated.', 'success' );
            }
            // Reload to recompute running balances across all rows.
            await loadList( activeId );
            updateCountBadge();
            closeModal();
        } catch ( err ) {
            showToast( ( T.errSave || 'Save failed: ' ) + err.message, 'error' );
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = T.btnSave || 'Save';
        }
    }

    // ── Inline boolean toggle (e.g. Verified) ────────────────────
    async function toggleBool( list, cb ) {
        const id  = Number( cb.dataset.row );
        const key = cb.dataset.key;
        const val = cb.checked ? 1 : 0;
        cb.disabled = true;
        try {
            const res = await fetch( `${ list.rest }/${ id }`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( { [ key ]: val } ),
            } );
            if ( ! res.ok ) {
                const e = await res.json().catch( () => ( {} ) );
                throw new Error( e.message || `HTTP ${ res.status }` );
            }
            const row = state[ activeId ].rows.find( ( r ) => Number( r.id ) === id );
            if ( row ) row[ key ] = val;
            showToast( T.toastSaved || 'Saved.', 'success' );
        } catch ( err ) {
            cb.checked = ! cb.checked; // revert
            showToast( ( T.errSave || 'Save failed: ' ) + err.message, 'error' );
        } finally {
            cb.disabled = false;
        }
    }

    // ── Delete ────────────────────────────────────────────────────
    function openDeleteModal( id ) {
        deleteId = id;
        const list = state[ activeId ].list;
        const row  = state[ activeId ].rows.find( ( r ) => Number( r.id ) === id );
        const label = row ? ( row.title || row.entry_date || ( '#' + id ) ) : ( '#' + id );
        document.getElementById( 'me-delete-text' ).innerHTML =
            `${ escHtml( T.titleDelete || 'Delete entry' ) }: <strong>${ escHtml( label ) }</strong>`;
        document.getElementById( 'me-delete-overlay' ).classList.remove( 'me-hidden' );
        document.getElementById( 'me-delete-confirm' ).focus();
    }
    function closeDeleteModal() {
        document.getElementById( 'me-delete-overlay' )?.classList.add( 'me-hidden' );
        deleteId = null;
    }
    async function confirmDelete() {
        if ( deleteId === null ) return;
        const list = state[ activeId ].list;
        const btn  = document.getElementById( 'me-delete-confirm' );
        btn.disabled = true;
        btn.textContent = T.stateDeleting || 'Deleting…';
        try {
            const res = await fetch( `${ list.rest }/${ deleteId }`, {
                method: 'DELETE', headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok && res.status !== 204 ) throw new Error( `HTTP ${ res.status }` );
            showToast( T.toastDeleted || 'Entry deleted.', 'info' );
            closeDeleteModal();
            await loadList( activeId );
            updateCountBadge();
        } catch ( err ) {
            showToast( ( T.errDelete || 'Delete failed: ' ) + err.message, 'error' );
        } finally {
            btn.disabled = false;
            btn.textContent = T.btnDelete || 'Delete';
        }
    }

    // ── Row actions (extension-provided, e.g. Orders refund / resend) ──
    function handleRowAction( action, row ) {
        const hasAmount = !! action.amountParam;
        const total     = action.totalField ? Number( row[ action.totalField ] ) || 0 : 0;
        // A money action on a non-free order opens the amount modal; everything
        // else (free-order cancel or a plain confirm) uses the simple modal.
        openActionModal( action, row, hasAmount && total > 0 );
    }

    function openActionModal( action, row, withAmount ) {
        actionCtx = { action, row, withAmount };
        const i18n  = action.i18n || {};
        const total = action.totalField ? Number( row[ action.totalField ] ) || 0 : 0;

        document.getElementById( 'me-action-title' ).textContent = i18n.title || action.label || '';

        const amountRow  = document.getElementById( 'me-action-amount-row' );
        const textEl     = document.getElementById( 'me-action-text' );
        const confirmBtn = document.getElementById( 'me-action-confirm' );
        const errEl      = document.getElementById( 'me-action-amount-err' );
        if ( errEl ) errEl.textContent = '';

        if ( withAmount ) {
            const maxCents = action.maxField ? Number( row[ action.maxField ] ) || 0 : total;
            const input = document.getElementById( 'me-action-amount' );
            input.max   = ( maxCents / 100 ).toFixed( 2 );
            input.value = ( maxCents / 100 ).toFixed( 2 );
            document.getElementById( 'me-action-amount-label' ).textContent = i18n.amountLabel || action.label || '';
            document.getElementById( 'me-action-amount-hint' ).textContent  = i18n.amountHint || '';
            amountRow.classList.remove( 'me-hidden' );
            textEl.textContent = '';
            confirmBtn.textContent = i18n.submit || action.label || ( T.btnSave || 'OK' );
        } else {
            amountRow.classList.add( 'me-hidden' );
            // Free-order (money action with total <= 0) vs a plain confirm.
            if ( action.amountParam && total <= 0 ) {
                textEl.textContent = i18n.freeText || '';
                confirmBtn.textContent = i18n.submitFree || i18n.submit || action.label || '';
            } else {
                textEl.textContent = action.confirm || i18n.text || '';
                confirmBtn.textContent = i18n.submit || action.label || ( T.btnSave || 'OK' );
            }
        }

        confirmBtn.className = 'me-btn ' + ( action.kind === 'danger' ? 'me-btn-danger' : 'me-btn-primary' );
        document.getElementById( 'me-action-overlay' ).classList.remove( 'me-hidden' );
        confirmBtn.focus();
    }

    function closeActionModal() {
        document.getElementById( 'me-action-overlay' )?.classList.add( 'me-hidden' );
        actionCtx = null;
    }

    async function confirmAction() {
        if ( ! actionCtx ) return;
        const { action, row, withAmount } = actionCtx;
        const body = {};

        if ( withAmount ) {
            const input = document.getElementById( 'me-action-amount' );
            const raw   = String( input.value ).trim().replace( ',', '.' );
            const val   = Number( raw );
            const errEl = document.getElementById( 'me-action-amount-err' );
            if ( raw === '' || isNaN( val ) || val <= 0 ) {
                if ( errEl ) errEl.textContent = T.errAmount || 'Enter a valid amount.';
                input.focus();
                return;
            }
            body[ action.amountParam ] = Math.round( val * 100 );
        }

        await runAction( action, row, body );
    }

    // Perform an action's REST call (from the action modal) and refresh the list.
    async function runAction( action, row, body ) {
        const i18n = action.i18n || {};
        const confirmBtn = document.getElementById( 'me-action-confirm' );
        if ( confirmBtn ) {
            confirmBtn.disabled = true;
            confirmBtn.textContent = i18n.working || T.stateSaving || 'Working…';
        }
        try {
            const url = `${ state[ activeId ].list.rest }/${ row.id }${ action.path || '' }`;
            const res = await fetch( url, {
                method:  action.method || 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( body || {} ),
            } );
            if ( ! res.ok ) {
                const e = await res.json().catch( () => ( {} ) );
                throw new Error( e.message || `HTTP ${ res.status }` );
            }
            showToast( i18n.toast || ( T.toastUpdated || 'Done.' ), 'success' );
            closeActionModal();
            await loadList( activeId );
            updateCountBadge();
        } catch ( err ) {
            showToast( ( i18n.error ? i18n.error + ' ' : '' ) + err.message, 'error' );
        } finally {
            if ( confirmBtn ) {
                confirmBtn.disabled = false;
                confirmBtn.textContent = i18n.submit || action.label || ( T.btnSave || 'OK' );
            }
        }
    }

    // ── Media picker (WP media library) ───────────────────────────
    function pickMedia( field ) {
        if ( ! window.wp || ! window.wp.media ) {
            showToast( T.noMediaLib || 'Media library unavailable.', 'error' );
            return;
        }
        mediaFrame = window.wp.media( {
            title: T.mediaTitle || 'Select Attachment',
            button: { text: T.mediaBtn || 'Use this file' },
            multiple: false,
        } );
        mediaFrame.on( 'select', () => {
            const att = mediaFrame.state().get( 'selection' ).first().toJSON();
            const hidden = document.getElementById( `f-${ field }` );
            const nameEl = document.getElementById( `f-${ field }-name` );
            const rmBtn  = document.querySelector( `.me-media-remove[data-field="${ field }"]` );
            if ( hidden ) hidden.value = att.id;
            if ( nameEl ) nameEl.textContent = att.filename || att.title || ( '#' + att.id );
            if ( rmBtn )  rmBtn.classList.remove( 'me-hidden' );
        } );
        mediaFrame.open();
    }
    function clearMedia( field ) {
        const hidden = document.getElementById( `f-${ field }` );
        const nameEl = document.getElementById( `f-${ field }-name` );
        const rmBtn  = document.querySelector( `.me-media-remove[data-field="${ field }"]` );
        if ( hidden ) hidden.value = '';
        if ( nameEl ) nameEl.textContent = T.noFileChosen || 'No file chosen';
        if ( rmBtn )  rmBtn.classList.add( 'me-hidden' );
    }

    // ── Toast ─────────────────────────────────────────────────────
    function showToast( msg, type ) {
        const toast = document.getElementById( 'me-toast' );
        if ( ! toast ) return;
        toast.textContent = msg;
        toast.className = `me-toast ${ type }`;
        if ( toastTimer ) clearTimeout( toastTimer );
        toastTimer = setTimeout( () => toast.classList.add( 'me-hidden' ), 3500 );
    }

    // ── Form helpers ──────────────────────────────────────────────
    const FORM = () => document.getElementById( 'me-form' );
    function captureFormSnapshot() {
        const form = FORM();
        if ( ! form ) return {};
        const snap = {};
        form.querySelectorAll( '[name]' ).forEach( ( el ) => { snap[ el.name ] = el.type === 'checkbox' ? ( el.checked ? '1' : '0' ) : el.value; } );
        return snap;
    }
    function isDirty() {
        if ( ! dirtySnapshot ) return false;
        const form = FORM();
        if ( ! form ) return false;
        for ( const el of form.querySelectorAll( '[name]' ) ) {
            const cur = el.type === 'checkbox' ? ( el.checked ? '1' : '0' ) : el.value;
            if ( ( dirtySnapshot[ el.name ] ?? '' ) !== cur ) return true;
        }
        return false;
    }
    function clearErrors() {
        document.querySelectorAll( '.me-field-error' ).forEach( ( el ) => { el.textContent = ''; } );
        document.querySelectorAll( '.me-form-input.is-invalid' ).forEach( ( el ) => el.classList.remove( 'is-invalid' ) );
    }
    function setError( fieldKey, msg ) {
        const errEl = document.getElementById( `err-${ fieldKey }` );
        const input = document.getElementById( `f-${ fieldKey }` );
        if ( errEl ) errEl.textContent = msg;
        if ( input ) { input.classList.add( 'is-invalid' ); input.focus(); }
    }

    // ── Date helpers ──────────────────────────────────────────────
    function fmtDate( mysql ) {
        if ( ! mysql ) return '';
        const d = new Date( String( mysql ).replace( ' ', 'T' ) );
        if ( isNaN( d.getTime() ) ) return String( mysql );
        return d.toLocaleString( CFG.locale || 'da-DK', {
            year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
        } );
    }
    function toLocalInput( mysql ) {
        if ( ! mysql ) return '';
        return String( mysql ).replace( ' ', 'T' ).slice( 0, 16 );
    }
    function nowMysql() {
        const d = new Date();
        const p = ( n ) => String( n ).padStart( 2, '0' );
        return `${ d.getFullYear() }-${ p( d.getMonth() + 1 ) }-${ p( d.getDate() ) } ${ p( d.getHours() ) }:${ p( d.getMinutes() ) }:00`;
    }

    // ── Security helpers ──────────────────────────────────────────
    function escHtml( str ) {
        return String( str ?? '' )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
    }
    function escAttr( str ) { return escHtml( str ); }

} )();
