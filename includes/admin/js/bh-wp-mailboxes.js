(function( $ ) {
    'use strict';

    $(function() {

        // ── Global check-all button ────────────────────────────────────────────
        $('#check-email').on('click', function(event) {
            event.preventDefault();

            var urlParams = new URLSearchParams(window.location.search);
            var data = {
                action: 'bh_wp_mailboxes_check_email',
                mailboxes_cpt: urlParams.get('post_type'),
                _wpnonce: $('#_wpnonce_checknow').val(),
            };

            $.post(ajaxurl, data, function(response) {
                console.log(response);
            });
        });

        // ── Per-account: Check now ─────────────────────────────────────────────
        $(document).on('click', '.bh-check-account', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Checking…');

            $.post(ajaxurl, {
                action: 'bh_wp_mailboxes_check_account',
                account_post_id: $btn.data('account-id'),
                _wpnonce: $('#_wpnonce_account_actions').val(),
            }, function(response) {
                $btn.prop('disabled', false).text('Check now');
                if (response.success) {
                    location.reload();
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Check now');
            });
        });

        // ── Per-account: Since… toggle ─────────────────────────────────────────
        $(document).on('click', '.bh-fetch-since-toggle', function() {
            var accountId = $(this).data('account-id');
            var $input = $('.bh-fetch-since-input[data-account-id="' + accountId + '"]');
            $input.toggle().focus();
        });

        // ── Per-account: Since date change ─────────────────────────────────────
        $(document).on('change', '.bh-fetch-since-input', function() {
            var $input = $(this);
            $.post(ajaxurl, {
                action: 'bh_wp_mailboxes_set_fetch_since',
                account_post_id: $input.data('account-id'),
                since_date: $input.val(),
                _wpnonce: $('#_wpnonce_account_actions').val(),
            }, function(response) {
                if (response.success) {
                    $input.hide();
                }
            });
        });

    });

})( jQuery );
