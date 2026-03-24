/**
 * LeanAutoLinks Admin JavaScript
 *
 * Handles AJAX actions for the admin UI: rule CRUD, toggle, bulk actions,
 * exclusion management, settings save, import/export, and queue auto-refresh.
 */
(function ($) {
    'use strict';

    var LW = window.leanautolinksAdmin || {};

    // =========================================================================
    // Utility
    // =========================================================================

    function showStatus(selector, message, isError) {
        var $el = $(selector);
        $el.text(message)
            .removeClass('lw-error')
            .toggleClass('lw-error', !!isError)
            .fadeIn();

        if (!isError) {
            setTimeout(function () { $el.fadeOut(); }, 4000);
        }
    }

    function ajaxPost(data, statusSelector, onSuccess) {
        data.action = 'leanautolinks_admin';
        data._nonce = LW.nonce;

        $.post(LW.ajaxUrl, data, function (response) {
            if (response.success) {
                var msg = typeof response.data === 'string' ? response.data : (response.data.message || LW.strings.done);
                showStatus(statusSelector, msg, false);
                if (typeof onSuccess === 'function') {
                    onSuccess(response.data);
                }
            } else {
                showStatus(statusSelector, response.data || LW.strings.error, true);
            }
        }).fail(function () {
            showStatus(statusSelector, LW.strings.error, true);
        });
    }

    function restRequest(method, endpoint, data, statusSelector, onSuccess) {
        $.ajax({
            url: LW.restUrl + endpoint,
            method: method,
            contentType: 'application/json',
            data: data ? JSON.stringify(data) : undefined,
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', LW.restNonce);
            },
            success: function (response) {
                if (typeof onSuccess === 'function') {
                    onSuccess(response);
                }
            },
            error: function (xhr) {
                var msg = LW.strings.error;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                if (statusSelector) {
                    showStatus(statusSelector, msg, true);
                }
            }
        });
    }

    // =========================================================================
    // Dashboard Quick Actions
    // =========================================================================

    $(document).on('click', '.lw-ajax-action', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var action = $btn.data('action');
        var scope = $btn.data('scope') || '';

        if (action === 'bulk_reprocess' && !confirm(LW.strings.confirmBulk)) {
            return;
        }
        if (action === 'clear_done' && !confirm(LW.strings.confirmClearDone)) {
            return;
        }

        $btn.prop('disabled', true).text(LW.strings.processing);
        var statusSel = '#lw-action-status, #lw-queue-action-status';

        ajaxPost({
            action_type: 'bulk_action',
            bulk_type: action,
            scope: scope
        }, statusSel, function () {
            $btn.prop('disabled', false).text($btn.text());
            setTimeout(function () { location.reload(); }, 1500);
        });
    });

    // =========================================================================
    // Rules: Add New
    // =========================================================================

    $('#lw-add-rule-form').on('submit', function (e) {
        e.preventDefault();

        var data = {
            action_type: 'create_rule',
            keyword: $('#lw-keyword').val(),
            target_url: $('#lw-target-url').val(),
            rule_type: $('#lw-rule-type').val(),
            entity_type: $('#lw-entity-type').val(),
            priority: $('#lw-priority').val(),
            max_per_post: $('#lw-max-per-post').val(),
            case_sensitive: $('input[name="case_sensitive"]').is(':checked') ? 1 : 0,
            nofollow: $('input[name="nofollow"]').is(':checked') ? 1 : 0,
            sponsored: $('input[name="sponsored"]').is(':checked') ? 1 : 0
        };

        ajaxPost(data, '#lw-rule-status', function () {
            setTimeout(function () { location.reload(); }, 1000);
        });
    });

    // Show/hide entity type based on rule type.
    $('#lw-rule-type').on('change', function () {
        var isEntity = $(this).val() === 'entity';
        $('#lw-entity-type-wrapper').toggle(isEntity);

        // Auto-check sponsored for affiliate.
        if ($(this).val() === 'affiliate') {
            $('#lw-sponsored').prop('checked', true);
        }
    });

    // =========================================================================
    // Rules: Toggle Active (switch style)
    // =========================================================================

    $(document).on('click', '.lw-toggle-switch', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var ruleId = $btn.data('rule-id');

        ajaxPost({
            action_type: 'toggle_rule',
            rule_id: ruleId
        }, '#lw-rule-status', function (data) {
            var $label = $btn.next('.lw-toggle-label');
            if (data.is_active) {
                $btn.addClass('lw-active');
                $label.text('On');
            } else {
                $btn.removeClass('lw-active');
                $label.text('Off');
            }
        });
    });

    // =========================================================================
    // Rules: Edit
    // =========================================================================

    $(document).on('click', '.lw-edit-rule', function (e) {
        e.preventDefault();

        var $btn = $(this);
        $('#lw-edit-rule-id').val($btn.data('rule-id'));
        $('#lw-edit-keyword').val($btn.data('keyword'));
        $('#lw-edit-target-url').val($btn.data('target-url'));
        $('#lw-edit-rule-type').val($btn.data('rule-type'));
        $('#lw-edit-entity-type').val($btn.data('entity-type') || '');
        $('#lw-edit-priority').val($btn.data('priority'));
        $('#lw-edit-max-per-post').val($btn.data('max-per-post'));
        $('#lw-edit-case-sensitive').prop('checked', $btn.data('case-sensitive') == 1);
        $('#lw-edit-nofollow').prop('checked', $btn.data('nofollow') == 1);
        $('#lw-edit-sponsored').prop('checked', $btn.data('sponsored') == 1);

        // Show/hide entity type.
        $('#lw-edit-entity-type-wrapper').toggle($btn.data('rule-type') === 'entity');

        $('#lw-edit-modal').show();
    });

    $('#lw-edit-rule-type').on('change', function () {
        $('#lw-edit-entity-type-wrapper').toggle($(this).val() === 'entity');
        if ($(this).val() === 'affiliate') {
            $('#lw-edit-sponsored').prop('checked', true);
        }
    });

    $('#lw-edit-submit').on('click', function () {
        var data = {
            action_type: 'update_rule',
            rule_id: $('#lw-edit-rule-id').val(),
            keyword: $('#lw-edit-keyword').val(),
            target_url: $('#lw-edit-target-url').val(),
            rule_type: $('#lw-edit-rule-type').val(),
            entity_type: $('#lw-edit-entity-type').val(),
            priority: $('#lw-edit-priority').val(),
            max_per_post: $('#lw-edit-max-per-post').val(),
            case_sensitive: $('#lw-edit-case-sensitive').is(':checked') ? 1 : 0,
            nofollow: $('#lw-edit-nofollow').is(':checked') ? 1 : 0,
            sponsored: $('#lw-edit-sponsored').is(':checked') ? 1 : 0
        };

        ajaxPost(data, '#lw-edit-status', function () {
            setTimeout(function () { location.reload(); }, 1000);
        });
    });

    // =========================================================================
    // Rules: Delete
    // =========================================================================

    $(document).on('click', '.lw-delete-rule', function (e) {
        e.preventDefault();

        if (!confirm(LW.strings.confirmDelete)) {
            return;
        }

        var $btn = $(this);
        var ruleId = $btn.data('rule-id');

        ajaxPost({
            action_type: 'delete_rule',
            rule_id: ruleId
        }, '#lw-rule-status', function () {
            $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
        });
    });

    // =========================================================================
    // Rules: Bulk Actions
    // =========================================================================

    $('#lw-select-all').on('change', function () {
        $('input[name="rule_ids[]"]').prop('checked', $(this).is(':checked'));
    });

    $('#lw-apply-bulk').on('click', function (e) {
        e.preventDefault();

        var action = $('#lw-bulk-action').val();
        if (!action) return;

        var ids = [];
        $('input[name="rule_ids[]"]:checked').each(function () {
            ids.push($(this).val());
        });

        if (ids.length === 0) return;

        if (!confirm(LW.strings.confirmDelete)) return;

        var bulkType = action === 'delete' ? 'delete_rules' :
                       action === 'activate' ? 'activate_rules' : 'deactivate_rules';

        ajaxPost({
            action_type: 'bulk_action',
            bulk_type: bulkType,
            rule_ids: ids
        }, '#lw-rule-status', function () {
            setTimeout(function () { location.reload(); }, 1000);
        });
    });

    // =========================================================================
    // Rules: Import/Export
    // =========================================================================

    $('#lw-import-rules-btn').on('click', function () {
        $('#lw-import-modal').show();
    });

    $(document).on('click', '.lw-modal-close', function () {
        $(this).closest('.lw-modal').hide();
    });

    // File upload fills textarea.
    $('#lw-import-file').on('change', function (e) {
        var file = e.target.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function (ev) {
            $('#lw-import-json').val(ev.target.result);
        };
        reader.readAsText(file);
    });

    $('#lw-import-submit').on('click', function () {
        var json = $('#lw-import-json').val().trim();

        if (!json) {
            showStatus('#lw-import-status', 'Please provide JSON data.', true);
            return;
        }

        try {
            var rules = JSON.parse(json);
        } catch (err) {
            showStatus('#lw-import-status', 'Invalid JSON format.', true);
            return;
        }

        restRequest('POST', 'rules/import', { rules: rules }, '#lw-import-status', function (response) {
            showStatus('#lw-import-status', LW.strings.imported + ' (' + response.imported + '/' + response.total + ')', false);
            setTimeout(function () { location.reload(); }, 2000);
        });
    });

    $('#lw-export-rules-btn').on('click', function () {
        $.ajax({
            url: LW.restUrl + 'rules?per_page=100',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', LW.restNonce);
            },
            success: function (data) {
                var json = JSON.stringify(data, null, 2);
                var blob = new Blob([json], { type: 'application/json' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'leanautolinks-rules.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }
        });
    });

    // =========================================================================
    // Exclusions
    // =========================================================================

    $('#lw-add-exclusion-form').on('submit', function (e) {
        e.preventDefault();

        ajaxPost({
            action_type: 'create_exclusion',
            excl_type: $('#lw-excl-type').val(),
            excl_value: $('#lw-excl-value').val()
        }, '#lw-exclusion-status', function () {
            setTimeout(function () { location.reload(); }, 1000);
        });
    });

    $(document).on('click', '.lw-delete-exclusion', function (e) {
        e.preventDefault();

        if (!confirm(LW.strings.confirmDelete)) return;

        var $btn = $(this);
        var exclId = $btn.data('exclusion-id');

        ajaxPost({
            action_type: 'delete_exclusion',
            exclusion_id: exclId
        }, '#lw-exclusion-status', function () {
            $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
        });
    });

    // Quick exclusion buttons.
    $(document).on('click', '.lw-quick-exclusion', function (e) {
        e.preventDefault();

        ajaxPost({
            action_type: 'create_exclusion',
            excl_type: $(this).data('type'),
            excl_value: $(this).data('value')
        }, '#lw-exclusion-status', function () {
            setTimeout(function () { location.reload(); }, 1000);
        });
    });

    // =========================================================================
    // Settings
    // =========================================================================

    $('#lw-settings-form').on('submit', function (e) {
        e.preventDefault();

        var data = {};
        $(this).serializeArray().forEach(function (item) {
            if (item.name === 'supported_post_types[]') {
                if (!data.supported_post_types) data.supported_post_types = [];
                data['supported_post_types[]'] = data['supported_post_types[]'] || [];
                // Use the name as-is for jQuery.
            }
        });

        // Build data manually from form.
        var formData = $(this).serialize() + '&action=leanautolinks_admin&_nonce=' + LW.nonce;

        $.post(LW.ajaxUrl, formData, function (response) {
            if (response.success) {
                showStatus('#lw-settings-status', LW.strings.saved, false);
            } else {
                showStatus('#lw-settings-status', response.data || LW.strings.error, true);
            }
        }).fail(function () {
            showStatus('#lw-settings-status', LW.strings.error, true);
        });
    });

    // =========================================================================
    // Queue Auto-Refresh (when on queue tab with active processing)
    // =========================================================================

    (function autoRefreshQueue() {
        if ($('.lw-queue-stats-bar').length === 0) return;

        // Check if there are pending/processing items.
        var hasPending = parseInt($('.lw-stat-blue strong').text(), 10) > 0;
        var hasProcessing = parseInt($('.lw-stat-yellow strong').text(), 10) > 0;

        if (hasPending || hasProcessing) {
            setTimeout(function () {
                location.reload();
            }, 15000); // Refresh every 15 seconds during active processing.
        }
    })();

})(jQuery);
