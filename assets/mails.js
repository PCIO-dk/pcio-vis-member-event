/* ================================================================
   PCIO VIS Member Event — mails.js
   Mail list: REST-backed table with CRUD dialogs and Quill editor
   ================================================================ */

( function () {
    'use strict';

    // ── State ─────────────────────────────────────────────────────
    let mails      = [];
    let sortCol    = 'created_at';
    let sortDir    = 'desc';
    let editId     = null;
    let deleteId   = null;
    let quill      = null;
    let footerQuill = null;
    let toastTimer = null;

    const REST     = ( PCIO_ME.rest + '/mails' ).replace( /([^:]\/)\/+/g, '$1' );
    const NONCE    = PCIO_ME.nonce;
    const CAN_EDIT = PCIO_ME.canEdit;
    const T        = PCIO_ME.i18n;

    // Column definitions
    const COLUMNS = [
        { key: 'subject',    label: T.colSubject, sortable: true  },
        { key: 'created_at', label: T.colCreated, sortable: true  },
        { key: 'sent_at',    label: T.colStatus,  sortable: true  },
    ];

    const ICON_EDIT  = `<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
    const ICON_DEL   = `<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>`;
    const ICON_SEND  = `<svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>`;
    const ICON_EMPTY = `<svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>`;

    // ── Bootstrap ─────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', () => {
        buildShell();
        loadMails();
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
                <p>${ escHtml( T.noMailsYet ) }</p>
            </div>
        </div>

        <!-- Create / Edit modal -->
        <div class="me-overlay me-hidden" id="me-overlay" role="dialog" aria-modal="true"
             aria-labelledby="me-modal-title">
            <div class="me-modal me-modal-wide">
                <div class="me-modal-header">
                    <h2 class="me-modal-title" id="me-modal-title">${ escHtml( T.titleCreate ) }</h2>
                    <button class="me-modal-close" id="me-modal-close" aria-label="${ escAttr( T.closeLabel ) }">&times;</button>
                </div>
                <div class="me-modal-body">
                    <div class="me-form-group" style="margin-bottom:18px">
                        <label class="me-form-label" for="m-subject">
                            ${ escHtml( T.fieldSubject ) } <span class="req">*</span>
                        </label>
                        <input class="me-form-input" type="text" id="m-subject"
                               maxlength="255" placeholder="${ escAttr( T.phSubject ) }">
                        <span class="me-field-error" id="err-m-subject"></span>
                    </div>
                    <div class="me-form-group me-hidden" id="me-mail-tags" style="margin-bottom:14px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px"></div>
                    <div class="me-form-group">
                        <label class="me-form-label" style="margin-bottom:8px;display:block">
                            ${ escHtml( T.fieldContent ) }
                        </label>
                        <div id="m-quill-editor"></div>
                    </div>
                    <div class="me-form-group" style="margin-top:18px">
                        <label class="me-form-label" style="margin-bottom:8px;display:block">
                            ${ escHtml( T.attachmentsLabel ) }
                        </label>
                        <div id="me-attachment-chips" class="me-attachment-chips"></div>
                        <button type="button" class="me-btn me-btn-secondary" id="me-btn-add-attachment"
                                style="margin-top:6px">
                            ${ escHtml( T.btnAddAttachment ) }
                        </button>
                    </div>
                    <div class="me-form-group me-newsletter-block" style="margin-top:18px">
                        <label class="me-check-row">
                            <input type="checkbox" id="m-is-newsletter">
                            <span>${ escHtml( T.newsletterToggle ) }</span>
                        </label>
                        <p class="me-form-hint">${ escHtml( T.newsletterHint ) }</p>
                        <div id="me-newsletter-fields" class="me-hidden" style="margin-top:12px">
                            <label class="me-form-label" for="m-event-max-date">
                                ${ escHtml( T.newsletterMaxDate ) }
                            </label>
                            <input class="me-form-input" type="date" id="m-event-max-date"
                                   style="max-width:220px">
                            <p class="me-form-hint">${ escHtml( T.newsletterMaxDateHint ) }</p>

                            <label class="me-form-label" style="margin:14px 0 8px;display:block">
                                ${ escHtml( T.newsletterFooter ) }
                            </label>
                            <div id="m-footer-editor"></div>

                            <div style="margin-top:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                                <button type="button" class="me-btn me-btn-secondary" id="me-modal-generate">
                                    ${ escHtml( T.newsletterGenerate ) }
                                </button>
                                <span id="me-nl-result" class="me-nl-result"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="me-modal-footer">
                    <button class="me-btn me-btn-secondary" id="me-modal-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-send me-hidden" id="me-modal-send-btn">${ escHtml( T.btnSendToAll ) }</button>
                    <button class="me-btn me-btn-primary"   id="me-modal-save">${ escHtml( T.btnSaveDraft ) }</button>
                </div>
            </div>
        </div>

        <!-- Send confirm modal -->
        <div class="me-overlay me-hidden" id="me-send-overlay"
             role="alertdialog" aria-modal="true">
            <div class="me-modal me-modal-sm">
                <div class="me-modal-header">
                    <h2 class="me-modal-title">${ escHtml( T.titleSendMail ) }</h2>
                    <button class="me-modal-close" id="me-send-close" aria-label="${ escAttr( T.closeLabel ) }">&times;</button>
                </div>
                <div class="me-delete-body">
                    <div class="me-delete-icon me-send-icon">
                        ${ ICON_SEND }
                    </div>
                    <p class="me-delete-name" id="me-send-name"></p>

                    <div class="me-form-group" style="margin:12px 0 4px;text-align:left">
                        <label class="me-form-label" for="me-send-target">${ escHtml( T.sendTargetLabel ) }</label>
                        <select class="me-form-input" id="me-send-target">
                            <option value="all">${ escHtml( T.sendTargetAll ) }</option>
                            <option value="addresses">${ escHtml( T.sendTargetAddresses ) }</option>
                        </select>
                    </div>

                    <div id="me-send-groups-row" class="me-form-group me-hidden"
                         style="margin:8px 0 4px;text-align:left">
                        <label class="me-form-label" for="me-send-group">${ escHtml( T.sendGroupLabel ) }</label>
                        <select class="me-form-input" id="me-send-group"></select>
                    </div>

                    <div id="me-send-addresses-row" class="me-form-group me-hidden"
                         style="margin:8px 0 4px;text-align:left">
                        <label class="me-form-label" for="me-send-addresses">${ escHtml( T.sendAddressesLabel ) }</label>
                        <textarea class="me-form-textarea" id="me-send-addresses" rows="3"
                                  placeholder="${ escAttr( T.sendAddressesPh ) }"></textarea>
                        <span class="me-field-error" id="err-me-send-addresses"></span>
                    </div>

                    <p class="me-delete-warning" id="me-send-info"></p>
                </div>
                <div class="me-delete-footer">
                    <button class="me-btn me-btn-secondary" id="me-send-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-primary"   id="me-send-confirm">${ escHtml( T.btnSendNow ) }</button>
                </div>
            </div>
        </div>

        <!-- Delete confirm modal -->
        <div class="me-overlay me-hidden" id="me-del-overlay"
             role="alertdialog" aria-modal="true">
            <div class="me-modal me-modal-sm">
                <div class="me-modal-header">
                    <h2 class="me-modal-title">${ escHtml( T.titleDelete ) }</h2>
                    <button class="me-modal-close" id="me-del-close" aria-label="${ escAttr( T.closeLabel ) }">&times;</button>
                </div>
                <div class="me-delete-body">
                    <div class="me-delete-icon">
                        ${ ICON_DEL }
                    </div>
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

        // Wire up events
        if ( CAN_EDIT ) {
            document.getElementById( 'me-btn-new' )
                .addEventListener( 'click', () => openModal( null ) );
        }
        document.getElementById( 'me-modal-close'    ).addEventListener( 'click', closeModal );
        document.getElementById( 'me-modal-cancel'   ).addEventListener( 'click', closeModal );
        document.getElementById( 'me-modal-save'     ).addEventListener( 'click', saveMail );
        document.getElementById( 'me-modal-send-btn' ).addEventListener( 'click', () => {
            // Capture editId and current attachments BEFORE closeModal() nulls them.
            const id          = editId;
            const attachments = mailAttachments.slice();
            closeModal();
            openSendModal( id, attachments );
        } );
        document.getElementById( 'me-btn-add-attachment' ).addEventListener( 'click', openMediaPicker );
        document.getElementById( 'm-is-newsletter' ).addEventListener( 'change', onNewsletterToggle );
        document.getElementById( 'me-modal-generate' ).addEventListener( 'click', generateNewsletter );
        document.getElementById( 'me-send-close'   ).addEventListener( 'click', closeSendModal );
        document.getElementById( 'me-send-cancel'  ).addEventListener( 'click', closeSendModal );
        document.getElementById( 'me-send-confirm' ).addEventListener( 'click', confirmSend );
        document.getElementById( 'me-send-target'  ).addEventListener( 'change', ( e ) => {
            toggleSendRows( e.target.value );
        } );
        document.getElementById( 'me-del-close'    ).addEventListener( 'click', closeDeleteModal );
        document.getElementById( 'me-del-cancel'   ).addEventListener( 'click', closeDeleteModal );
        document.getElementById( 'me-del-confirm'  ).addEventListener( 'click', confirmDelete );

        document.getElementById( 'me-overlay' ).addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closeModal();
        } );
        document.getElementById( 'me-send-overlay' ).addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closeSendModal();
        } );
        document.getElementById( 'me-del-overlay' ).addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closeDeleteModal();
        } );
        document.addEventListener( 'keydown', ( e ) => {
            if ( e.key === 'Escape' ) { closeModal(); closeSendModal(); closeDeleteModal(); }
        } );

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
            const input = document.createElement( 'input' );
            input.type         = 'text';
            input.classList.add( 'me-filter-input' );
            input.dataset.col  = col.key;
            input.placeholder  = `${ T.filterWord } ${ col.label.toLowerCase() }\u2026`;
            input.autocomplete = 'off';
            input.addEventListener( 'input', renderTable );
            td.appendChild( input );
            filterRow.appendChild( td );
        } );

        // Actions column header
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
        sortDir = sortCol === col ? ( sortDir === 'asc' ? 'desc' : 'asc' ) : 'asc';
        sortCol = col;
        updateSortClasses();
        renderTable();
    }

    // ── Load ──────────────────────────────────────────────────────
    async function loadMails() {
        try {
            const res = await fetch( REST, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            mails = await res.json();
            renderTable();
        } catch ( err ) {
            showToast( T.errorLoad + err.message, 'error' );
        }
    }

    // ── Render table ──────────────────────────────────────────────
    function renderTable() {
        const tbody = document.getElementById( 'me-tbody' );
        const empty = document.getElementById( 'me-empty' );
        const count = document.getElementById( 'me-count' );
        const vis   = document.getElementById( 'me-visible-count' );
        if ( ! tbody ) return;

        // Gather per-column filters
        const colFilters = {};
        document.querySelectorAll( '.me-filter-input' ).forEach( input => {
            const v = input.value.trim().toLowerCase();
            if ( v ) colFilters[ input.dataset.col ] = v;
        } );

        let filtered = mails.filter( m => {
            for ( const [ col, val ] of Object.entries( colFilters ) ) {
                const cell = col === 'sent_at'
                    ? ( m.sent_at ? 'sent ' + fmtDate( m.sent_at ) : 'draft' )
                    : String( m[ col ] ?? '' );
                if ( ! cell.toLowerCase().includes( val ) ) return false;
            }
            return true;
        } );

        // Sort
        filtered = filtered.slice().sort( ( a, b ) => {
            const av = String( a[ sortCol ] ?? '' );
            const bv = String( b[ sortCol ] ?? '' );
            const cmp = av.localeCompare( bv, undefined, { numeric: true, sensitivity: 'base' } );
            return sortDir === 'asc' ? cmp : -cmp;
        } );

        if ( count ) {
            const word = mails.length !== 1 ? T.mailPlural : T.mailSingular;
            count.textContent = `${ mails.length } ${ word }`;
        }
        if ( vis ) {
            vis.textContent = filtered.length < mails.length
                ? `${ T.showing } ${ filtered.length } ${ T.of } ${ mails.length } ${ T.mailPlural }`
                : `${ mails.length } ${ mails.length !== 1 ? T.mailPlural : T.mailSingular } ${ T.totalSuffix }`;
        }

        if ( filtered.length === 0 ) {
            tbody.innerHTML = '';
            empty.classList.remove( 'me-hidden' );
            return;
        }
        empty.classList.add( 'me-hidden' );

        tbody.innerHTML = filtered.map( m => {
            const sentBadge = m.sent_at
                ? `<span class="me-badge me-badge-sent">${ escHtml( T.statusSentPfx ) } ${ fmtDate( m.sent_at ) }</span>`
                : `<span class="me-badge me-badge-draft">${ escHtml( T.statusDraft ) }</span>`;

            const actions = CAN_EDIT ? `
                <td class="me-col-actions">
                    <button class="me-icon-btn" data-action="edit"   data-id="${ m.id }" title="${ escAttr( T.editTitle ) }">${ ICON_EDIT }</button>
                    <button class="me-icon-btn me-icon-btn-send" data-action="send" data-id="${ m.id }" title="${ escAttr( T.sendTitle ) }">${ ICON_SEND }</button>
                    ${ m.mailtype ? '' : `<button class="me-icon-btn me-icon-btn-danger" data-action="delete" data-id="${ m.id }" title="${ escAttr( T.deleteTitle ) }">${ ICON_DEL }</button>` }
                </td>` : '';

            return `<tr>
                <td class="me-cell-subject">${ escHtml( m.subject ) }${ Number( m.is_newsletter ) ? `<span class="me-badge-newsletter">${ escHtml( T.newsletterBadge || 'Newsletter' ) }</span>` : '' }${ m.mailtype ? `<span class="me-badge-system">${ escHtml( T.systemBadge || 'System' ) }</span>` : '' }</td>
                <td class="me-col-date">${ fmtDate( m.created_at ) }</td>
                <td class="me-col-date">${ sentBadge }</td>
                ${ actions }
            </tr>`;
        } ).join( '' );

        if ( CAN_EDIT ) {
            tbody.querySelectorAll( '[data-action]' ).forEach( btn => {
                btn.addEventListener( 'click', () => {
                    const id = parseInt( btn.dataset.id, 10 );
                    if ( btn.dataset.action === 'edit'   ) openModal( id );
                    if ( btn.dataset.action === 'send'   ) openSendModal( id );
                    if ( btn.dataset.action === 'delete' ) openDeleteModal( id );
                } );
            } );
        }
    }

    // ── Modal ─────────────────────────────────────────────────────
    async function openModal( id ) {
        editId = id;
        mailAttachments = [];
        document.getElementById( 'me-modal-title' ).textContent = id ? T.titleEdit : T.titleCreate;
        document.getElementById( 'm-subject'       ).value       = '';
        document.getElementById( 'err-m-subject'   ).textContent = '';
        document.getElementById( 'm-is-newsletter' ).checked     = false;
        document.getElementById( 'm-event-max-date' ).value      = '';
        document.getElementById( 'me-nl-result'    ).textContent = '';

        // Init Quill once, then reuse
        if ( ! quill ) {
            quill = new Quill( '#m-quill-editor', {
                theme:   'snow',
                modules: {
                    toolbar: [
                        [ 'bold', 'italic', 'underline' ],
                        [ { list: 'ordered' }, { list: 'bullet' } ],
                        [ 'link' ],
                        [ { color: [] } ],
                        [ 'clean' ],
                    ],
                },
                placeholder: T.phQuill,
            } );
        }
        quill.setContents( [] );

        // Footer editor (newsletter only) — init once, reuse.
        if ( ! footerQuill ) {
            footerQuill = new Quill( '#m-footer-editor', {
                theme:   'snow',
                modules: {
                    toolbar: [
                        [ 'bold', 'italic', 'underline' ],
                        [ { list: 'ordered' }, { list: 'bullet' } ],
                        [ 'link' ],
                        [ 'clean' ],
                    ],
                },
                placeholder: T.newsletterFooterPh,
            } );
        }
        footerQuill.setContents( [] );

        if ( id ) {
            try {
                const res = await fetch( `${ REST }/${ id }`, { headers: { 'X-WP-Nonce': NONCE } } );
                if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
                const mail = await res.json();
                document.getElementById( 'm-subject' ).value = mail.subject;
                if ( mail.content ) {
                    quill.clipboard.dangerouslyPasteHTML( mail.content );
                    quill.history.clear();
                }
                document.getElementById( 'm-is-newsletter' ).checked = !! Number( mail.is_newsletter );
                document.getElementById( 'm-event-max-date' ).value = mail.event_max_date || '';
                if ( mail.footer ) {
                    footerQuill.clipboard.dangerouslyPasteHTML( mail.footer );
                    footerQuill.history.clear();
                }
                // Populate saved attachments
                mailAttachments = ( mail.attachments || [] ).map( att => ( {
                    id:   att.id,
                    name: att.name || String( att.id ),
                } ) );
                // Show available tag chips for system mail templates.
                renderMailTags( mail.available_tags || [] );
            } catch ( err ) {
                showToast( T.errLoadMail + err.message, 'error' );
                return;
            }
        } else {
            renderMailTags( [] );
        }

        document.getElementById( 'me-overlay' ).classList.remove( 'me-hidden' );
        document.getElementById( 'm-subject' ).focus();
        renderAttachmentChips();
        onNewsletterToggle();
        // Show Send button for any existing mail
        document.getElementById( 'me-modal-send-btn' )
            .classList.toggle( 'me-hidden', ! id );
    }

    function closeModal() {
        document.getElementById( 'me-overlay' ).classList.add( 'me-hidden' );
        editId = null;
    }

    function renderMailTags( tags ) {
        const wrap = document.getElementById( 'me-mail-tags' );
        if ( ! wrap ) return;
        if ( ! tags || tags.length === 0 ) {
            wrap.classList.add( 'me-hidden' );
            return;
        }
        wrap.classList.remove( 'me-hidden' );
        const chips = tags.map( t =>
            `<code class="me-tag-chip" title="${ escAttr( t.label ) }">${ escHtml( t.tag ) }</code>`
        ).join( ' ' );
        wrap.innerHTML = `<p class="me-form-label" style="margin-bottom:4px">${ escHtml( T.availableTagsLabel || 'Available tags' ) }</p>${ chips }`;
    }

    async function saveMail() {
        const subject = document.getElementById( 'm-subject' ).value.trim();
        const errEl   = document.getElementById( 'err-m-subject' );
        if ( ! subject ) {
            errEl.textContent = T.subjectReq;
            document.getElementById( 'm-subject' ).focus();
            return;
        }
        errEl.textContent = '';

        const raw     = quill ? quill.root.innerHTML : '';
        const content = raw === '<p><br></p>' ? '' : raw;

        const isNewsletter = document.getElementById( 'm-is-newsletter' ).checked;
        const maxDate      = document.getElementById( 'm-event-max-date' ).value || '';
        const footerRaw    = footerQuill ? footerQuill.root.innerHTML : '';
        const footer       = footerRaw === '<p><br></p>' ? '' : footerRaw;

        const btn = document.getElementById( 'me-modal-save' );
        btn.disabled    = true;
        btn.textContent = T.stateSaving;

        try {
            const method = editId ? 'PUT' : 'POST';
            const url    = editId ? `${ REST }/${ editId }` : REST;
            const res    = await fetch( url, {
                method,
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( {
                    subject,
                    content,
                    attachments:    mailAttachments.map( a => a.id ),
                    is_newsletter:  isNewsletter ? 1 : 0,
                    footer,
                    event_max_date: maxDate,
                } ),
            } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            const saved = await res.json();

            if ( editId ) {
                const idx = mails.findIndex( m => m.id === editId );
                if ( idx !== -1 ) mails[ idx ] = { ...mails[ idx ], subject: saved.subject, is_newsletter: saved.is_newsletter };
            } else {
                mails.unshift( {
                    id:            saved.id,
                    subject:       saved.subject,
                    is_newsletter: saved.is_newsletter,
                    created_at:    saved.created_at,
                    sent_at:       saved.sent_at,
                } );
            }

            renderTable();
            closeModal();
            showToast( editId ? T.toastUpdated : T.toastCreated, 'success' );
        } catch ( err ) {
            showToast( T.errSave + err.message, 'error' );
        } finally {
            btn.disabled    = false;
            btn.textContent = T.btnSaveDraft;
        }
    }

    // ── Newsletter ────────────────────────────────────────────────
    function onNewsletterToggle() {
        const on    = document.getElementById( 'm-is-newsletter' ).checked;
        const panel = document.getElementById( 'me-newsletter-fields' );
        panel.classList.toggle( 'me-hidden', ! on );
        // Default the cut-off date to the end of next month when first enabled.
        const dateEl = document.getElementById( 'm-event-max-date' );
        if ( on && ! dateEl.value ) {
            dateEl.value = defaultMaxDate();
        }
    }

    // End of next month, YYYY-MM-DD.
    function defaultMaxDate() {
        const now  = new Date();
        const last = new Date( now.getFullYear(), now.getMonth() + 2, 0 );
        const pad  = n => String( n ).padStart( 2, '0' );
        return `${ last.getFullYear() }-${ pad( last.getMonth() + 1 ) }-${ pad( last.getDate() ) }`;
    }

    // Persist the current modal fields, returning the saved mail (no UI close).
    async function persistCurrentMail() {
        const subject = document.getElementById( 'm-subject' ).value.trim();
        const raw     = quill ? quill.root.innerHTML : '';
        const content = raw === '<p><br></p>' ? '' : raw;
        const footerRaw = footerQuill ? footerQuill.root.innerHTML : '';
        const footer    = footerRaw === '<p><br></p>' ? '' : footerRaw;

        const method = editId ? 'PUT' : 'POST';
        const url    = editId ? `${ REST }/${ editId }` : REST;
        const res    = await fetch( url, {
            method,
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
            body:    JSON.stringify( {
                subject,
                content,
                attachments:    mailAttachments.map( a => a.id ),
                is_newsletter:  document.getElementById( 'm-is-newsletter' ).checked ? 1 : 0,
                footer,
                event_max_date: document.getElementById( 'm-event-max-date' ).value || '',
            } ),
        } );
        if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
        const saved = await res.json();
        editId = saved.id;
        // Keep the list in sync.
        const idx = mails.findIndex( m => m.id === saved.id );
        if ( idx !== -1 ) {
            mails[ idx ] = { ...mails[ idx ], subject: saved.subject, is_newsletter: saved.is_newsletter };
        } else {
            mails.unshift( {
                id: saved.id, subject: saved.subject, is_newsletter: saved.is_newsletter,
                created_at: saved.created_at, sent_at: saved.sent_at,
            } );
        }
        return saved;
    }

    async function generateNewsletter() {
        const subject = document.getElementById( 'm-subject' ).value.trim();
        if ( ! subject ) {
            document.getElementById( 'err-m-subject' ).textContent = T.subjectReq;
            document.getElementById( 'm-subject' ).focus();
            return;
        }
        const btn    = document.getElementById( 'me-modal-generate' );
        const result = document.getElementById( 'me-nl-result' );
        btn.disabled    = true;
        btn.textContent = T.newsletterGenerating;
        result.textContent = '';

        try {
            await persistCurrentMail();
            const res = await fetch( `${ REST }/${ editId }/newsletter`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
            } );
            const data = await res.json();
            if ( ! res.ok ) throw new Error( data && data.message ? data.message : `HTTP ${ res.status }` );

            const link = data.url
                ? `<a href="${ escAttr( data.url ) }" target="_blank" rel="noopener">${ escHtml( T.newsletterOpen ) }</a>`
                : '';
            result.innerHTML = `<span class="me-nl-ok">${ escHtml( T.newsletterDone ) } (${ data.event_count } ${ escHtml( T.newsletterEvents ) })</span> ${ link }`;
            renderTable();
            showToast( T.newsletterDone, 'success' );
        } catch ( err ) {
            result.innerHTML = `<span class="me-nl-err">${ escHtml( err.message ) }</span>`;
            showToast( T.newsletterFailed + err.message, 'error' );
        } finally {
            btn.disabled    = false;
            btn.textContent = T.newsletterGenerate;
        }
    }
    // ── Send modal ────────────────────────────────────────────────
    let sendId = null;

    // ── Attachment handling ───────────────────────────────────────
    // Each entry: { id: int, name: string }
    let mailAttachments = [];
    let mediaFrame      = null;

    function openMediaPicker() {
        if ( typeof wp === 'undefined' || ! wp.media ) {
            showToast( T.noMediaLib, 'error' );
            return;
        }
        if ( ! mediaFrame ) {
            mediaFrame = wp.media( {
                title:    T.mediaTitle,
                button:   { text: T.mediaBtn },
                multiple: true,
            } );
            mediaFrame.on( 'select', () => {
                mediaFrame.state().get( 'selection' ).each( att => {
                    const a = att.toJSON();
                    if ( ! mailAttachments.find( x => x.id === a.id ) ) {
                        mailAttachments.push( { id: a.id, name: a.filename || a.title || String( a.id ) } );
                    }
                } );
                renderAttachmentChips();
            } );
        }
        mediaFrame.open();
    }

    function renderAttachmentChips() {
        const container = document.getElementById( 'me-attachment-chips' );
        if ( ! container ) return;
        container.innerHTML = mailAttachments.map( ( att, idx ) =>
            `<span class="me-attachment-chip">
                ${ escHtml( att.name ) }
                <button type="button" class="me-chip-remove" data-idx="${ idx }"
                        aria-label="${ escAttr( T.removeAttachment ) }">&times;</button>
            </span>`
        ).join( '' );
        container.querySelectorAll( '.me-chip-remove' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                mailAttachments.splice( parseInt( btn.dataset.idx, 10 ), 1 );
                renderAttachmentChips();
            } );
        } );
    }

    /**
     * Open the send-confirm modal.
     *
     * @param {number}     id                  Mail ID.
     * @param {Array|null} attachmentsSnapshot  Pre-resolved list from the compose modal
     *                                         (may contain unsaved changes). null = table-row
     *                                         path, so we fetch from REST instead.
     */
    async function openSendModal( id, attachmentsSnapshot = null ) {
        sendId = id;
        const mail = mails.find( m => m.id === id );
        document.getElementById( 'me-send-name' ).innerHTML =
            `<strong>${ escHtml( mail?.subject || '' ) }</strong>`;

        // Load attachments:
        // – Compose-modal path: snapshot already has the latest in-memory state (may be unsaved).
        // – Table-row path: fetch the saved mail to get its stored attachments.
        if ( attachmentsSnapshot !== null ) {
            mailAttachments = attachmentsSnapshot;
        } else {
            try {
                const r = await fetch( `${ REST }/${ id }`, { headers: { 'X-WP-Nonce': NONCE } } );
                if ( r.ok ) {
                    const saved = await r.json();
                    mailAttachments = ( saved.attachments || [] ).map( att => ( {
                        id:   att.id,
                        name: att.name || String( att.id ),
                    } ) );
                }
            } catch ( _e ) { /* non-fatal */ }
        }

        // Reset target selector to 'all'
        const targetSel = document.getElementById( 'me-send-target' );
        targetSel.value = 'all';
        toggleSendRows( 'all' );

        // Populate the groups dropdown from the REST endpoint (optional — may return empty)
        const groupSel = document.getElementById( 'me-send-group' );
        groupSel.innerHTML = '';
        fetch( ( PCIO_ME.rest + '/mail-groups' ).replace( /([^:]\/)\/+/g, '$1' ),
               { headers: { 'X-WP-Nonce': NONCE } } )
            .then( r => r.ok ? r.json() : [] )
            .then( groups => {
                if ( groups.length ) {
                    groups.forEach( g => {
                        const o = document.createElement( 'option' );
                        o.value       = g.slug;
                        o.textContent = g.label + ( g.count != null ? ` (${ g.count })` : '' );
                        groupSel.appendChild( o );
                    } );
                    // Add the 'group' option to the target selector if not already present
                    if ( ! targetSel.querySelector( 'option[value="group"]' ) ) {
                        const o = document.createElement( 'option' );
                        o.value       = 'group';
                        o.textContent = T.sendTargetGroup;
                        targetSel.appendChild( o );
                    }
                } else {
                    // Remove 'group' option if no groups available
                    const existing = targetSel.querySelector( 'option[value="group"]' );
                    if ( existing ) existing.remove();
                }
            } )
            .catch( () => {} );

        document.getElementById( 'me-send-addresses' ).value = '';
        document.getElementById( 'err-me-send-addresses' ).textContent = '';
        document.getElementById( 'me-send-info' ).textContent = T.sendInfo;
        document.getElementById( 'me-send-overlay' ).classList.remove( 'me-hidden' );
    }

    function toggleSendRows( target ) {
        document.getElementById( 'me-send-addresses-row' )
            .classList.toggle( 'me-hidden', target !== 'addresses' );
        document.getElementById( 'me-send-groups-row' )
            .classList.toggle( 'me-hidden', target !== 'group' );
    }

    function closeSendModal() {
        document.getElementById( 'me-send-overlay' ).classList.add( 'me-hidden' );
        sendId = null;
    }

    async function confirmSend() {
        if ( ! sendId ) return;

        const target    = document.getElementById( 'me-send-target' ).value;
        const addrRaw   = document.getElementById( 'me-send-addresses' ).value.trim();
        const groupSlug = document.getElementById( 'me-send-group' )?.value || '';
        const errEl     = document.getElementById( 'err-me-send-addresses' );

        // Validate manual addresses
        if ( target === 'addresses' ) {
            if ( ! addrRaw ) {
                errEl.textContent = T.sendAddressesRequired;
                document.getElementById( 'me-send-addresses' ).focus();
                return;
            }
            // Rough email validation for each entry
            const addrs = addrRaw.split( ';' ).map( s => s.trim() ).filter( Boolean );
            const invalid = addrs.filter( a => ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( a ) );
            if ( invalid.length ) {
                errEl.textContent = T.sendAddressesInvalid.replace( '%s', invalid.join( ', ' ) );
                return;
            }
            errEl.textContent = '';
        }

        const btn = document.getElementById( 'me-send-confirm' );
        btn.disabled    = true;
        btn.textContent = T.stateSending;

        try {
            const body = { target, attachments: mailAttachments.map( a => a.id ) };
            if ( target === 'addresses' ) {
                body.addresses = addrRaw.split( ';' ).map( s => s.trim() ).filter( Boolean );
            } else if ( target === 'group' ) {
                body.group = groupSlug;
            }

            const res = await fetch( `${ REST }/${ sendId }/send`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( body ),
            } );
            const data = await res.json();
            if ( ! res.ok ) throw new Error( data.message || `HTTP ${ res.status }` );

            // Update local state
            const idx = mails.findIndex( m => m.id === sendId );
            if ( idx !== -1 ) mails[ idx ] = { ...mails[ idx ], sent_at: data.mail.sent_at };

            renderTable();
            closeSendModal();
            const sentMsg = T.sentToFmt.replace( '%d', data.sent )
                + ( data.failed > 0 ? T.sentFailedFmt.replace( '%d', data.failed ) : '' ) + '.';
            showToast( sentMsg, data.failed > 0 ? 'error' : 'success' );
        } catch ( err ) {
            showToast( T.errSend + err.message, 'error' );
        } finally {
            btn.disabled    = false;
            btn.textContent = T.btnSendNow;
        }
    }
    // ── Delete modal ──────────────────────────────────────────────
    function openDeleteModal( id ) {
        const mail = mails.find( m => m.id === id );
        if ( mail && mail.mailtype ) {
            showToast( T.systemMailNoDelete || 'System mails cannot be deleted.', 'error' );
            return;
        }
        deleteId = id;
        document.getElementById( 'me-del-name' ).innerHTML =
            `Delete <strong>${ escHtml( mail?.subject || '' ) }</strong>?`;
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
                method:  'DELETE',
                headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok && res.status !== 204 ) throw new Error( `HTTP ${ res.status }` );
            mails = mails.filter( m => m.id !== deleteId );
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
        if ( ! iso ) return '—';
        const d = new Date( iso.replace( ' ', 'T' ) );
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

    function escAttr( str ) {
        return String( str ?? '' )
            .replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' );
    }

} )();
