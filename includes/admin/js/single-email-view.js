(function ( $ ) {
	'use strict';

	$(function () {
		var settings = window.bhWpMailboxesSingleEmail || {};

		/**
		 * Build the same badge markup that PHP's get_remote_status_html() would produce.
		 *
		 * @param {boolean|null} isRead          null = unknown; true = read; false = unread.
		 * @param {boolean|null} isRemoteDeleted null = unknown; true = deleted on server.
		 * @return {string} HTML string.
		 */
		function buildRemoteStatusHtml( isRead, isRemoteDeleted ) {
			var parts = [];
			if ( isRead !== null && isRead !== undefined ) {
				parts.push( isRead
					? '<span class="bh-email-badge bh-email-badge--read">Read on server</span>'
					: '<span class="bh-email-badge bh-email-badge--unread">Unread on server</span>'
				);
			}
			if ( isRemoteDeleted ) {
				parts.push( '<span class="bh-email-badge bh-email-badge--deleted">Deleted on server</span>' );
			}
			return parts.join( ' ' );
		}

		/**
		 * Update the remote-status badge area and action-button visibility after a remote action.
		 *
		 * @param {boolean|null} isRead
		 * @param {boolean|null} isRemoteDeleted
		 */
		function updateRemoteUi( isRead, isRemoteDeleted ) {
			// Clear loading state from the badge fallback and/or the radio options.
			$( '.bh-email-remote-status, #bh-email-read-status-options' ).removeClass( 'is-loading' );
			$( '.bh-email-remote-status .spinner' ).remove();

			// The badge area only exists when the connection can't change status (e.g. deleted); update if present.
			$( '.bh-email-remote-badges' ).html( buildRemoteStatusHtml( isRead, isRemoteDeleted ) );

			if ( isRemoteDeleted ) {
				// Nothing further can be changed once the email is deleted on the server.
				$( '#bh-email-read-status-options, #bh-email-remote-publishing-actions' ).hide();
				$( '#bh-email-remote-deleted' ).show();
				return;
			}

			// Highlight (and select) the radio matching the current server status.
			var $options = $( '#bh-email-read-status-options' );
			$options.find( '.bh-email-status__option' ).removeClass( 'bh-email-status__option--current' );
			var value = isRead === true ? 'read' : ( isRead === false ? 'unread' : null );
			if ( value ) {
				var $input = $( 'input[name="bh_email_remote_read"][value="' + value + '"]' );
				$input.prop( 'checked', true );
				$input.closest( '.bh-email-status__option' ).addClass( 'bh-email-status__option--current' );
			}
		}

		// Re-fetch the Email Log metabox so new entries (including a failed remote action) appear without
		// a page reload.
		function refreshLog() {
			$.get( location.href, function ( html ) {
				var $fresh = $( html ).find( '#bh-email-log-notes .bh-email-log-notes' );
				if ( $fresh.length ) {
					$( '#bh-email-log-notes .bh-email-log-notes' ).replaceWith( $fresh );
				}
			} );
		}

		function remoteAction( action, $btn ) {
			$btn.prop( 'disabled', true );

			$.post(
				settings.ajaxUrl || ajaxurl,
				{
					action:   action,
					post_id:  settings.postId,
					_wpnonce: settings.nonce,
				},
				function ( response ) {
					if ( response.success && response.data ) {
						updateRemoteUi( response.data.is_read, response.data.is_remote_deleted );
					}
					// The action records a log entry (success or failure); reflect it immediately.
					refreshLog();
					$btn.prop( 'disabled', false );
				}
			).fail( function () {
				$btn.prop( 'disabled', false );
			} );
		}

		// Save the selected read/unread radio through the corresponding remote action.
		$( '#bh-email-remote-save' ).on( 'click', function ( e ) {
			e.preventDefault();
			var value = $( 'input[name="bh_email_remote_read"]:checked' ).val();
			if ( ! value ) {
				return;
			}
			// Clear the current-status highlight while the change is pending; updateRemoteUi restores it on success.
			$( '#bh-email-read-status-options .bh-email-status__option' ).removeClass( 'bh-email-status__option--current' );
			var action = value === 'read' ? settings.markReadAction : settings.markUnreadAction;
			remoteAction( action, $( this ) );
		} );

		$( '#bh-email-delete-on-server' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( 'Delete this email on the remote server?' ) ) {
				return;
			}
			remoteAction( settings.deleteOnServerAction, $( this ) );
		} );

		// The status is the only mutable field. Save it through the API (which records the change in the
		// email's log) rather than the native post save, then reload to show the new status and log entry.
		$( '#bh-email-status-box #save' ).on( 'click', function ( e ) {
			e.preventDefault();
			var $btn = $( this ).prop( 'disabled', true );
			$.post(
				settings.ajaxUrl || ajaxurl,
				{
					action:   settings.updateStatusAction,
					post_id:  settings.postId,
					status:   $( 'input[name="post_status"]:checked' ).val(),
					_wpnonce: settings.nonce,
				}
			).done( function () {
				window.location.reload();
			} ).fail( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		// On load, fetch the live remote status and update the highlighted radio (or the badge fallback).
		var $remoteStatus = $( '.bh-email-remote-status.is-loading, #bh-email-read-status-options.is-loading' );
		if ( $remoteStatus.length && settings.getRemoteStatusAction ) {
			$.post(
				settings.ajaxUrl || ajaxurl,
				{
					action:   settings.getRemoteStatusAction,
					post_id:  settings.postId,
					_wpnonce: settings.nonce,
				},
				function ( response ) {
					if ( response.success && response.data ) {
						updateRemoteUi( response.data.is_read, response.data.is_remote_deleted );
					} else {
						$remoteStatus.removeClass( 'is-loading' ).find( '.spinner' ).remove();
					}
				}
			).fail( function () {
				$remoteStatus.removeClass( 'is-loading' ).find( '.spinner' ).remove();
			} );
		}

		function resizeIframe( iframe ) {
			try {
				iframe.style.height = iframe.contentDocument.documentElement.scrollHeight + 'px';
			} catch ( e ) {
				// Cross-origin content blocked; leave default height.
			}
		}

		// Auto-resize email body iframes to their content height on initial load.
		// srcdoc iframes often complete before DOMContentLoaded, so check readyState
		// and resize immediately when the document is already complete.
		$( 'iframe.bh-email-html-body, iframe.bh-email-plain-body' ).each( function () {
			var iframe = this;
			if ( iframe.contentDocument && iframe.contentDocument.readyState === 'complete' ) {
				resizeIframe( iframe );
			} else {
				$( iframe ).on( 'load', function () {
					resizeIframe( iframe );
				} );
			}
		} );

		// Re-run resize when a postbox is expanded, since the iframe may have
		// loaded while the box was collapsed (scrollHeight === 0 when hidden).
		$( document ).on( 'click', '.postbox .toggle-indicator, .postbox .hndle', function () {
			var $postbox = $( this ).closest( '.postbox' );
			// WordPress toggles the 'closed' class synchronously on click,
			// so a zero-delay timeout lets us read the final state.
			setTimeout( function () {
				if ( ! $postbox.hasClass( 'closed' ) ) {
					$postbox.find( 'iframe.bh-email-html-body, iframe.bh-email-plain-body' ).each( function () {
						resizeIframe( this );
					} );
				}
			}, 0 );
		} );
	} );

} )( jQuery );
