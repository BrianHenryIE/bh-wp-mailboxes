(function( $ ) {
    'use strict';

    function makeCheckNotice( accountId, accountName ) {
        $( '.bh-check-notice[data-account-id="' + accountId + '"]' ).remove();
        var $n = $( '<div class="notice bh-check-notice" data-account-id="' + accountId + '"><p></p>' +
            '<button type="button" class="notice-dismiss">' +
            '<span class="screen-reader-text">Dismiss this notice.</span></button></div>' );
        $n.css( 'border-left-color', '#8d96a0' );
        $n.find( 'p' )
            .append( $( '<span class="spinner is-active">' ) )
            .append( document.createTextNode( 'Checking email for ' ) )
            .append( $( '<strong>' ).text( accountName ) )
            .append( document.createTextNode( '…' ) );
        $n.on( 'click', '.notice-dismiss', function() {
            $n.fadeOut( 200, function() { $( this ).remove(); } );
        } );
        $( '.wp-header-end' ).after( $n );
        return $n;
    }

    function finishNotice( $notice, msg, borderColor ) {
        $notice.find( '.spinner' ).remove();
        $notice.find( 'p' ).text( msg );
        $notice.css( 'border-left-color', borderColor );
    }

    function handleCheckResponse( response, $card, $notice ) {
        var accountName = $card.data( 'account-name' );
        var prefix      = accountName ? accountName + ': ' : '';
        if ( response.success ) {
            var count = response.data.new_email_count;
            $card.find( '[data-field="last-fetched"]' ).text( response.data.last_fetched );
            if ( count > 0 ) {
                var $countEl = $card.find( '[data-field="email-count"]' );
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

            // Name the account(s) being checked, taken from the status cards.
            var names = $( '.bh-mailboxes-account-card' ).map( function() {
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
        $( document ).on( 'click', '.bh-check-account', function() {
            var $btn        = $( this );
            var accountId   = $btn.data( 'account-id' );
            var $card       = $( '.bh-mailboxes-account-card[data-account-id="' + accountId + '"]' );
            var accountName = $card.data( 'account-name' );
            var origLabel   = $btn.text();
            $btn.prop( 'disabled', true ).text( 'Checking…' );

            var $notice = makeCheckNotice( accountId, accountName );

            $.post( ajaxurl, {
                action:          bh_wp_mailboxes_ajax.check_account_action,
                account_post_id: accountId,
                _wpnonce:        $( '#_wpnonce_account_actions' ).val(),
            } ).done( function( response ) {
                $btn.prop( 'disabled', false ).text( origLabel );
                handleCheckResponse( response, $card, $notice );
            } ).fail( function() {
                $btn.prop( 'disabled', false ).text( origLabel );
                // TODO: message should come from the server. E.g. "could not find saved account".
                finishNotice( $notice, 'Check failed: server error.', '#d63638' );
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
        $( document ).on( 'click', '.bh-fetch-since-toggle', function() {
            var accountId = $( this ).data( 'account-id' );
            $( '.bh-fetch-since-input[data-account-id="' + accountId + '"]' ).toggle().focus();
        } );

        // ── Per-account: Since date change ─────────────────────────────────────
        $( document ).on( 'change', '.bh-fetch-since-input', function() {
            var $input      = $( this );
            var accountId   = $input.data( 'account-id' );
            var $card       = $( '.bh-mailboxes-account-card[data-account-id="' + accountId + '"]' );
            var accountName = $card.data( 'account-name' );
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

            $.post( ajaxurl, {
                action:          bh_wp_mailboxes_ajax.check_account_action,
                account_post_id: accountId,
                since_date:      sinceDate,
                _wpnonce:        $( '#_wpnonce_account_actions' ).val(),
            } ).done( function( response ) {
                handleCheckResponse( response, $card, $notice );
            } ).fail( function() {
                // TODO: message should come from the server. E.g. "could not find saved account". Unless, of course, it is a timeout.
                finishNotice( $notice, 'Check failed: server error.', '#d63638' );
            } );
        } );

    } );

} )( jQuery );
