(function( $ ) {
    'use strict';

    $(function() {

        $('#check-email').on(
            'click',
            function(event) {
                event.preventDefault();

                let buttonName = event.target.name;

                var data = {};

                // $_GET['page'] has the slug.
                // e.g. ?page=bh-wp-logger-test-plugin-logs
                var urlParams = new URLSearchParams(window.location.search);

                data.mailboxes_cpt = urlParams.get('post_type');
                data._wpnonce = $('#_wpnonce_checknow').val();

                switch ( buttonName) {
                    case 'check-email':
                        data.action = 'bh_wp_mailboxes_check_email';

                        break;
                    default:
                        return;
                }

                $.post(
                    ajaxurl,
                    data,
                    function (response) {

                        console.log(response);
                    }
                );

            }
        );


    });

})( jQuery );
