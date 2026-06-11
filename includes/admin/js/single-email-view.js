(function ( $ ) {
	'use strict';

	$(function () {
		var settings = window.bhWpMailboxesSingleEmail || {};

		function remoteAction( action, $btn ) {
			$btn.prop( 'disabled', true );

			$.post(
				settings.ajaxUrl || ajaxurl,
				{
					action:    action,
					post_id:   settings.postId,
					_wpnonce:  settings.nonce,
				},
				function ( response ) {
					if ( response.success && response.data && response.data.status_html !== undefined ) {
						$( '.bh-email-remote-status' ).html( response.data.status_html );
					}
					$btn.prop( 'disabled', false );
				}
			).fail( function () {
				$btn.prop( 'disabled', false );
			} );
		}

		$( '#bh-email-mark-read' ).on( 'click', function ( e ) {
			e.preventDefault();
			remoteAction( 'bh_wp_mailboxes_mark_read', $( this ) );
		} );

		$( '#bh-email-mark-unread' ).on( 'click', function ( e ) {
			e.preventDefault();
			remoteAction( 'bh_wp_mailboxes_mark_unread', $( this ) );
		} );

		$( '#bh-email-delete-on-server' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( 'Delete this email on the remote server?' ) ) {
				return;
			}
			remoteAction( 'bh_wp_mailboxes_delete_on_server', $( this ) );
		} );

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
