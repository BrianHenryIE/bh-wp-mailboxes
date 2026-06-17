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
			var $container = $( '.bh-email-remote-status' );
			$container.removeClass( 'is-loading' );
			$container.find( '.spinner' ).remove();
			$container.find( '.bh-email-remote-badges' ).html( buildRemoteStatusHtml( isRead, isRemoteDeleted ) );

			if ( isRemoteDeleted ) {
				$( '#bh-email-mark-read, #bh-email-mark-unread, #bh-email-delete-on-server' ).closest( 'p' ).hide();
				return;
			}

			if ( isRead === true ) {
				$( '#bh-email-mark-read' ).closest( 'p' ).hide();
				$( '#bh-email-mark-unread' ).closest( 'p' ).show();
			} else if ( isRead === false ) {
				$( '#bh-email-mark-unread' ).closest( 'p' ).hide();
				$( '#bh-email-mark-read' ).closest( 'p' ).show();
			}
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
					$btn.prop( 'disabled', false );
				}
			).fail( function () {
				$btn.prop( 'disabled', false );
			} );
		}

		$( '#bh-email-mark-read' ).on( 'click', function ( e ) {
			e.preventDefault();
			remoteAction( settings.markReadAction, $( this ) );
		} );

		$( '#bh-email-mark-unread' ).on( 'click', function ( e ) {
			e.preventDefault();
			remoteAction( settings.markUnreadAction, $( this ) );
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

		// On load, the remote badges are shown dimmed with a spinner; fetch the live status and update them.
		var $remoteStatus = $( '.bh-email-remote-status.is-loading' );
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
