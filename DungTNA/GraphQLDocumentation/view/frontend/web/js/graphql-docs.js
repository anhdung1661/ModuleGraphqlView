// view/frontend/web/js/graphql-docs.js
define(['jquery'], function ($) {
    'use strict';

    return function (config) {
        const ajaxUrl = config.ajaxUrl;
        let loadedTabs = {'queries': true};
        let searchTimeout;

        // Event Delegation - works for dynamic content
        $(document).on('click', '.main-tab-button, .stats-tab-button', function () {
            const tab = $(this).data('tab');
            switchTab(tab);
        });

        $(document).on('click', '.code-tab-button', function () {
            const $btn = $(this);
            const $parent = $btn.closest('.code-examples-content');
            const target = $btn.data('tab');

            $parent.find('.code-tab-button').removeClass('active');
            $parent.find('.code-content').removeClass('active');
            $btn.addClass('active');
            $parent.find('#' + target).addClass('active');
        });

        $(document).on('click', '.copy-btn', function () {
            const target = $(this).data('clipboard-target');
            const text = $(target).text();
            GraphQLDocs.copyToClipboard(text, this);
        });

        // Debounced Search
        $('#graphqlSearch').on('keyup', function () {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => performSearch(), 300);
        });

        function performSearch() {
            const query = $('#graphqlSearch').val().toLowerCase().trim();
            const activeTab = $('.main-tab-button.active').data('tab');
            const $content = $('#' + activeTab + '-tab');

            if (!query) {
                $content.find('.schema-item').show();
                return;
            }

            $content.find('.schema-item').each(function () {
                const $item = $(this);
                const name = $item.data('name')?.toLowerCase() || '';
                const text = $item.text().toLowerCase();

                if (name.includes(query) || text.includes(query)) {
                    $item.show();
                    const $content = $item.find('.schema-item-content');
                    if ($content.hasClass('collapsed')) {
                        $content.removeClass('collapsed');
                        $item.find('.toggle-icon').removeClass('collapsed');
                    }
                } else {
                    $item.hide();
                }
            });
        }

        function switchTab(tab) {
            $('.main-tab-button, .stats-tab-button').removeClass('active');
            $('.tab-content').removeClass('active');

            $(`.main-tab-button[data-tab="${tab}"], .stats-tab-button[data-tab="${tab}"]`).addClass('active');
            $('#' + tab + '-tab').addClass('active');

            if (!loadedTabs[tab]) {
                loadTab(tab);
            } else {
                performSearch();
            }
        }

        function loadTab(tab) {
            const $container = $('#' + tab + '-tab');
            $container.html('<div class="loading-spinner">Loading ' + tab.replace(/-/g, ' ') + '...</div>');

            $.get(ajaxUrl, {tab: tab})
                .done(function (response) {
                    if (response.success) {
                        $container.html(response.html);
                        loadedTabs[tab] = true;
                        performSearch();
                    } else {
                        $container.html('<div class="message error">' + response.error + '</div>');
                    }
                })
                .fail(function () {
                    $container.html('<div class="message error">Failed to load content.</div>');
                });
        }

        // Initialize
        $(function () {
            // Collapse all initially
            $('.schema-section-content, .schema-item-content, .toggle-content, .code-examples-content')
                .addClass('collapsed');
            $('.toggle-icon').addClass('collapsed');
        });
    };
});