/**
 * LeanAutoLinks Gutenberg Sidebar Panel
 *
 * Provides a document settings panel in the block editor that replicates
 * the classic meta box functionality for managing keyword linking rules.
 *
 * Uses vanilla JS with WordPress global packages (no build step required).
 */
(function () {
    'use strict';

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useCallback = wp.element.useCallback;
    var registerPlugin = wp.plugins.registerPlugin;
    var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
    var TextControl = wp.components.TextControl;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;
    var Notice = wp.components.Notice;
    var useSelect = wp.data.useSelect;

    var config = window.leanautolinksGutenberg || {};

    /**
     * Small badge component for rule type display.
     */
    function RuleTypeBadge(props) {
        var type = props.type || 'internal';
        var style = {
            display: 'inline-block',
            fontSize: '11px',
            lineHeight: '1',
            padding: '2px 6px',
            borderRadius: '3px',
            marginLeft: '6px',
            color: '#fff',
            backgroundColor: type === 'affiliate' ? '#d63638' : (type === 'entity' ? '#2271b1' : '#00a32a'),
        };
        return el('span', { style: style }, type.charAt(0).toUpperCase() + type.slice(1));
    }

    /**
     * Main panel component.
     */
    function LeanAutoLinksPanel() {
        var postId = useSelect(function (select) {
            return select('core/editor').getCurrentPostId();
        }, []);

        var postUrl = useSelect(function (select) {
            return select('core/editor').getPermalink();
        }, []);

        var _rulesState = useState([]);
        var rulesPointingHere = _rulesState[0];
        var setRulesPointingHere = _rulesState[1];

        var _appliedState = useState([]);
        var appliedKeywords = _appliedState[0];
        var setAppliedKeywords = _appliedState[1];

        var _loadingState = useState(true);
        var loading = _loadingState[0];
        var setLoading = _loadingState[1];

        var _keywordState = useState('');
        var newKeyword = _keywordState[0];
        var setNewKeyword = _keywordState[1];

        var _addingState = useState(false);
        var adding = _addingState[0];
        var setAdding = _addingState[1];

        var _noticeState = useState(null);
        var notice = _noticeState[0];
        var setNotice = _noticeState[1];

        var _removingState = useState({});
        var removingIds = _removingState[0];
        var setRemovingIds = _removingState[1];

        /**
         * Fetch data from the server via AJAX.
         */
        var fetchData = useCallback(function () {
            if (!postId) return;

            setLoading(true);

            var formData = new FormData();
            formData.append('action', 'leanautolinks_gutenberg_get_data');
            formData.append('_nonce', config.nonce);
            formData.append('post_id', postId);

            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (result.success && result.data) {
                        setRulesPointingHere(result.data.rules_pointing_here || []);
                        setAppliedKeywords(result.data.applied_keywords || []);
                    }
                    setLoading(false);
                })
                .catch(function () {
                    setLoading(false);
                    setNotice({ type: 'error', message: config.strings.error });
                });
        }, [postId]);

        useEffect(function () {
            fetchData();
        }, [fetchData]);

        /**
         * Add a new keyword rule.
         */
        function handleAddKeyword() {
            var keyword = newKeyword.trim();
            if (!keyword) {
                setNotice({ type: 'error', message: config.strings.keywordRequired });
                return;
            }

            setAdding(true);
            setNotice(null);

            var formData = new FormData();
            formData.append('action', 'leanautolinks_metabox_add_rule');
            formData.append('_nonce', config.nonce);
            formData.append('post_id', postId);
            formData.append('keyword', keyword);

            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    setAdding(false);
                    if (result.success) {
                        setNewKeyword('');
                        setNotice({ type: 'success', message: result.data.message || config.strings.added });
                        fetchData();
                    } else {
                        setNotice({ type: 'error', message: result.data || config.strings.error });
                    }
                })
                .catch(function () {
                    setAdding(false);
                    setNotice({ type: 'error', message: config.strings.error });
                });
        }

        /**
         * Remove a keyword rule.
         */
        function handleRemoveRule(ruleId) {
            if (!window.confirm(config.strings.confirmDelete)) return;

            var newRemoving = Object.assign({}, removingIds);
            newRemoving[ruleId] = true;
            setRemovingIds(newRemoving);

            var formData = new FormData();
            formData.append('action', 'leanautolinks_metabox_remove_rule');
            formData.append('_nonce', config.nonce);
            formData.append('rule_id', ruleId);

            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    var updated = Object.assign({}, removingIds);
                    delete updated[ruleId];
                    setRemovingIds(updated);

                    if (result.success) {
                        setNotice({ type: 'success', message: config.strings.removed });
                        setRulesPointingHere(function (prev) {
                            return prev.filter(function (r) { return r.id !== ruleId; });
                        });
                    } else {
                        setNotice({ type: 'error', message: result.data || config.strings.error });
                    }
                })
                .catch(function () {
                    var updated = Object.assign({}, removingIds);
                    delete updated[ruleId];
                    setRemovingIds(updated);
                    setNotice({ type: 'error', message: config.strings.error });
                });
        }

        /**
         * Handle Enter key in keyword input.
         */
        function handleKeyDown(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                handleAddKeyword();
            }
        }

        // Build children array for the panel.
        var children = [];

        // Notice
        if (notice) {
            children.push(
                el(Notice, {
                    key: 'notice',
                    status: notice.type === 'error' ? 'error' : 'success',
                    isDismissible: true,
                    onRemove: function () { setNotice(null); },
                    style: { margin: '0 0 12px' },
                }, notice.message)
            );
        }

        if (loading) {
            children.push(
                el('div', {
                    key: 'loading',
                    style: { display: 'flex', alignItems: 'center', gap: '8px', padding: '8px 0' },
                },
                    el(Spinner, null),
                    el('span', null, config.strings.loading)
                )
            );
        } else {
            // Keywords Pointing Here section
            children.push(
                el('div', { key: 'pointing-here', style: { marginBottom: '16px' } },
                    el('h4', { style: { margin: '0 0 8px', fontSize: '12px', textTransform: 'uppercase', color: '#757575' } },
                        config.strings.pointingHere
                    ),
                    rulesPointingHere.length > 0
                        ? el('ul', { style: { margin: 0, padding: 0, listStyle: 'none' } },
                            rulesPointingHere.map(function (rule) {
                                return el('li', {
                                    key: 'rule-' + rule.id,
                                    style: {
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'space-between',
                                        padding: '6px 0',
                                        borderBottom: '1px solid #e0e0e0',
                                    },
                                },
                                    el('span', { style: { display: 'flex', alignItems: 'center', flex: 1, minWidth: 0 } },
                                        el('span', {
                                            style: {
                                                fontWeight: 500,
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                                whiteSpace: 'nowrap',
                                            },
                                        }, rule.keyword),
                                        el(RuleTypeBadge, { type: rule.rule_type })
                                    ),
                                    el(Button, {
                                        icon: 'no-alt',
                                        label: config.strings.removeRule,
                                        isSmall: true,
                                        isDestructive: true,
                                        isBusy: !!removingIds[rule.id],
                                        disabled: !!removingIds[rule.id],
                                        onClick: function () { handleRemoveRule(rule.id); },
                                    })
                                );
                            })
                        )
                        : el('p', { style: { margin: 0, color: '#757575', fontStyle: 'italic', fontSize: '13px' } },
                            config.strings.noRulesPointingHere
                        )
                )
            );

            // Applied Keywords section
            children.push(
                el('div', { key: 'applied', style: { marginBottom: '16px' } },
                    el('h4', { style: { margin: '0 0 8px', fontSize: '12px', textTransform: 'uppercase', color: '#757575' } },
                        config.strings.appliedKeywords
                    ),
                    appliedKeywords.length > 0
                        ? el('ul', { style: { margin: 0, padding: 0, listStyle: 'none' } },
                            appliedKeywords.map(function (item) {
                                return el('li', {
                                    key: 'applied-' + item.id,
                                    style: {
                                        padding: '6px 0',
                                        borderBottom: '1px solid #e0e0e0',
                                    },
                                },
                                    el('div', {
                                        style: {
                                            display: 'flex',
                                            alignItems: 'center',
                                        },
                                    },
                                        el('span', {
                                            style: {
                                                fontWeight: 500,
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                                whiteSpace: 'nowrap',
                                                flex: 1,
                                            },
                                        }, item.keyword),
                                        el(RuleTypeBadge, { type: item.rule_type }),
                                        el('span', {
                                            style: {
                                                marginLeft: '6px',
                                                fontSize: '11px',
                                                color: '#757575',
                                            },
                                        }, '\u00d7' + item.link_count)
                                    ),
                                    el('div', {
                                        style: {
                                            marginTop: '2px',
                                            fontSize: '11px',
                                            color: '#757575',
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: '3px',
                                        },
                                    },
                                        el('span', {
                                            className: 'dashicons dashicons-admin-links',
                                            style: { fontSize: '12px', width: '12px', height: '12px' },
                                        }),
                                        el('a', {
                                            href: item.target_url,
                                            target: '_blank',
                                            rel: 'noopener',
                                            style: {
                                                color: '#2271b1',
                                                textDecoration: 'none',
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                                whiteSpace: 'nowrap',
                                            },
                                        }, item.target_url)
                                    )
                                );
                            })
                        )
                        : el('p', { style: { margin: 0, color: '#757575', fontStyle: 'italic', fontSize: '13px' } },
                            config.strings.noApplied
                        )
                )
            );

            // Add Keyword section
            children.push(
                el('div', { key: 'add-keyword' },
                    el('h4', { style: { margin: '0 0 4px', fontSize: '12px', textTransform: 'uppercase', color: '#757575' } },
                        config.strings.addKeyword
                    ),
                    el('p', { style: { margin: '0 0 8px', fontSize: '12px', color: '#757575' } },
                        config.strings.addDescription
                    ),
                    el('div', { style: { display: 'flex', gap: '8px' } },
                        el(TextControl, {
                            value: newKeyword,
                            onChange: setNewKeyword,
                            placeholder: config.strings.keywordPlaceholder,
                            onKeyDown: handleKeyDown,
                            __nextHasNoMarginBottom: true,
                            style: { flex: 1 },
                        }),
                        el(Button, {
                            variant: 'primary',
                            onClick: handleAddKeyword,
                            isBusy: adding,
                            disabled: adding,
                            style: { flexShrink: 0 },
                        }, config.strings.addButton)
                    )
                )
            );
        }

        return el(
            PluginDocumentSettingPanel,
            {
                name: 'leanautolinks-keywords',
                title: 'LeanAutoLinks Keywords',
                icon: 'admin-links',
            },
            children
        );
    }

    registerPlugin('leanautolinks-keywords', {
        render: LeanAutoLinksPanel,
        icon: 'admin-links',
    });
})();
