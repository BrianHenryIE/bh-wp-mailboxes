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
        if ( response.success ) {
            var count = response.data.new_email_count;
            $card.find( '[data-field="last-fetched"]' ).text( response.data.last_fetched );
            if ( count > 0 ) {
                var $countEl = $card.find( '[data-field="email-count"]' );
                $countEl.text( parseInt( $countEl.text(), 10 ) + count );
                refreshTable();
            }
            var msg = count > 0
                ? 'Email checked successfully, ' + count + ' new email' + ( count !== 1 ? 's' : '' ) + ' found.'
                : 'Email checked successfully, no new emails.';
            finishNotice( $notice, msg, count > 0 ? '#00a32a' : '#72aee6' );
        } else {
            var errMsg = ( response.data && response.data.message ) ? response.data.message : 'Check failed.';
            finishNotice( $notice, errMsg, '#d63638' );
        }
    }

    function refreshTable() {
        $.get( location.href, function( html ) {
            var $newRows = $( html ).find( '#the-list' );
            if ( $newRows.length ) {
                $( '#the-list' ).replaceWith( $newRows );
            }
        } );
    }

    $( function() {

        // ── Global check-all button ────────────────────────────────────────────
        $( '#check-email' ).on( 'click', function( event ) {
            event.preventDefault();
            var urlParams = new URLSearchParams( window.location.search );
            $.post( ajaxurl, {
                action:        'bh_wp_mailboxes_check_email',
                mailboxes_cpt: urlParams.get( 'post_type' ),
                _wpnonce:      $( '#_wpnonce_checknow' ).val(),
            }, function( response ) {
                console.log( response );
            } );
        } );

        // ── Per-account: Check now ─────────────────────────────────────────────
        $( document ).on( 'click', '.bh-check-account', function() {
            var $btn        = $( this );
            var accountId   = $btn.data( 'account-id' );
            var $card       = $( '.bh-mailboxes-account-card[data-account-id="' + accountId + '"]' );
            var accountName = $card.find( '.bh-mailboxes-account-card__title' ).text();
            var origLabel   = $btn.text();
            $btn.prop( 'disabled', true ).text( 'Checking…' );

            var $notice = makeCheckNotice( accountId, accountName );

            $.post( ajaxurl, {
                action:          'bh_wp_mailboxes_check_account',
                account_post_id: accountId,
                _wpnonce:        $( '#_wpnonce_account_actions' ).val(),
            } ).done( function( response ) {
                $btn.prop( 'disabled', false ).text( origLabel );
                handleCheckResponse( response, $card, $notice );
            } ).fail( function() {
                $btn.prop( 'disabled', false ).text( origLabel );
                finishNotice( $notice, 'Check failed: server error.', '#d63638' );
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
            var accountName = $card.find( '.bh-mailboxes-account-card__title' ).text();

            $input.hide();
            var $notice = makeCheckNotice( accountId, accountName );

            $.post( ajaxurl, {
                action:          'bh_wp_mailboxes_check_account',
                account_post_id: accountId,
                since_date:      $input.val(),
                _wpnonce:        $( '#_wpnonce_account_actions' ).val(),
            } ).done( function( response ) {
                handleCheckResponse( response, $card, $notice );
            } ).fail( function() {
                finishNotice( $notice, 'Check failed: server error.', '#d63638' );
            } );
        } );

    } );

} )( jQuery );
