/**
 * The add/edit IMAP account modal and its delete confirmation dialog (see Email_Account_Modal).
 *
 * Self-contained: this is all the JS needed to print the modal on a consumer's own screen. The
 * accounts table script (bh-wp-mailboxes.js) depends on it and reuses its helpers via the
 * `bhWpMailboxesAccountModal` global. Where an accounts table (`.bh-mailboxes-status__table`) is on
 * the page, saving/deleting swaps in the refreshed table returned by the server; otherwise the result
 * is only reported in a notice.
 *
 * Relies on `bh_wp_mailboxes_ajax` (localised action names) and `#_wpnonce_account_actions` (printed
 * with the modal markup).
 */
(function( $ ) {
    'use strict';

    // Where notices go: after `.wp-header-end` (WP core's marker), or after the page title on a
    // consumer's screen that lacks it (e.g. the modal printed on a settings page).
    function noticeAnchor() {
        var $anchor = $( '.wp-header-end' );
        if ( ! $anchor.length ) {
            $anchor = $( '.wrap h1, .wrap h2' ).first();
        }
        if ( ! $anchor.length ) {
            $anchor = $( '<span class="wp-header-end">' ).prependTo( $( '.wrap' ).first().length ? '.wrap' : '#wpbody-content' );
        }
        return $anchor;
    }

    // A dismissible, per-account (or 'accounts'/'all') notice in the grey "in progress" state.
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
        noticeAnchor().after( $n );
        return $n;
    }

    function finishNotice( $notice, msg, borderColor ) {
        $notice.find( '.spinner' ).remove();
        $notice.find( 'p' ).text( msg );
        $notice.css( 'border-left-color', borderColor );
    }

    function showTableNotice( msg, type ) {
        var $n = makeCheckNotice( 'accounts', '' );
        $n.addClass( 'notice-' + type );
        finishNotice( $n, msg, { success: '#00a32a', warning: '#dba617', error: '#d63638' }[ type ] || '#72aee6' );
        return $n;
    }

    function accountRow( accountId ) {
        return $( '.bh-mailboxes-account[data-account-id="' + accountId + '"]' );
    }

    function accountsNonce() {
        return $( '#_wpnonce_account_actions' ).val();
    }

    // Replace the accounts table (when there is one on the page) with the server-rendered copy carried by an AJAX response.
    function replaceTable( html ) {
        $( '.bh-mailboxes-status__table' ).html( html );
    }

    function postAccounts( action, data ) {
        return $.post( ajaxurl, $.extend( { action: action, _wpnonce: accountsNonce() }, data ) );
    }

    function failMessage( xhr, fallback ) {
        var json = xhr && xhr.responseJSON;
        return ( json && json.data && json.data.message ) ? json.data.message : fallback;
    }

    // Shared with the accounts table script.
    window.bhWpMailboxesAccountModal = {
        makeCheckNotice: makeCheckNotice,
        finishNotice:    finishNotice,
        showTableNotice: showTableNotice,
        accountRow:      accountRow,
        replaceTable:    replaceTable,
        postAccounts:    postAccounts,
        failMessage:     failMessage
    };

    // ── Add / edit account modal ───────────────────────────────────────────
    var dialog, $form, $title, $submit, $test, $cancel, $notice, $spinner;

    function initDialog() {
        dialog = document.getElementById( 'bh-mailboxes-account-dialog' );
        if ( ! dialog || typeof dialog.showModal !== 'function' ) {
            return false;
        }
        $form    = $( dialog ).find( '.bh-mailboxes-account-form' );
        $title   = $( dialog ).find( '#bh-mailboxes-account-dialog-title' );
        $submit  = $form.find( '.bh-mailboxes-account-form__submit' );
        $test    = $form.find( '.bh-mailboxes-account-form__test' );
        $cancel  = $form.find( '.bh-mailboxes-account-form__cancel' );
        $notice  = $form.find( '.bh-mailboxes-account-form__notice' );
        $spinner = $form.find( '.spinner' );
        return true;
    }

    function formNotice( msg, type ) {
        $notice.attr( 'class', 'bh-mailboxes-account-form__notice notice inline notice-' + type ).find( 'p' ).text( msg );
        $notice.prop( 'hidden', false );
    }

    function setEditOnlyVisible( visible ) {
        $form.find( '.bh-mailboxes-account-form__edit-only' ).prop( 'hidden', ! visible );
    }

    function openAddDialog() {
        $form[ 0 ].reset();
        $form.attr( 'data-mode', 'add' );
        $form.find( '[name="account_post_id"]' ).val( '' );
        $form.find( '[name="email_address"]' ).prop( 'readOnly', false );
        $form.find( '[name="password"]' ).prop( 'required', true );
        $title.text( $title.data( 'add-title' ) );
        $submit.text( $submit.data( 'add-label' ) );
        setEditOnlyVisible( false );
        $notice.prop( 'hidden', true );
        dialog.showModal();
        $form.find( '[name="display_name"]' ).trigger( 'focus' );
    }

    // Pre-fill from the row's data attributes (password stays blank: empty keeps the saved one).
    function openEditDialog( $row ) {
        var hasCredentials = String( $row.data( 'has-credentials' ) ) === '1';
        $form[ 0 ].reset();
        $form.attr( 'data-mode', 'edit' );
        $form.find( '[name="account_post_id"]' ).val( $row.data( 'account-id' ) );
        $form.find( '[name="display_name"]' ).val( $row.data( 'account-name' ) );
        $form.find( '[name="email_address"]' ).val( $row.data( 'email-address' ) ).prop( 'readOnly', true );
        $form.find( '[name="server"]' ).val( $row.data( 'server' ) || '' );
        $form.find( '[name="username"]' ).val( $row.data( 'username' ) || '' );
        $form.find( '[name="encryption"]' ).val( $row.data( 'encryption' ) === undefined ? 'TLS' : String( $row.data( 'encryption' ) ) );
        $form.find( '[name="password"]' ).val( '' ).prop( 'required', ! hasCredentials );
        $title.text( $title.data( 'edit-title' ) );
        $submit.text( $submit.data( 'edit-label' ) );
        setEditOnlyVisible( hasCredentials );
        $notice.prop( 'hidden', true );
        dialog.showModal();
        $form.find( '[name="display_name"]' ).trigger( 'focus' );
    }

    function formData() {
        var data = {};
        $form.serializeArray().forEach( function( field ) {
            data[ field.name ] = field.value;
        } );
        return data;
    }

    // Try the entered details against the server without saving anything; the result stays in the form.
    function testConnection() {
        // Same required-field checks as submitting (email, server, and password when adding).
        if ( ! $form[ 0 ].reportValidity() ) {
            return;
        }
        $notice.prop( 'hidden', true );

        var label = $test.text();
        $test.prop( 'disabled', true ).text( 'Testing…' );
        $submit.prop( 'disabled', true );
        $spinner.addClass( 'is-active' );

        postAccounts( bh_wp_mailboxes_ajax.test_connection_action, formData() ).done( function( response ) {
            // A refused login / unreachable server comes back as HTTP 200 with success:false.
            formNotice( response.data.message, response.success ? 'success' : 'error' );
        } ).fail( function( xhr ) {
            formNotice( failMessage( xhr, 'Connection test failed: server error.' ), 'error' );
        } ).always( function() {
            $test.prop( 'disabled', false ).text( label );
            $submit.prop( 'disabled', false );
            $spinner.removeClass( 'is-active' );
        } );
    }

    function submitAccount( event ) {
        event.preventDefault();
        $notice.prop( 'hidden', true );

        var data = formData();

        var label = $submit.text();
        $submit.prop( 'disabled', true ).text( 'Saving…' );
        $test.prop( 'disabled', true );
        $spinner.addClass( 'is-active' );

        postAccounts( bh_wp_mailboxes_ajax.save_account_action, data ).done( function( response ) {
            replaceTable( response.data.table_html );
            dialog.close();
            if ( response.data.connection && response.data.connection.success ) {
                showTableNotice( 'Account saved. Connected successfully.', 'success' );
            } else {
                var msg = response.data.connection ? response.data.connection.message : '';
                showTableNotice( 'Account saved, but the connection test failed: ' + msg, 'warning' );
            }
        } ).fail( function( xhr ) {
            formNotice( failMessage( xhr, 'The account could not be saved.' ), 'error' );
        } ).always( function() {
            $submit.prop( 'disabled', false ).text( label );
            $test.prop( 'disabled', false );
            $spinner.removeClass( 'is-active' );
        } );
    }

    // ── Delete confirmation dialog ─────────────────────────────────────────
    var confirmDialog, pendingDelete = null;

    function openConfirmDialog( $row ) {
        pendingDelete = $row.data( 'account-id' );
        $( confirmDialog ).find( '.bh-mailboxes-account-confirm__message' ).text( 'Delete ' + $row.data( 'email-address' ) + '?' );
        $( confirmDialog ).find( '.bh-mailboxes-account-confirm__delete' ).prop( 'disabled', false );
        confirmDialog.showModal();
    }

    function deleteAccount( accountId ) {
        var emailAddress = accountRow( accountId ).data( 'email-address' );
        $( confirmDialog ).find( '.bh-mailboxes-account-confirm__delete' ).prop( 'disabled', true );
        postAccounts( bh_wp_mailboxes_ajax.delete_account_action, { account_post_id: accountId } ).done( function( response ) {
            replaceTable( response.data.table_html );
            showTableNotice( emailAddress + ' deleted. Its downloaded emails are kept.', 'success' );
        } ).fail( function( xhr ) {
            showTableNotice( failMessage( xhr, 'The account could not be deleted.' ), 'error' );
        } ).always( function() {
            confirmDialog.close();
        } );
    }

    $( function() {
        if ( ! initDialog() ) {
            return;
        }
        confirmDialog = document.getElementById( 'bh-mailboxes-account-confirm' );

        $( document ).on( 'click', '.bh-account-add', openAddDialog );
        $( document ).on( 'click', '.bh-account-edit', function( event ) {
            event.preventDefault();
            openEditDialog( accountRow( $( this ).data( 'account-id' ) ) );
        } );
        $form.on( 'submit', submitAccount );
        $test.on( 'click', testConnection );
        $cancel.add( $( dialog ).find( '.bh-mailboxes-account-dialog__close' ) ).on( 'click', function() {
            dialog.close();
        } );

        $( document ).on( 'click', '.bh-account-delete', function( event ) {
            event.preventDefault();
            openConfirmDialog( accountRow( $( this ).data( 'account-id' ) ) );
        } );
        $( confirmDialog ).find( '.bh-mailboxes-account-confirm__delete' ).on( 'click', function() {
            if ( pendingDelete ) {
                deleteAccount( pendingDelete );
            }
        } );
        $( confirmDialog ).find( '.bh-mailboxes-account-confirm__cancel' ).on( 'click', function() {
            confirmDialog.close();
        } );
        $( confirmDialog ).on( 'close', function() {
            pendingDelete = null;
        } );

        // Clicking the backdrop (outside a dialog's box) closes it.
        $( [ dialog, confirmDialog ] ).on( 'click', function( event ) {
            if ( event.target === this ) {
                this.close();
            }
        } );
    } );

} )( jQuery );
