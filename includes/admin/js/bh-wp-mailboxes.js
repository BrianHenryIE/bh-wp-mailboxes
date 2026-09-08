/**
 * The emails list screen: the accounts table (enable/disable, "Check now", "Check since…"), the
 * page-wide "Check all" button, and the "Delete on server" row action.
 *
 * Depends on account-modal.js, which owns the add/edit/delete dialogs and the shared helpers
 * (notices, AJAX posting, table refresh) exposed as `bhWpMailboxesAccountModal`.
 */
(function( $ ) {
    'use strict';

    var modal           = window.bhWpMailboxesAccountModal;
    var makeCheckNotice = modal.makeCheckNotice;
    var finishNotice    = modal.finishNotice;
    var showTableNotice = modal.showTableNotice;
    var accountRow      = modal.accountRow;
    var replaceTable    = modal.replaceTable;
    var postAccounts    = modal.postAccounts;
    var failMessage     = modal.failMessage;

    function handleCheckResponse( response, $row, $notice ) {
        var accountName = $row.data( 'account-name' );
        var prefix      = accountName ? accountName + ': ' : '';
        if ( response.success ) {
            var count = response.data.new_email_count;
            $row.find( '[data-field="last-fetched"]' ).text( response.data.last_fetched );
            if ( count > 0 ) {
                var $countEl = $row.find( '[data-field="email-count"]' );
                $countEl.text( parseInt( $countEl.text(), 10 ) + count );
                refreshTable( response.data.new_email_ids );
            }
            var msg = count > 0
                ? 'Email checked successfully, ' + count + ' new email' + ( count !== 1 ? 's' : '' ) + ' found.'
                : 'Email checked successfully, no new emails.';
            finishNotice( $notice, prefix + msg, count > 0 ? '#00a32a' : '#72aee6' );
        } else {
            var errMsg = ( response.data && response.data.message ) ? response.data.message : 'Check failed.';
            finishNotice( $notice, prefix + errMsg, '#d63638' );
        }
    }

    function refreshTable( newIds ) {
        $.get( location.href, function( html ) {
            var $newRows = $( html ).find( '#the-list' );
            if ( $newRows.length ) {
                $( '#the-list' ).replaceWith( $newRows );
                highlightNewRows( newIds );
            }
        } );
    }

    // Briefly highlight the freshly-fetched rows; the CSS animation fades the highlight out.
    function highlightNewRows( newIds ) {
        if ( ! newIds || ! newIds.length ) {
            return;
        }
        newIds.forEach( function( id ) {
            $( '#post-' + id ).addClass( 'bh-email-row--new' );
        } );
        setTimeout( function() {
            $( '.bh-email-row--new' ).removeClass( 'bh-email-row--new' );
        }, 3000 );
    }

    $( function() {

        $( document ).on( 'click', '.bh-account-toggle', function( event ) {
            event.preventDefault();
            var $btn         = $( this );
            var accountId    = $btn.data( 'account-id' );
            var active       = String( $btn.data( 'active' ) ) === '1';
            var emailAddress = accountRow( accountId ).data( 'email-address' );
            $btn.attr( 'aria-disabled', 'true' ).addClass( 'disabled' );

            postAccounts( bh_wp_mailboxes_ajax.set_account_active_action, { account_post_id: accountId, active: active ? '1' : '0' } ).done( function( response ) {
                replaceTable( response.data.table_html );
                showTableNotice( emailAddress + ( active ? ' enabled.' : ' disabled.' ), 'success' );
            } ).fail( function( xhr ) {
                $btn.removeAttr( 'aria-disabled' ).removeClass( 'disabled' );
                showTableNotice( failMessage( xhr, 'The account status could not be changed.' ), 'error' );
            } );
        } );

        // ── Move the check button into the page title, replacing "Add New Email" ─
        var $checkBtn = $( '#check-email' );
        if ( $checkBtn.length ) {
            $( 'a.page-title-action' ).remove();
            $checkBtn.insertAfter( $( 'h1.wp-heading-inline' ).first() ).show();
        }

        // ── Global check-all button ────────────────────────────────────────────
        $( '#check-email' ).on( 'click', function( event ) {
            event.preventDefault();
            var urlParams = new URLSearchParams( window.location.search );

            // Name the account(s) being checked, taken from the accounts table.
            var names = $( '.bh-mailboxes-account' ).map( function() {
                return $( this ).data( 'account-name' );
            } ).get().filter( Boolean );
            var label = names.length ? names.join( ', ' ) : 'all accounts';

            var $notice = makeCheckNotice( 'all', label );

            $.post( ajaxurl, {
                action:        bh_wp_mailboxes_ajax.check_email_action,
                mailboxes_cpt: urlParams.get( 'post_type' ),
                _wpnonce:      $( '#_wpnonce_checknow' ).val(),
            } ).done( function( response ) {
                var newEmails = ( response.data && response.data.new_emails ) || [];
                var count     = newEmails.length;
                var msg = count > 0
                    ? 'Email checked successfully, ' + count + ' new email' + ( count !== 1 ? 's' : '' ) + ' found.'
                    : 'Email checked successfully, no new emails.';
                finishNotice( $notice, label + ': ' + msg, count > 0 ? '#00a32a' : '#72aee6' );
                if ( count > 0 ) {
                    refreshTable( newEmails.map( function( email ) { return email.post_id; } ) );
                }
            } ).fail( function() {
                finishNotice( $notice, label + ': Check failed: server error.', '#d63638' );
            } );
        } );

        // ── Per-account: Check now ─────────────────────────────────────────────
        $( document ).on( 'click', '.bh-check-account', function( event ) {
            event.preventDefault();
            var $btn        = $( this );
            var accountId   = $btn.data( 'account-id' );
            var $row        = accountRow( accountId );
            var accountName = $row.data( 'account-name' );
            var origLabel   = $btn.text();
            $btn.attr( 'aria-disabled', 'true' ).addClass( 'disabled' ).text( 'Checking…' );

            var $notice = makeCheckNotice( accountId, accountName );

            postAccounts( bh_wp_mailboxes_ajax.check_account_action, { account_post_id: accountId } ).done( function( response ) {
                $btn.removeAttr( 'aria-disabled' ).removeClass( 'disabled' ).text( origLabel );
                handleCheckResponse( response, $row, $notice );
            } ).fail( function( xhr ) {
                $btn.removeAttr( 'aria-disabled' ).removeClass( 'disabled' ).text( origLabel );
                finishNotice( $notice, failMessage( xhr, 'Check failed: server error.' ), '#d63638' );
            } );
        } );

        // ── Row action: Delete on server (with confirmation) ───────────────────
        $( document ).on( 'click', '.bh-email-delete-on-server', function( event ) {
            event.preventDefault();
            var $link  = $( this );
            var postId = $link.data( 'post-id' );

            if ( ! window.confirm( 'Delete this email on the remote server? This cannot be undone.' ) ) {
                return;
            }

            var origLabel = $link.text();
            $link.text( 'Deleting…' );

            $.post( ajaxurl, {
                action:   bh_wp_mailboxes_ajax.delete_on_server_action,
                post_id:  postId,
                _wpnonce: bh_wp_mailboxes_ajax.remote_action_nonce,
            } ).done( function( response ) {
                if ( response.success ) {
                    // The email is now deleted on the server; reload the table so the row reflects it
                    // (and no longer offers "Delete on server").
                    refreshTable( [] );
                } else {
                    var msg = ( response.data && response.data.message ) ? response.data.message : 'Delete on server failed.';
                    window.alert( msg );
                    $link.text( origLabel );
                }
            } ).fail( function() {
                window.alert( 'Delete on server failed: server error.' );
                $link.text( origLabel );
            } );
        } );

        // ── Per-account: Since toggle ──────────────────────────────────────────
        $( document ).on( 'click', '.bh-fetch-since-toggle', function( event ) {
            event.preventDefault();
            var accountId = $( this ).data( 'account-id' );
            $( '.bh-fetch-since-input[data-account-id="' + accountId + '"]' ).toggle().focus();
        } );

        // ── Per-account: Since date change ─────────────────────────────────────
        $( document ).on( 'change', '.bh-fetch-since-input', function() {
            var $input      = $( this );
            var accountId   = $input.data( 'account-id' );
            var $row        = accountRow( accountId );
            var accountName = $row.data( 'account-name' );
            var sinceDate   = $input.val();

            if ( ! sinceDate ) {
                // Ignore an empty value, e.g. the spurious re-fire of `change` after we clear the input below.
                return;
            }

            $input.hide();
            // Clear the value so re-opening and picking the same date fires `change` again — a date
            // input does not emit `change` when re-committed with an unchanged value, which otherwise
            // limited this to one check per page load.
            $input.val( '' );

            var $notice = makeCheckNotice( accountId, accountName );

            postAccounts( bh_wp_mailboxes_ajax.check_account_action, { account_post_id: accountId, since_date: sinceDate } ).done( function( response ) {
                handleCheckResponse( response, $row, $notice );
            } ).fail( function( xhr ) {
                finishNotice( $notice, failMessage( xhr, 'Check failed: server error.' ), '#d63638' );
            } );
        } );

    } );

} )( jQuery );
