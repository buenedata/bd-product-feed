/**
 * BD Product Feed Admin JavaScript
 * Handles AJAX interactions and UI enhancements
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        BDProductFeed.init();
    });

    // Main plugin object
    window.BDProductFeed = {
        
        /**
         * Initialize the admin interface
         */
        init: function() {
            this.bindEvents();
            this.initTooltips();
            this.initProgressBars();
            this.checkFeedStatus();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Generate feed button
            $('#bd-generate-feed').on('click', this.generateFeed);
            
            // Test feed button
            $('#bd-test-feed').on('click', this.testFeed);
            
            // Validate feed button
            $('#bd-validate-feed').on('click', this.validateFeed);
            
            // Copy feed URL button
            $('.bd-copy-button').on('click', this.copyToClipboard);
            
            // Tab switching
            $('.nav-tab').on('click', this.switchTab);
            
            // Form validation
            $('form').on('submit', this.validateForm);
            
            // Currency conversion toggle
            $('input[name="currency_conversion"]').on('change', this.toggleCurrencyOptions);
            
            // Category selection helpers
            $('.bd-select-all-categories').on('click', this.selectAllCategories);
            $('.bd-deselect-all-categories').on('click', this.deselectAllCategories);
            
            // Auto-save settings (debounced)
            let saveTimeout;
            $('.auto-save').on('change', function() {
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(BDProductFeed.autoSaveSettings, 2000);
            });
        },

        /**
         * Generate feed via AJAX
         */
        generateFeed: function(e) {
            e.preventDefault();
            
            if (!confirm(bdProductFeed.strings.confirm_regenerate)) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.text();
            
            // Show loading state
            $button.addClass('bd-loading').prop('disabled', true).text(bdProductFeed.strings.generating);
            
            // Show progress bar if available
            BDProductFeed.showProgress('feed-generation', 0);
            
            $.ajax({
                url: bdProductFeed.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bd_generate_feed',
                    nonce: bdProductFeed.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BDProductFeed.showNotice('success', response.data.message);
                        BDProductFeed.updateFeedStats(response.data);
                        BDProductFeed.showProgress('feed-generation', 100);
                    } else {
                        BDProductFeed.showNotice('error', response.data || bdProductFeed.strings.error);
                    }
                },
                error: function(xhr, status, error) {
                    BDProductFeed.showNotice('error', bdProductFeed.strings.error + ': ' + error);
                },
                complete: function() {
                    // Reset button state
                    $button.removeClass('bd-loading').prop('disabled', false).text(originalText);
                    BDProductFeed.hideProgress('feed-generation');
                }
            });
        },

        /**
         * Test feed via AJAX
         */
        testFeed: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const originalText = $button.text();
            
            // Show loading state
            $button.addClass('bd-loading').prop('disabled', true).text(bdProductFeed.strings.testing);
            
            $.ajax({
                url: bdProductFeed.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bd_test_feed',
                    nonce: bdProductFeed.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BDProductFeed.showNotice('success', response.data.message);
                        if (response.data.xml_preview) {
                            BDProductFeed.showXmlPreview(response.data.xml_preview);
                        }
                    } else {
                        BDProductFeed.showNotice('error', response.data || bdProductFeed.strings.error);
                    }
                },
                error: function(xhr, status, error) {
                    BDProductFeed.showNotice('error', bdProductFeed.strings.error + ': ' + error);
                },
                complete: function() {
                    // Reset button state
                    $button.removeClass('bd-loading').prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Validate feed via AJAX
         */
        validateFeed: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const originalText = $button.text();
            
            // Show loading state
            $button.addClass('bd-loading').prop('disabled', true).text(bdProductFeed.strings.validating);
            
            $.ajax({
                url: bdProductFeed.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bd_validate_feed',
                    nonce: bdProductFeed.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BDProductFeed.showValidationResults(response.data);
                    } else {
                        BDProductFeed.showNotice('error', response.data || bdProductFeed.strings.error);
                    }
                },
                error: function(xhr, status, error) {
                    BDProductFeed.showNotice('error', bdProductFeed.strings.error + ': ' + error);
                },
                complete: function() {
                    // Reset button state
                    $button.removeClass('bd-loading').prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Show notification message
         */
        showNotice: function(type, message) {
            const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Remove existing notices
            $('.notice').fadeOut(300, function() {
                $(this).remove();
            });
            
            // Add new notice
            $('.bd-admin-header').after($notice);
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Scroll to notice
            $('html, body').animate({
                scrollTop: $notice.offset().top - 50
            }, 300);
        },

        /**
         * Update feed statistics in dashboard
         */
        updateFeedStats: function(data) {
            if (data.product_count) {
                $('.bd-status-item').each(function() {
                    const $item = $(this);
                    const label = $item.find('strong').text();
                    
                    if (label.includes('Produkter i feed')) {
                        $item.find('span').text(data.product_count.toLocaleString());
                    } else if (label.includes('Sist oppdatert')) {
                        $item.find('span').text('Akkurat nå');
                    }
                });
            }
            
            // Update feed URL if available
            if (data.feeds && data.feeds.length > 0) {
                const feedUrl = data.feeds[0].url;
                $('.bd-info-box code').text(feedUrl);
                $('.bd-copy-button').data('text', feedUrl);
            }
        },

        /**
         * Show validation results
         */
        showValidationResults: function(data) {
            let html = '<div class="bd-validation-results">';
            
            if (data.valid) {
                html += '<div class="bd-validation-success"><strong>✅ Feed validering bestått!</strong></div>';
            } else {
                html += '<div class="bd-validation-error"><strong>❌ Feed validering feilet</strong></div>';
            }
            
            if (data.errors && data.errors.length > 0) {
                html += '<h4>Feil:</h4>';
                data.errors.forEach(function(error) {
                    html += '<div class="bd-validation-error">' + error + '</div>';
                });
            }
            
            if (data.warnings && data.warnings.length > 0) {
                html += '<h4>Advarsler:</h4>';
                data.warnings.forEach(function(warning) {
                    html += '<div class="bd-validation-warning">' + warning + '</div>';
                });
            }
            
            html += '</div>';
            
            // Show results in modal or dedicated area
            this.showModal('Validering Resultater', html);
        },

        /**
         * Show XML preview
         */
        showXmlPreview: function(xmlContent) {
            const html = '<pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto; max-height: 400px;">' + 
                        this.escapeHtml(xmlContent) + '</pre>';
            this.showModal('XML Forhåndsvisning', html);
        },

        /**
         * Show modal dialog
         */
        showModal: function(title, content) {
            const modal = $(`
                <div class="bd-modal-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100000; display: flex; align-items: center; justify-content: center;">
                    <div class="bd-modal" style="background: white; border-radius: 8px; padding: 20px; max-width: 80%; max-height: 80%; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
                        <div class="bd-modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                            <h3 style="margin: 0;">${title}</h3>
                            <button class="bd-modal-close" style="background: none; border: none; font-size: 20px; cursor: pointer; padding: 5px;">&times;</button>
                        </div>
                        <div class="bd-modal-content">${content}</div>
                    </div>
                </div>
            `);
            
            $('body').append(modal);
            
            // Close modal handlers
            modal.find('.bd-modal-close').on('click', function() {
                modal.fadeOut(200, function() {
                    modal.remove();
                });
            });
            
            modal.on('click', function(e) {
                if (e.target === this) {
                    modal.fadeOut(200, function() {
                        modal.remove();
                    });
                }
            });
            
            // ESC key to close
            $(document).on('keyup.bdmodal', function(e) {
                if (e.keyCode === 27) {
                    modal.fadeOut(200, function() {
                        modal.remove();
                    });
                    $(document).off('keyup.bdmodal');
                }
            });
        },

        /**
         * Copy text to clipboard
         */
        copyToClipboard: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const text = $button.data('text') || $button.prev('code').text();
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    BDProductFeed.showNotice('success', 'Kopiert til utklippstavle!');
                });
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                BDProductFeed.showNotice('success', 'Kopiert til utklippstavle!');
            }
        },

        /**
         * Switch tabs
         */
        switchTab: function(e) {
            e.preventDefault();
            
            const $tab = $(this);
            const tabId = $tab.attr('href').split('tab=')[1];
            
            // Update URL without page reload
            if (history.pushState) {
                const newUrl = window.location.href.split('&tab=')[0] + '&tab=' + tabId;
                history.pushState(null, null, newUrl);
            }
            
            // Update tab appearance
            $('.nav-tab').removeClass('nav-tab-active');
            $tab.addClass('nav-tab-active');
            
            // Show loading state briefly for smooth transition
            $('.tab-content').addClass('bd-loading');
            
            setTimeout(function() {
                window.location.reload();
            }, 100);
        },

        /**
         * Toggle currency conversion options
         */
        toggleCurrencyOptions: function() {
            const $checkbox = $(this);
            const $currencyOptions = $('input[name="target_currencies[]"]').closest('tr');
            
            if ($checkbox.is(':checked')) {
                $currencyOptions.slideDown(300);
            } else {
                $currencyOptions.slideUp(300);
            }
        },

        /**
         * Select all categories
         */
        selectAllCategories: function(e) {
            e.preventDefault();
            $(this).closest('td').find('input[type="checkbox"]').prop('checked', true);
        },

        /**
         * Deselect all categories
         */
        deselectAllCategories: function(e) {
            e.preventDefault();
            $(this).closest('td').find('input[type="checkbox"]').prop('checked', false);
        },

        /**
         * Validate form before submission
         */
        validateForm: function(e) {
            const $form = $(this);
            let isValid = true;
            
            // Check required fields
            $form.find('[required]').each(function() {
                const $field = $(this);
                if (!$field.val().trim()) {
                    $field.addClass('error');
                    isValid = false;
                } else {
                    $field.removeClass('error');
                }
            });
            
            // Check email fields
            $form.find('input[type="email"]').each(function() {
                const $field = $(this);
                const email = $field.val().trim();
                if (email && !BDProductFeed.isValidEmail(email)) {
                    $field.addClass('error');
                    isValid = false;
                } else {
                    $field.removeClass('error');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                BDProductFeed.showNotice('error', 'Vennligst fyll ut alle påkrevde felt korrekt.');
            }
            
            return isValid;
        },

        /**
         * Auto-save settings
         */
        autoSaveSettings: function() {
            const $form = $('form').first();
            const formData = $form.serialize() + '&action=bd_auto_save_settings&nonce=' + bdProductFeed.nonce;
            
            $.ajax({
                url: bdProductFeed.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        $('.auto-save-indicator').text('✓ Lagret automatisk').fadeIn().delay(2000).fadeOut();
                    }
                }
            });
        },

        /**
         * Initialize tooltips
         */
        initTooltips: function() {
            $('[data-tooltip]').hover(
                function() {
                    const tooltip = $(this).data('tooltip');
                    const $tooltip = $('<div class="bd-tooltip">' + tooltip + '</div>');
                    $('body').append($tooltip);
                    
                    const offset = $(this).offset();
                    $tooltip.css({
                        position: 'absolute',
                        top: offset.top - $tooltip.outerHeight() - 5,
                        left: offset.left + ($(this).outerWidth() / 2) - ($tooltip.outerWidth() / 2),
                        background: '#333',
                        color: 'white',
                        padding: '5px 10px',
                        borderRadius: '4px',
                        fontSize: '12px',
                        zIndex: 1000,
                        whiteSpace: 'nowrap'
                    }).fadeIn(200);
                },
                function() {
                    $('.bd-tooltip').remove();
                }
            );
        },

        /**
         * Initialize progress bars
         */
        initProgressBars: function() {
            $('.bd-progress').each(function() {
                const $progress = $(this);
                const value = $progress.data('value') || 0;
                $progress.find('.bd-progress-bar').css('width', value + '%');
            });
        },

        /**
         * Show progress bar
         */
        showProgress: function(id, percentage) {
            let $progress = $('#bd-progress-' + id);
            if ($progress.length === 0) {
                $progress = $('<div id="bd-progress-' + id + '" class="bd-progress"><div class="bd-progress-bar"></div></div>');
                $('.bd-admin-header').after($progress);
            }
            
            $progress.find('.bd-progress-bar').css('width', percentage + '%');
            
            if (percentage >= 100) {
                setTimeout(function() {
                    $progress.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 1000);
            }
        },

        /**
         * Hide progress bar
         */
        hideProgress: function(id) {
            $('#bd-progress-' + id).fadeOut(300, function() {
                $(this).remove();
            });
        },

        /**
         * Check feed status periodically
         */
        checkFeedStatus: function() {
            // Check every 30 seconds if on dashboard
            if (window.location.href.includes('tab=dashboard') || !window.location.href.includes('tab=')) {
                setInterval(function() {
                    $.ajax({
                        url: bdProductFeed.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'bd_get_feed_status',
                            nonce: bdProductFeed.nonce
                        },
                        success: function(response) {
                            if (response.success && response.data.last_modified) {
                                // Update last modified time
                                $('.bd-status-item').each(function() {
                                    const $item = $(this);
                                    const label = $item.find('strong').text();
                                    
                                    if (label.includes('Sist oppdatert')) {
                                        const timeAgo = BDProductFeed.timeAgo(response.data.last_modified);
                                        $item.find('span').text(timeAgo);
                                    }
                                });
                            }
                        }
                    });
                }, 30000);
            }
        },

        /**
         * Utility functions
         */
        isValidEmail: function(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        },

        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        },

        timeAgo: function(timestamp) {
            const now = Math.floor(Date.now() / 1000);
            const diff = now - timestamp;
            
            if (diff < 60) return 'Akkurat nå';
            if (diff < 3600) return Math.floor(diff / 60) + ' minutter siden';
            if (diff < 86400) return Math.floor(diff / 3600) + ' timer siden';
            return Math.floor(diff / 86400) + ' dager siden';
        }
    };

})(jQuery);