/**
 * LeanAutoLinks Meta Box JavaScript
 *
 * Handles AJAX actions for the keyword meta box in the post editor.
 */
(function ($) {
    'use strict';

    var MB = window.leanautolinksMetabox || {};

    function showStatus(message, isError) {
        var $el = $('#lw-mb-status');
        $el.text(message)
            .removeClass('lw-mb-success lw-mb-error')
            .addClass(isError ? 'lw-mb-error' : 'lw-mb-success')
            .show();

        if (!isError) {
            setTimeout(function () { $el.fadeOut(); }, 4000);
        }
    }

    // Add rule from meta box.
    $('#lw-mb-add-btn').on('click', function () {
        var keyword = $('#lw-mb-keyword').val().trim();

        if (!keyword) {
            showStatus('Keyword is required.', true);
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(MB.ajaxUrl, {
            action: 'leanautolinks_metabox_add_rule',
            _nonce: MB.nonce,
            post_id: MB.postId,
            keyword: keyword
        }, function (response) {
            $btn.prop('disabled', false);
            if (response.success) {
                showStatus(response.data.message || MB.strings.added, false);
                $('#lw-mb-keyword').val('');
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                showStatus(response.data || MB.strings.error, true);
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            showStatus(MB.strings.error, true);
        });
    });

    // Submit on Enter key in keyword field.
    $('#lw-mb-keyword').on('keypress', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#lw-mb-add-btn').trigger('click');
        }
    });

    // Remove rule.
    $(document).on('click', '.lw-mb-remove-btn', function () {
        if (!confirm(MB.strings.confirmDelete)) {
            return;
        }

        var $btn = $(this);
        var ruleId = $btn.data('rule-id');
        $btn.prop('disabled', true);

        $.post(MB.ajaxUrl, {
            action: 'leanautolinks_metabox_remove_rule',
            _nonce: MB.nonce,
            rule_id: ruleId
        }, function (response) {
            if (response.success) {
                $btn.closest('.lw-metabox-item').fadeOut(300, function () {
                    $(this).remove();
                });
                showStatus(MB.strings.removed, false);
            } else {
                $btn.prop('disabled', false);
                showStatus(response.data || MB.strings.error, true);
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            showStatus(MB.strings.error, true);
        });
    });

})(jQuery);
