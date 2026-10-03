/* ================================================================
   PCIO VIS Member Event — members.js
   Member list: REST-backed table with sort, filter & CRUD dialogs
   ================================================================ */

( function () {
    'use strict';

    // ── State ─────────────────────────────────────────────────────
    let members   = [];
    let visibleRows = [];  // filtered + sorted rows currently shown in the table
    let sortCol   = 'name';
    let sortDir   = 'asc';
    let editId    = null;
    let deleteId  = null;
    let toastTimer = null;    let dirtySnapshot = null;
    let activeType = 'all';   // 'all' | 'volunteers' | 'non_active'
    const REST       = ( PCIO_ME.rest + '/members'  ).replace( /([^:]\/)\/+/g, '$1' );
    const REST_ROLES = ( PCIO_ME.rest + '/vis-roles' ).replace( /([^:]\/)\/+/g, '$1' );
    const NONCE        = PCIO_ME.nonce;
    const CAN_EDIT     = PCIO_ME.canEdit;
    const T            = PCIO_ME.i18n;

    // Role option cache (fetched once)
    let visRoles = null;  // [{ id, slug, name, caps }]

    // Column definitions — built from PHP field definitions + role columns.
    // Fields with list_col=false appear in the edit modal only, not as table columns.
    const ALL_FIELDS  = PCIO_ME.memberFields || [];
    const META_FIELDS = ALL_FIELDS.filter( f => f.meta );
    const COLUMNS = [
        ...ALL_FIELDS
            .filter( f => f.list_col !== false )
            .map( f => ( { key: f.key, label: f.label, sortable: f.sortable } ) ),
        { key: 'vis_role_name', label: T.colVisRole, sortable: true },
    ];

    // SVG icons (stroke-based, currentColor)
    const ICON_EDIT = `<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
    const ICON_DEL  = `<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>`;
    const ICON_EMPTY = `<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`;

    // ── Bootstrap ─────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', () => {
        buildShell();
        const params = new URLSearchParams( window.location.search );
        // Allow deep links like /vis/members?q=1234 to pre-filter the list
        // (used by the shop subscription "customer" links).
        const q = params.get( 'q' );
        if ( q ) {
            const s = document.getElementById( 'me-global-search' );
            if ( s ) { s.value = q; }
        }
        // Exact membership-number deep link, e.g. /vis/members?member_number=42
        // (used by the boat register "crew" links). Unlike ?q this matches the
        // membership number exactly, so short numbers cannot match longer ones.
        const msn = params.get( 'member_number' );
        if ( msn ) {
            const f = document.querySelector( '.me-filter-input[data-col="member_number"]' );
            if ( f ) { f.value = msn; }
        }
        loadMembers();
    } );

    // ── Build page shell (runs once) ──────────────────────────────
    function buildSubnav( items ) {
        if ( ! Array.isArray( items ) || items.length < 2 ) return '';
        // Render subnav links left, type-filter pills right, in one flex row.
        return '<div class="me-nav-bar">' +
            '<div class="me-fin-tabs">' +
            items.map( function ( item ) {
                return '<a class="me-fin-tab' + ( item.active ? ' me-fin-tab-active' : '' ) +
                    '" href="' + escAttr( item.url ) + '">' + escHtml( item.label ) + '</a>';
            } ).join( '' ) +
            '</div>' +
            '<div class="me-type-tabs" id="me-type-tabs"></div>' +
            '</div>';
    }

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

        ${ buildSubnav( PCIO_ME.subnav ) }

        <div class="me-table-card">
            <div class="me-toolbar">
                <span class="me-toolbar-info" id="me-visible-count"></span>
                <div class="me-search-box">
                    <input type="search" id="me-global-search"
                           placeholder="${ escHtml( T.searchPh ) }"
                           autocomplete="off">
                </div>
                <button class="me-btn me-btn-secondary me-btn-sm" id="me-btn-copy-emails"
                        title="${ escHtml( T.btnCopyEmails ) }">
                    ${ escHtml( T.btnCopyEmails ) }
                </button>
            </div>

            <table id="me-table">
                <thead>
                    <tr class="me-head-row" id="me-head-row"></tr>
                    <tr class="me-filter-row" id="me-filter-row"></tr>
                </thead>
                <tbody id="me-tbody"></tbody>
            </table>
        </div>

        <!-- ── Create / Edit modal ─────────────────────────────── -->
        <div class="me-overlay me-hidden" id="me-modal-overlay"
             role="dialog" aria-modal="true" aria-labelledby="me-modal-title-text">
            <div class="me-modal">
                <div class="me-modal-header">
                    <h2 class="me-modal-title" id="me-modal-title-text">${ escHtml( T.titleCreate ) }</h2>
                    <button class="me-modal-close" id="me-modal-close"
                            aria-label="${ escHtml( T.closeDialog ) }">&times;</button>
                </div>
                <div class="me-modal-body">
                    <form id="me-form" novalidate>
                        <div class="me-form-row">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-member-number">${ escHtml( T.fieldMemberNum ) }</label>
                                <input class="me-form-input" type="text"
                                       id="f-member-number" name="member_number"
                                       placeholder="${ escHtml( T.phMemberNum ) }" maxlength="10">
                            </div>
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-name">
                                    ${ escHtml( T.fieldName ) } <span class="req">*</span>
                                </label>
                                <input class="me-form-input" type="text"
                                       id="f-name" name="name"
                                       placeholder="${ escHtml( T.phName ) }" maxlength="120" required>
                                <span class="me-field-error" id="err-name"></span>
                            </div>
                        </div>
                        <div class="me-form-row">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-email">${ escHtml( T.fieldEmail ) }</label>
                                <input class="me-form-input" type="email"
                                       id="f-email" name="email"
                                       placeholder="${ escHtml( T.phEmail ) }" maxlength="120">
                                <span class="me-field-error" id="err-email"></span>
                            </div>
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-phone">${ escHtml( T.fieldPhone ) }</label>
                                <input class="me-form-input" type="tel"
                                       id="f-phone" name="phone"
                                       placeholder="${ escHtml( T.phPhone ) }" maxlength="40">
                            </div>
                        </div>
                        <div class="me-form-row full">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-address">${ escHtml( T.fieldAddress ) }</label>
                                <textarea class="me-form-textarea"
                                          id="f-address" name="address"
                                          placeholder="${ escHtml( T.phAddress ) }"></textarea>
                            </div>
                        </div>
                        <div id="me-meta-fields"></div>

                        ${ CAN_EDIT ? `
                        <!-- Role assignment (managers / admins only) -->
                        <div class="me-form-section-header" id="me-role-section">
                            <span>${ escHtml( T.roleSectionHdr ) }</span>
                        </div>
                        <div class="me-form-row">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-vis-role">${ escHtml( T.fieldVisRole ) }</label>
                                <select class="me-form-input" id="f-vis-role" name="vis_role">
                                    <option value="">${ escHtml( T.noRole ) }</option>
                                </select>
                            </div>
                            <div class="me-form-group" style="align-self:flex-end;padding-bottom:8px">
                                <label class="me-check-row">
                                    <input type="checkbox" id="f-is-volunteer" name="is_volunteer">
                                    <span>${ escHtml( T.fieldVolunteer || 'Volunteer' ) }</span>
                                </label>
                            </div>
                        </div>` : '' }
                    </form>
                </div>
                <div class="me-modal-footer">
                    <button class="me-btn me-btn-secondary" id="me-btn-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-primary"   id="me-btn-save">${ escHtml( T.btnSave ) }</button>
                </div>
            </div>
        </div>

        <!-- ── Delete confirm modal ────────────────────────────── -->
        <div class="me-overlay me-hidden" id="me-delete-overlay"
             role="alertdialog" aria-modal="true">
            <div class="me-modal me-modal-sm">
                <div class="me-modal-header">
                    <h2 class="me-modal-title">${ escHtml( T.titleDelete ) }</h2>
                    <button class="me-modal-close" id="me-delete-close"
                            aria-label="${ escHtml( T.closeDialog ) }">&times;</button>
                </div>
                <div class="me-delete-body">
                    <div class="me-delete-icon">
                        <svg viewBox="0 0 24 24">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                            <path d="M10 11v6"/><path d="M14 11v6"/>
                            <path d="M9 6V4h6v2"/>
                        </svg>
                    </div>
                    <p class="me-delete-title">${ escHtml( T.confirmDel ) }</p>
                    <p class="me-delete-name" id="me-delete-name-text">&mdash;</p>
                    <p class="me-delete-warning">${ escHtml( T.cannotUndo ) }</p>
                </div>
                <div class="me-delete-footer">
                    <button class="me-btn me-btn-secondary" id="me-delete-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-danger"    id="me-delete-confirm">${ escHtml( T.btnDelete ) }</button>
                </div>
            </div>
        </div>

        <!-- ── Toast ───────────────────────────────────────────── -->
        <div class="me-toast me-hidden" id="me-toast" role="status" aria-live="polite"></div>
        `;

        // Static event bindings
        document.getElementById( 'me-global-search' )
            .addEventListener( 'input', renderRows );

        document.getElementById( 'me-btn-copy-emails' )
            .addEventListener( 'click', copyEmails );

        // Type tab delegation
        const typeTabsEl = document.getElementById( 'me-type-tabs' );
        if ( typeTabsEl ) {
            typeTabsEl.addEventListener( 'click', ( e ) => {
                const btn = e.target.closest( '.me-type-tab' );
                if ( btn && btn.dataset.type !== activeType ) {
                    loadMembers( btn.dataset.type );
                }
            } );
        }

        if ( CAN_EDIT ) {
            document.getElementById( 'me-btn-new'       ).addEventListener( 'click', () => openModal( null ) );
            document.getElementById( 'me-modal-close'   ).addEventListener( 'click', maybeCloseModal );
            document.getElementById( 'me-btn-cancel'    ).addEventListener( 'click', maybeCloseModal );
            document.getElementById( 'me-btn-save'      ).addEventListener( 'click', submitForm );
            document.getElementById( 'me-delete-close'  ).addEventListener( 'click', closeDeleteModal );
            document.getElementById( 'me-delete-cancel' ).addEventListener( 'click', closeDeleteModal );
            document.getElementById( 'me-delete-confirm').addEventListener( 'click', confirmDelete );
        }

        // Close on backdrop click
        document.getElementById( 'me-modal-overlay' ).addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) maybeCloseModal();
        } );
        document.getElementById( 'me-delete-overlay' ).addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closeDeleteModal();
        } );

        // Keyboard Escape
        document.addEventListener( 'keydown', ( e ) => {
            if ( e.key !== 'Escape' ) return;
            if ( ! document.getElementById( 'me-modal-overlay' ).classList.contains( 'me-hidden' ) ) {
                maybeCloseModal();
            } else {
                closeDeleteModal();
            }
        } );

        buildHeadAndFilterRow();
    }

    // ── Table head & per-column filter row ────────────────────────
    function buildHeadAndFilterRow() {
        const headRow   = document.getElementById( 'me-head-row' );
        const filterRow = document.getElementById( 'me-filter-row' );

        COLUMNS.forEach( ( col ) => {
            // ── Header cell
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

            // ── Filter cell (all columns get a filter input)
            const td    = document.createElement( 'td' );
            const input = document.createElement( 'input' );
            input.type         = 'text';
            input.classList.add( 'me-filter-input' );
            input.dataset.col  = col.key;
            input.placeholder  = col.key === 'member_number'
                ? `${ col.label } (exact)…`
                : `${ T.filterWord } ${ col.label.toLowerCase() }…`;
            input.autocomplete = 'off';
            input.addEventListener( 'input', renderRows );
            td.appendChild( input );
            filterRow.appendChild( td );
        } );

        // Actions column (no filter cell)
        headRow.appendChild( document.createElement( 'th' ) );
        filterRow.appendChild( document.createElement( 'td' ) );

        updateSortClasses();
    }

    function updateSortClasses() {
        document.querySelectorAll( '#me-head-row th[data-col]' ).forEach( ( th ) => {
            th.classList.remove( 'sort-asc', 'sort-desc' );
            if ( th.dataset.col === sortCol ) {
                th.classList.add( sortDir === 'asc' ? 'sort-asc' : 'sort-desc' );
            }
        } );
    }

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

    // ── Data loading ──────────────────────────────────────────────
    async function loadMembers( type ) {
        if ( type ) activeType = type;
        // Render type-filter pills into the placeholder injected by buildSubnav().
        const tabsEl = document.getElementById( 'me-type-tabs' );
        if ( tabsEl ) {
            const tabs = PCIO_ME.typeTabs || [];
            tabsEl.innerHTML = tabs.map( t =>
                '<button class="me-type-tab' + ( activeType === t.type ? ' me-type-tab-active' : '' ) +
                '" data-type="' + escAttr( t.type ) + '">' + escHtml( t.label ) + '</button>'
            ).join( '' );
        }
        try {
            const url = REST + ( activeType && activeType !== 'all' ? '?type=' + encodeURIComponent( activeType ) : '' );
            const res = await fetch( url, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            members = await res.json();
            updateCountBadge();
            renderRows();
        } catch ( err ) {
            const tbody = document.getElementById( 'me-tbody' );
            if ( tbody ) {
                tbody.innerHTML = `<tr><td colspan="${ COLUMNS.length + 1 }">
                    <div class="me-empty-state">
                        ${ ICON_EMPTY }
                        <p>${ escHtml( T.errorLoad ) }${ escHtml( err.message ) }</p>
                    </div></td></tr>`;
            }
        }
    }

    function updateCountBadge() {
        const el = document.getElementById( 'me-count' );
        if ( el ) {
            const word = members.length !== 1 ? T.memberPlural : T.memberSingular;
            el.textContent = `${ members.length } ${ word }`;
        }
    }

    // ── Row rendering ─────────────────────────────────────────────
    function renderRows() {
        const q = ( document.getElementById( 'me-global-search' )?.value || '' ).toLowerCase().trim();

        // Gather per-column filters
        const colFilters = {};
        document.querySelectorAll( '.me-filter-input' ).forEach( ( input ) => {
            const v = input.value.trim().toLowerCase();
            if ( v ) colFilters[ input.dataset.col ] = v;
        } );

        // Filter
        let rows = members.filter( ( m ) => {
            if ( q && ! Object.values( m ).some( ( v ) => String( v ?? '' ).toLowerCase().includes( q ) ) ) {
                return false;
            }
            for ( const [ col, val ] of Object.entries( colFilters ) ) {
                const cell = String( m[ col ] ?? '' ).toLowerCase();
                // Membership number is matched exactly so a short integer does not
                // also hit longer numbers that merely contain it.
                if ( col === 'member_number' ) {
                    if ( cell !== val ) return false;
                } else if ( ! cell.includes( val ) ) {
                    return false;
                }
            }
            return true;
        } );

        // Sort
        rows = rows.slice().sort( ( a, b ) => {
            const av = String( a[ sortCol ] ?? '' );
            const bv = String( b[ sortCol ] ?? '' );
            const cmp = av.localeCompare( bv, undefined, { numeric: true, sensitivity: 'base' } );
            return sortDir === 'asc' ? cmp : -cmp;
        } );

        const tbody = document.getElementById( 'me-tbody' );
        if ( ! tbody ) return;

        if ( rows.length === 0 ) {
            tbody.innerHTML = `<tr><td colspan="${ COLUMNS.length + 1 }">
                <div class="me-empty-state">
                    ${ ICON_EMPTY }
                    <p>${ members.length === 0
                        ? escHtml( T.emptyNone )
                        : escHtml( T.emptyFiltered ) }</p>
                </div></td></tr>`;
        } else {
            tbody.innerHTML = rows.map( buildRow ).join( '' );
            tbody.querySelectorAll( '.me-edit-btn' ).forEach( ( btn ) =>
                btn.addEventListener( 'click', () => openModal( parseInt( btn.dataset.id, 10 ) ) )
            );
            tbody.querySelectorAll( '.me-del-btn' ).forEach( ( btn ) =>
                btn.addEventListener( 'click', () => openDeleteModal( parseInt( btn.dataset.id, 10 ) ) )
            );
        }

        const vis = document.getElementById( 'me-visible-count' );
        visibleRows = rows;
        if ( vis ) {
            const word = T.memberPlural;
            vis.textContent = rows.length < members.length
                ? `${ T.showing } ${ rows.length } ${ T.of } ${ members.length } ${ word }`
                : `${ members.length } ${ members.length !== 1 ? T.memberPlural : T.memberSingular } ${ T.totalSuffix }`;
        }
    }

    function buildRow( m ) {
        const emailCell = m.email
            ? `<a href="mailto:${ escAttr( m.email ) }" title="${ escAttr( m.email ) }">${ escHtml( m.email ) }</a>`
            : '<span style="color:#cbd5e1">&mdash;</span>';

        const editBtn = CAN_EDIT
            ? `<button class="me-icon-btn edit me-edit-btn" data-id="${ m.id }"
                        title="${ escAttr( T.titleEdit ) } ${ escAttr( m.name ) }" aria-label="${ escAttr( T.titleEdit ) } ${ escAttr( m.name ) }">
                   ${ ICON_EDIT }
               </button>` : '';

        const delBtn = CAN_EDIT
            ? `<button class="me-icon-btn delete me-del-btn" data-id="${ m.id }"
                        title="${ escAttr( T.titleDelete ) } ${ escAttr( m.name ) }" aria-label="${ escAttr( T.titleDelete ) } ${ escAttr( m.name ) }">
                   ${ ICON_DEL }
               </button>` : '';

        return `<tr>
            <td class="me-cell-number">${ escHtml( m.member_number || '–' ) }</td>
            <td class="me-cell-name">${ escHtml( m.name ) }</td>
            <td class="me-cell-email">${ emailCell }</td>
            <td class="me-cell-phone">${ escHtml( m.phone || '–' ) }</td>
            ${ META_FIELDS.filter( f => f.list_col !== false ).map( f => `<td>${ escHtml( m[ f.key ] || '\u2013' ) }</td>` ).join( '' ) }
            <td class="me-cell-vis-role">
                ${ m.vis_role_name
                    ? `<span class="me-role-badge">${ escHtml( m.vis_role_name ) }</span>`
                    : '<span style="color:#94a3b8">–</span>' }
                ${ Number( m.is_volunteer ) ? `<span class="me-badge-system">${ escHtml( T.volunteerBadge || 'Volunteer' ) }</span>` : '' }
            </td>
            <td><div class="me-row-actions">${ editBtn }${ delBtn }</div></td>
        </tr>`;
    }

    // ── Meta field helpers ────────────────────────────────────────

    function renderMetaFields( values ) {
        const container = document.getElementById( 'me-meta-fields' );
        if ( ! container || META_FIELDS.length === 0 ) return;

        container.innerHTML = META_FIELDS.map( f => {
            const id  = `f-${ f.key }`;
            const val = escAttr( values[ f.key ] || '' );
            const input = f.type === 'textarea'
                ? `<textarea class="me-form-textarea" id="${ id }" name="${ f.key }">${ escHtml( values[ f.key ] || '' ) }</textarea>`
                : `<input class="me-form-input" type="${ escAttr( f.type ) }" id="${ id }" name="${ f.key }" value="${ val }">`;
            return `
            <div class="me-form-row full">
                <div class="me-form-group">
                    <label class="me-form-label" for="${ id }">${ escHtml( f.label ) }</label>
                    ${ input }
                </div>
            </div>`;
        } ).join( '' );
    }

    // ── Create / Edit modal ───────────────────────────────────────
    function openModal( id ) {
        editId        = id;
        dirtySnapshot = null;
        clearErrors();

        // Show modal immediately so elements exist before we populate them
        document.getElementById( 'me-modal-overlay' ).classList.remove( 'me-hidden' );

        const titleEl = document.getElementById( 'me-modal-title-text' );

        // Populate role dropdowns then set ALL field values in one go so the
        // dirty-snapshot is captured after every field — including selects — is set.
        ( ! CAN_EDIT
            ? Promise.resolve( [] )
            : ( visRoles ? Promise.resolve( visRoles ) : fetch( REST_ROLES, { headers: { 'X-WP-Nonce': NONCE } } ).then( r => { if ( ! r.ok ) throw new Error( `HTTP ${ r.status }` ); return r.json(); } ).then( d => { visRoles = d; return d; } ) )
        ).then( ( vr ) => {
            // Populate the vis-role select (only once — guard against duplicates on re-open)
            const vrSel = document.getElementById( 'f-vis-role' );
            if ( vrSel && vrSel.options.length === 1 ) {
                vr.forEach( r => {
                    const opt = document.createElement( 'option' );
                    opt.value = r.slug;  opt.textContent = r.name;
                    vrSel.appendChild( opt );
                } );
            }

            // Set all field values
            const m = id !== null ? members.find( x => +x.id === id ) : null;
            if ( id === null ) {
                titleEl.textContent = T.titleCreate;
                setField( 'member_number', '' );
                setField( 'member_number', '' );
                setField( 'name',          '' );
                setField( 'email',         '' );
                setField( 'phone',         '' );
                setField( 'address',       '' );
                renderMetaFields( {} );
            } else {
                titleEl.textContent = T.titleEdit;
                setField( 'member_number', m?.member_number ?? '' );
                setField( 'member_number', m?.member_number ?? '' );
                setField( 'name',          m?.name          ?? '' );
                setField( 'email',         m?.email         ?? '' );
                setField( 'phone',         m?.phone         ?? '' );
                setField( 'address',       m?.address       ?? '' );
                renderMetaFields( m || {} );
            }
            setSelect( 'f-vis-role', m?.vis_role ?? '' );
            const volEl = document.getElementById( 'f-is-volunteer' );
            if ( volEl ) volEl.checked = !! Number( m?.is_volunteer ?? 0 );

            // Capture baseline AFTER all values are set (used by isDirty)
            dirtySnapshot = captureFormSnapshot();

            document.getElementById( 'f-name' ).focus();
        } ).catch( err => {
            showToast( T.errRoleOptions + err.message, 'error' );
        } );
    }

    function closeModal() {
        document.getElementById( 'me-modal-overlay' )?.classList.add( 'me-hidden' );
        clearErrors();
        editId        = null;
        dirtySnapshot = null;
    }

    function maybeCloseModal() {
        if ( isDirty() && ! window.confirm( T.confirmDiscard ) ) return;
        closeModal();
    }

    async function submitForm() {
        clearErrors();

        const data = {
            member_number: getField( 'member_number' ),
            member_number: getField( 'member_number' ),
            name:          getField( 'name' ),
            email:         getField( 'email' ),
            phone:         getField( 'phone' ),
            address:       getField( 'address' ),
        };
        // Include meta fields
        META_FIELDS.forEach( f => { data[ f.key ] = getField( f.key ); } );
        // Volunteer flag (managers only)
        if ( CAN_EDIT ) {
            const volEl = document.getElementById( 'f-is-volunteer' );
            if ( volEl ) data.is_volunteer = volEl.checked ? 1 : 0;
        }

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

        const saveBtn = document.getElementById( 'me-btn-save' );
        saveBtn.disabled    = true;
        saveBtn.textContent = T.stateSaving;

        try {
            let res;
            if ( editId === null ) {
                res = await fetch( REST, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body:    JSON.stringify( data ),
                } );
            } else {
                res = await fetch( `${ REST }/${ editId }`, {
                    method:  'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body:    JSON.stringify( data ),
                } );
            }

            if ( ! res.ok ) {
                const err = await res.json().catch( () => ( {} ) );
                throw new Error( err.message || `HTTP ${ res.status }` );
            }

            const saved = await res.json();
            let finalMember = saved;

            // Role assignment is a manager / admin action only.
            if ( CAN_EDIT ) {
                const rolePayload = { vis_role: getSelect( 'f-vis-role' ) };
                const memberId = editId === null ? saved.id : editId;
                const roleRes = await fetch( `${ REST }/${ memberId }/role`, {
                    method:  'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body:    JSON.stringify( rolePayload ),
                } );
                if ( roleRes.ok ) {
                    finalMember = await roleRes.json();
                } else {
                    const roleErr = await roleRes.json().catch( () => ( {} ) );
                    showToast( T.errRoleSave + ( roleErr.message || `HTTP ${ roleRes.status }` ), 'error' );
                }
            }

            if ( editId === null ) {
                members.push( finalMember );
                showToast( T.toastCreated, 'success' );
            } else {
                const idx = members.findIndex( ( m ) => +m.id === editId );
                if ( idx !== -1 ) members[ idx ] = finalMember;
                showToast( T.toastUpdated, 'success' );
            }

            updateCountBadge();
            renderRows();
            closeModal();
        } catch ( err ) {
            showToast( T.errSave + err.message, 'error' );
        } finally {
            saveBtn.disabled    = false;
            saveBtn.textContent = T.btnSave;
        }
    }

    // ── Delete modal ──────────────────────────────────────────────
    function openDeleteModal( id ) {
        deleteId = id;
        const m = members.find( ( x ) => +x.id === id );
        const textEl = document.getElementById( 'me-delete-name-text' );
        if ( textEl ) {
            textEl.innerHTML = ( T.deletePrompt || 'Delete %s?' ).replace(
                '%s',
                `<strong>${ escHtml( m?.name || T.thisMember ) }</strong>`
            );
        }
        document.getElementById( 'me-delete-overlay' ).classList.remove( 'me-hidden' );
        document.getElementById( 'me-delete-confirm' ).focus();
    }

    function closeDeleteModal() {
        document.getElementById( 'me-delete-overlay' )?.classList.add( 'me-hidden' );
        deleteId = null;
    }

    async function confirmDelete() {
        if ( deleteId === null ) return;
        const btn = document.getElementById( 'me-delete-confirm' );
        btn.disabled    = true;
        btn.textContent = T.stateDeleting;

        try {
            const res = await fetch( `${ REST }/${ deleteId }`, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok && res.status !== 204 ) {
                throw new Error( `HTTP ${ res.status }` );
            }
            members = members.filter( ( m ) => +m.id !== deleteId );
            updateCountBadge();
            renderRows();
            showToast( T.toastDeleted, 'info' );
            closeDeleteModal();
        } catch ( err ) {
            showToast( T.errDelete + err.message, 'error' );
        } finally {
            btn.disabled    = false;
            btn.textContent = T.btnDelete;
        }
    }

    // ── Copy emails ───────────────────────────────────────────────
    function copyEmails() {
        const emails = visibleRows
            .map( ( m ) => ( m.email || '' ).trim() )
            .filter( Boolean );

        if ( ! emails.length ) {
            showToast( T.toastCopyNone, 'info' );
            return;
        }

        navigator.clipboard.writeText( emails.join( ';' ) )
            .then( () => showToast( emails.length + ' ' + T.toastCopied, 'success' ) )
            .catch( () => showToast( T.toastCopyFail, 'error' ) );
    }

    // ── Toast ─────────────────────────────────────────────────────
    function showToast( msg, type ) {
        const toast = document.getElementById( 'me-toast' );
        if ( ! toast ) return;
        toast.textContent = msg;
        toast.className   = `me-toast ${ type }`;
        if ( toastTimer ) clearTimeout( toastTimer );
        toastTimer = setTimeout( () => toast.classList.add( 'me-hidden' ), 3500 );
    }

    // ── Form helpers ──────────────────────────────────────────────
    const FORM = () => document.getElementById( 'me-form' );
    function captureFormSnapshot() {
        const form = FORM();
        if ( ! form ) return {};
        const snap = {};
        form.querySelectorAll( '[name]' ).forEach( el => {
            snap[ el.name ] = el.value;
        } );
        return snap;
    }

    function isDirty() {
        if ( ! dirtySnapshot ) return false;
        const form = FORM();
        if ( ! form ) return false;
        for ( const el of form.querySelectorAll( '[name]' ) ) {
            if ( ( dirtySnapshot[ el.name ] ?? '' ) !== el.value ) return true;
        }
        return false;
    }
    function setField( name, value ) {
        const el = FORM()?.querySelector( `[name="${ name }"]` );
        if ( el ) el.value = value;
    }

    function getField( name ) {
        return ( FORM()?.querySelector( `[name="${ name }"]` )?.value ?? '' ).trim();
    }

    function setSelect( id, value ) {
        const el = document.getElementById( id );
        if ( el ) el.value = value;
    }

    function getSelect( id ) {
        return document.getElementById( id )?.value ?? '';
    }

    function clearErrors() {
        document.querySelectorAll( '.me-field-error' ).forEach( ( el ) => { el.textContent = ''; } );
        document.querySelectorAll( '.me-form-input.is-invalid, .me-form-textarea.is-invalid' )
            .forEach( ( el ) => el.classList.remove( 'is-invalid' ) );
    }

    function setError( fieldName, msg ) {
        const errEl = document.getElementById( `err-${ fieldName }` );
        const input = document.getElementById( `f-${ fieldName }` );
        if ( errEl ) errEl.textContent = msg;
        if ( input ) { input.classList.add( 'is-invalid' ); input.focus(); }
    }

    // ── Security helpers ──────────────────────────────────────────
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
