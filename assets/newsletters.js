/* ================================================================
   PCIO VIS Member Event — newsletters.js
   Generated-newsletters list: REST-backed table with filter, sort
   and delete. Styled like the other vis/ admin lists.
   ================================================================ */

( function () {
    'use strict';

    // ── State ─────────────────────────────────────────────────────
    let rows       = [];
    let sortCol    = 'generated_at';
    let sortDir    = 'desc';
    let deleteId   = null;
    let toastTimer = null;

    const REST     = ( PCIO_ME.rest + '/newsletters' ).replace( /([^:]\/)\/+/g, '$1' );
    const NONCE    = PCIO_ME.nonce;
    const CAN_EDIT = PCIO_ME.canEdit;
    const T        = PCIO_ME.i18n;

    const COLUMNS = [
        { key: 'title',        label: T.colTitle,     sortable: true  },
        { key: 'event_count',  label: T.colEvents,    sortable: true  },
        { key: 'event_max_date', label: T.colMaxDate, sortable: true  },
        { key: 'generated_at', label: T.colGenerated, sortable: true  },
        { key: 'url',          label: T.colFile,      sortable: false },
    ];

    const ICON_DEL   = `<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>`;
    const ICON_EMPTY = `<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>`;

    // ── Bootstrap ─────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', () => {
        buildShell();
        loadRows();
    } );

    // ── Page shell ────────────────────────────────────────────────
    function buildShell() {
        const app = document.getElementById( 'pcio-me-app' );
        if ( ! app ) return;

        app.innerHTML = `
        <div class="me-page-header">
            <h1 class="me-page-title">${ escHtml( T.pageTitle ) }</h1>
            <div class="me-header-right">
                <span class="me-member-count" id="me-count"></span>
                <a class="me-btn me-btn-secondary" href="${ escAttr( PCIO_ME.mailsUrl || '#' ) }">${ escHtml( T.gotoMails ) }</a>
            </div>
        </div>

        <div class="me-table-card">
            <div class="me-toolbar">
                <span class="me-toolbar-info" id="me-visible-count"></span>
                <div class="me-search-box">
                    <input type="search" id="me-global-search" placeholder="${ escAttr( T.searchPh ) }">
                </div>
            </div>
            <div class="me-table-scroll">
                <table class="me-table" id="me-table">
                    <thead>
                        <tr class="me-head-row" id="me-head-row"></tr>
                        <tr class="me-filter-row" id="me-filter-row"></tr>
                    </thead>
                    <tbody id="me-tbody"></tbody>
                </table>
                <div class="me-empty-state me-hidden" id="me-empty"></div>
            </div>
        </div>

        <!-- Delete confirm modal -->
        <div class="me-overlay me-hidden" id="me-del-overlay" role="alertdialog" aria-modal="true">
            <div class="me-modal me-modal-sm">
                <div class="me-modal-header">
                    <h2 class="me-modal-title">${ escHtml( T.titleDelete ) }</h2>
                    <button class="me-modal-close" id="me-del-close" aria-label="${ escAttr( T.closeLabel ) }">&times;</button>
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

        buildHead();
        document.getElementById( 'me-global-search' ).addEventListener( 'input', renderRows );
        document.getElementById( 'me-del-close'   ).addEventListener( 'click', closeDeleteModal );
        document.getElementById( 'me-del-cancel'  ).addEventListener( 'click', closeDeleteModal );
        document.getElementById( 'me-del-confirm' ).addEventListener( 'click', confirmDelete );
    }

    function buildHead() {
        const head   = document.getElementById( 'me-head-row' );
        const filter = document.getElementById( 'me-filter-row' );

        head.innerHTML = COLUMNS.map( c => {
            const cls  = c.sortable ? 'sortable' : '';
            const icon = c.sortable ? '<span class="sort-icon" aria-hidden="true"></span>' : '';
            return `<th data-col="${ c.key }" class="${ cls }">${ escHtml( c.label ) }${ icon }</th>`;
        } ).join( '' ) + ( CAN_EDIT ? '<th class="me-col-actions"></th>' : '' );

        filter.innerHTML = COLUMNS.map( c => {
            if ( c.key === 'url' ) return '<td></td>';
            return `<td><input type="text" class="me-filter-input" data-col="${ c.key }" placeholder="${ escAttr( T.filterWord ) }"></td>`;
        } ).join( '' ) + ( CAN_EDIT ? '<td></td>' : '' );

        head.querySelectorAll( '.sortable' ).forEach( th => {
            th.addEventListener( 'click', () => toggleSort( th.dataset.col ) );
        } );
        filter.querySelectorAll( '.me-filter-input' ).forEach( inp => {
            inp.addEventListener( 'input', renderRows );
        } );
        updateSortClasses();
    }

    // ── Data ──────────────────────────────────────────────────────
    async function loadRows() {
        try {
            const res = await fetch( REST, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            rows = await res.json();
            renderRows();
        } catch ( err ) {
            showToast( T.errLoad + err.message, 'error' );
        }
    }

    // ── Render ────────────────────────────────────────────────────
    function toggleSort( col ) {
        if ( sortCol === col ) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortCol = col;
            sortDir = 'asc';
        }
        updateSortClasses();
        renderRows();
    }

    function updateSortClasses() {
        document.querySelectorAll( '#me-head-row .sortable' ).forEach( th => {
            th.classList.remove( 'sort-asc', 'sort-desc' );
            if ( th.dataset.col === sortCol ) {
                th.classList.add( sortDir === 'asc' ? 'sort-asc' : 'sort-desc' );
            }
        } );
    }

    function renderRows() {
        const tbody = document.getElementById( 'me-tbody' );
        const empty = document.getElementById( 'me-empty' );
        const count = document.getElementById( 'me-count' );
        const vis   = document.getElementById( 'me-visible-count' );
        if ( ! tbody ) return;

        const q = ( document.getElementById( 'me-global-search' )?.value || '' ).toLowerCase().trim();
        const colFilters = {};
        document.querySelectorAll( '.me-filter-input' ).forEach( inp => {
            const v = inp.value.trim().toLowerCase();
            if ( v ) colFilters[ inp.dataset.col ] = v;
        } );

        let filtered = rows.filter( r => {
            if ( q && ! [ r.title, r.event_count, r.event_max_date, fmtDate( r.generated_at ) ]
                .some( v => String( v ?? '' ).toLowerCase().includes( q ) ) ) return false;
            for ( const [ col, val ] of Object.entries( colFilters ) ) {
                const cell = col === 'generated_at' ? fmtDate( r.generated_at ) : String( r[ col ] ?? '' );
                if ( ! cell.toLowerCase().includes( val ) ) return false;
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
            const word = rows.length !== 1 ? T.newsletterPlural : T.newsletterSingular;
            count.textContent = `${ rows.length } ${ word }`;
        }
        if ( vis ) {
            vis.textContent = filtered.length < rows.length
                ? `${ T.showing } ${ filtered.length } ${ T.of } ${ rows.length } ${ T.newsletterPlural }`
                : `${ rows.length } ${ rows.length !== 1 ? T.newsletterPlural : T.newsletterSingular } ${ T.totalSuffix }`;
        }

        if ( filtered.length === 0 ) {
            tbody.innerHTML = '';
            empty.innerHTML = `${ ICON_EMPTY }<p>${ escHtml( rows.length === 0 ? T.emptyNone : T.emptyFiltered ) }</p>`;
            empty.classList.remove( 'me-hidden' );
            return;
        }
        empty.classList.add( 'me-hidden' );

        tbody.innerHTML = filtered.map( buildRow ).join( '' );

        if ( CAN_EDIT ) {
            tbody.querySelectorAll( '[data-action="delete"]' ).forEach( btn => {
                btn.addEventListener( 'click', () => openDeleteModal( parseInt( btn.dataset.id, 10 ) ) );
            } );
        }
    }

    function buildRow( r ) {
        const fileCell = r.url
            ? `<a class="me-nl-link" href="${ escAttr( r.url ) }" target="_blank" rel="noopener">${ escHtml( T.open ) }</a>`
            : '<span class="me-nl-missing">—</span>';

        const actions = CAN_EDIT ? `
            <td class="me-col-actions">
                <button class="me-icon-btn me-icon-btn-danger" data-action="delete" data-id="${ r.id }" title="${ escAttr( T.deleteTitle ) }">${ ICON_DEL }</button>
            </td>` : '';

        return `<tr>
            <td class="me-cell-name">${ escHtml( r.title ) }</td>
            <td class="me-cell-number">${ escHtml( String( r.event_count ?? 0 ) ) }</td>
            <td class="me-col-date">${ escHtml( r.event_max_date || '—' ) }</td>
            <td class="me-col-date">${ fmtDate( r.generated_at ) }</td>
            <td>${ fileCell }</td>
            ${ actions }
        </tr>`;
    }

    // ── Delete ────────────────────────────────────────────────────
    function openDeleteModal( id ) {
        deleteId = id;
        const r = rows.find( x => x.id === id );
        document.getElementById( 'me-del-name' ).textContent = r ? r.title : '';
        document.getElementById( 'me-del-overlay' ).classList.remove( 'me-hidden' );
    }

    function closeDeleteModal() {
        document.getElementById( 'me-del-overlay' ).classList.add( 'me-hidden' );
        deleteId = null;
    }

    async function confirmDelete() {
        if ( ! deleteId ) return;
        const btn = document.getElementById( 'me-del-confirm' );
        btn.disabled = true;
        try {
            const res = await fetch( `${ REST }/${ deleteId }`, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok && res.status !== 204 ) throw new Error( `HTTP ${ res.status }` );
            rows = rows.filter( r => r.id !== deleteId );
            closeDeleteModal();
            renderRows();
            showToast( T.toastDeleted, 'success' );
        } catch ( err ) {
            showToast( T.errDelete + err.message, 'error' );
        } finally {
            btn.disabled = false;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────
    function fmtDate( s ) {
        if ( ! s ) return '';
        const d = new Date( String( s ).replace( ' ', 'T' ) );
        if ( isNaN( d.getTime() ) ) return String( s );
        const pad = n => String( n ).padStart( 2, '0' );
        return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) } ${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`;
    }

    function showToast( msg, type ) {
        const el = document.getElementById( 'me-toast' );
        if ( ! el ) return;
        el.textContent = msg;
        el.className = `me-toast me-toast-${ type || 'info' }`;
        clearTimeout( toastTimer );
        toastTimer = setTimeout( () => el.classList.add( 'me-hidden' ), 4000 );
    }

    function escHtml( s ) {
        return String( s ?? '' ).replace( /[&<>"']/g, c => ( {
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[ c ] ) );
    }
    function escAttr( s ) { return escHtml( s ); }
} )();
