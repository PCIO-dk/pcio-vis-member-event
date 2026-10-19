/* ================================================================
   PCIO VIS Member Event — history.js
   Shared "History" tab: change log for one event or member with Undo.

   Usage:
     window.PCIO_ME_History.render( container, {
         rest:     'https://…/wp-json/pcio-vis/v1',
         nonce:    '…',
         query:    { event_id: 12 }   // or { member_id: 34 }
         onUndone: () => {}           // optional, after a successful undo
     } );

   Strings come from window.PCIO_ME_HISTORY_I18N (added by PHP).
   ================================================================ */

( function () {
    'use strict';

    const PER_PAGE = 25;

    function t( key ) {
        const s = window.PCIO_ME_HISTORY_I18N || {};
        return s[ key ] || key;
    }

    function escHtml( s ) {
        return String( s ?? '' )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
    }

    function render( container, opts ) {
        const base  = String( opts.rest || '' ).replace( /\/+$/, '' );
        const state = { page: 1, items: [], total: 0, loading: false };

        container.innerHTML = `
        <div class="me-tab-card me-history">
            <div class="me-history-head">
                <h2 class="me-history-title">${ escHtml( t( 'title' ) ) }</h2>
                <button type="button" class="me-btn me-btn-secondary me-btn-sm" data-act="refresh">${ escHtml( t( 'refresh' ) ) }</button>
            </div>
            <p class="me-history-note">${ escHtml( t( 'note' ) ) }</p>
            <div class="me-history-msg me-hidden" role="alert"></div>
            <div class="me-history-list"><div class="me-loading"><div class="me-spinner"></div></div></div>
            <div class="me-history-more me-hidden">
                <button type="button" class="me-btn me-btn-secondary me-btn-sm" data-act="more">${ escHtml( t( 'loadMore' ) ) }</button>
            </div>
        </div>`;

        const listEl = container.querySelector( '.me-history-list' );
        const moreEl = container.querySelector( '.me-history-more' );
        const msgEl  = container.querySelector( '.me-history-msg' );

        function showMsg( text, type ) {
            msgEl.textContent = text;
            msgEl.className   = 'me-history-msg me-history-msg-' + ( type || 'error' );
        }
        function clearMsg() {
            msgEl.className = 'me-history-msg me-hidden';
        }

        async function load( reset ) {
            if ( state.loading ) return;
            state.loading = true;
            if ( reset ) {
                state.page  = 1;
                state.items = [];
            }
            const params = new URLSearchParams( Object.assign( {}, opts.query || {}, {
                page: state.page,
                per_page: PER_PAGE,
            } ) );
            try {
                const res = await fetch( `${ base }/history?${ params }`, { headers: { 'X-WP-Nonce': opts.nonce } } );
                if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
                const data  = await res.json();
                state.items = state.items.concat( data.items || [] );
                state.total = data.total || 0;
                paint();
            } catch ( err ) {
                listEl.innerHTML = `<p class="me-history-empty">${ escHtml( t( 'errLoad' ) ) } ${ escHtml( err.message ) }</p>`;
            } finally {
                state.loading = false;
            }
        }

        function statusCell( it ) {
            if ( it.undoneAt ) {
                return `<span class="me-history-state" title="${ escHtml( it.undoneAt + ' · ' + ( it.undoneBy || '' ) ) }">${ escHtml( t( 'undone' ) ) }</span>`;
            }
            if ( it.undoState === 'expired' ) {
                return `<span class="me-history-state">${ escHtml( t( 'expired' ) ) }</span>`;
            }
            if ( it.undoState === 'none' ) {
                return `<span class="me-history-state">${ escHtml( t( 'notUndoable' ) ) }</span>`;
            }
            if ( it.canUndo ) {
                return `<button type="button" class="me-btn me-btn-secondary me-btn-sm" data-act="undo" data-id="${ Number( it.id ) }">${ escHtml( t( 'undo' ) ) }</button>`;
            }
            return '';
        }

        function paint() {
            if ( ! state.items.length ) {
                listEl.innerHTML = `<p class="me-history-empty">${ escHtml( t( 'empty' ) ) }</p>`;
                moreEl.classList.add( 'me-hidden' );
                return;
            }
            const rows = state.items.map( it => `
                <tr class="${ it.undoneAt ? 'is-undone' : '' }${ it.undoOf ? ' is-undo' : '' }">
                    <td class="me-history-date">${ escHtml( it.createdHuman || it.createdAt ) }</td>
                    <td class="me-history-user">${ escHtml( it.userName ) }</td>
                    <td class="me-history-desc">${ escHtml( it.description ) }<span class="me-history-id">#${ Number( it.id ) }</span></td>
                    <td class="me-history-action">${ statusCell( it ) }</td>
                </tr>` ).join( '' );
            listEl.innerHTML = `
                <table class="me-history-table">
                    <thead><tr>
                        <th>${ escHtml( t( 'colDate' ) ) }</th>
                        <th>${ escHtml( t( 'colUser' ) ) }</th>
                        <th>${ escHtml( t( 'colChange' ) ) }</th>
                        <th></th>
                    </tr></thead>
                    <tbody>${ rows }</tbody>
                </table>`;
            moreEl.classList.toggle( 'me-hidden', state.items.length >= state.total );
        }

        async function undo( id, btn ) {
            if ( ! window.confirm( t( 'confirmUndo' ) ) ) return;
            clearMsg();
            btn.disabled = true;
            try {
                const res  = await fetch( `${ base }/history/${ id }/undo`, {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': opts.nonce },
                } );
                const data = await res.json().catch( () => ( {} ) );
                if ( ! res.ok ) {
                    showMsg( data.message || `HTTP ${ res.status }`, 'error' );
                    btn.disabled = false;
                    return;
                }
                if ( typeof opts.onUndone === 'function' ) {
                    opts.onUndone( data );
                    return;
                }
                showMsg( data.message || t( 'undoneOk' ), 'success' );
                load( true );
            } catch ( err ) {
                showMsg( err.message, 'error' );
                btn.disabled = false;
            }
        }

        const rootEl = container.querySelector( '.me-history' );
        rootEl.addEventListener( 'click', ( e ) => {
            const btn = e.target.closest( '[data-act]' );
            if ( ! btn || ! rootEl.contains( btn ) ) return;
            const act = btn.dataset.act;
            if ( act === 'refresh' ) {
                clearMsg();
                load( true );
            } else if ( act === 'more' ) {
                state.page++;
                load( false );
            } else if ( act === 'undo' ) {
                undo( parseInt( btn.dataset.id, 10 ), btn );
            }
        } );

        load( true );
    }

    window.PCIO_ME_History = { render };
} )();
