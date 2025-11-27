/**
 * AI Content Generator - Admin Scripts
 *
 * @package AI_Content_Generator
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Settings Page
     */
    const SettingsPage = {
        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initModelSuggestions();
        },

        bindEvents: function() {
            // Tab navigation
            $(document).on('click', '.aicg-nav-tab', this.handleTabClick.bind(this));

            // Toggle API key visibility
            $(document).on('click', '.aicg-toggle-visibility', this.toggleVisibility);

            // Test connection
            $(document).on('click', '.aicg-test-connection', this.testConnection);

            // Add news topic
            $(document).on('click', '#aicg-add-news-topic', this.addNewsTopic);

            // Remove news topic
            $(document).on('click', '.aicg-remove-topic', this.removeNewsTopic);

            // Watermark image selector
            $(document).on('click', '#aicg-select-watermark', this.selectWatermark);
            $(document).on('click', '#aicg-remove-watermark', this.removeWatermark);
        },

        initTabs: function() {
            // Check for hash in URL
            const hash = window.location.hash;
            if (hash) {
                this.activateTab(hash);
            }
        },

        initModelSuggestions: function() {
            const providerSelect = $('#aicg_text_provider');
            if (providerSelect.length) {
                this.updateModelSuggestions(providerSelect.val());
                providerSelect.on('change', (e) => {
                    this.updateModelSuggestions($(e.target).val());
                });
            }
        },

        updateModelSuggestions: function(provider) {
            // Ocultar todas las sugerencias
            $('#aicg-model-suggestions p').hide();
            // Mostrar solo la del proveedor seleccionado
            $(`#aicg-model-suggestions p[data-provider="${provider}"]`).show();
        },

        handleTabClick: function(e) {
            e.preventDefault();
            const target = $(e.currentTarget).attr('href');
            this.activateTab(target);
            window.location.hash = target;
        },

        activateTab: function(target) {
            // Update navigation
            $('.aicg-nav-tab').removeClass('active');
            $(`.aicg-nav-tab[href="${target}"]`).addClass('active');

            // Update content
            $('.aicg-settings-section').removeClass('active');
            $(target).addClass('active');
        },

        toggleVisibility: function(e) {
            e.preventDefault();
            const targetId = $(this).data('target');
            const input = $(`#${targetId}`);
            const icon = $(this).find('.dashicons');

            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            } else {
                input.attr('type', 'password');
                icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            }
        },

        testConnection: function(e) {
            e.preventDefault();
            const btn = $(this);
            const provider = btn.data('provider');
            const resultSpan = $(`#test-result-${provider}`);

            btn.prop('disabled', true).text(aicgAdmin.strings.testingConnection);
            resultSpan.removeClass('success error').text('');

            $.ajax({
                url: aicgAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aicg_test_provider',
                    provider: provider,
                    nonce: aicgAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        resultSpan.addClass('success').text('✓ ' + aicgAdmin.strings.connectionSuccess);
                    } else {
                        resultSpan.addClass('error').text('✗ ' + response.data);
                    }
                },
                error: function() {
                    resultSpan.addClass('error').text('✗ ' + aicgAdmin.strings.connectionError);
                },
                complete: function() {
                    btn.prop('disabled', false).text(aicgAdmin.strings.testingConnection.replace('...', ''));
                }
            });
        },

        addNewsTopic: function(e) {
            e.preventDefault();
            const container = $('#aicg-news-topics-container');
            const index = container.find('.aicg-news-topic-row').length;

            const row = `
                <div class="aicg-news-topic-row">
                    <input type="text"
                           name="aicg_news_topics[${index}][nombre]"
                           value=""
                           placeholder="Nombre del tema"
                           class="regular-text">
                    <input type="url"
                           name="aicg_news_topics[${index}][imagen]"
                           value=""
                           placeholder="URL de imagen (opcional)"
                           class="regular-text">
                    <button type="button" class="button aicg-remove-topic">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            `;

            container.append(row);
        },

        removeNewsTopic: function(e) {
            e.preventDefault();
            $(this).closest('.aicg-news-topic-row').remove();
        },

        selectWatermark: function(e) {
            e.preventDefault();

            const frame = wp.media({
                title: 'Seleccionar Marca de Agua',
                button: { text: 'Usar esta imagen' },
                multiple: false,
                library: { type: 'image' }
            });

            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                $('#aicg_watermark_image').val(attachment.id);
                $('#aicg-watermark-preview').html(`<img src="${attachment.url}" alt="Watermark">`);
                $('#aicg-remove-watermark').show();
            });

            frame.open();
        },

        removeWatermark: function(e) {
            e.preventDefault();
            $('#aicg_watermark_image').val('');
            $('#aicg-watermark-preview').empty();
            $(this).hide();
        }
    };

    /**
     * Article Generator
     */
    const ArticleGenerator = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Topic type change
            $(document).on('change', '#aicg_topic_type', this.handleTopicTypeChange);

            // Temperature slider
            $(document).on('input', '#aicg_temperature', this.updateTemperatureLabel);

            // Form submit
            $(document).on('submit', '#aicg-article-form', this.handleSubmit.bind(this));

            // Generate another
            $(document).on('click', '#aicg-generate-another, #aicg-try-again', this.resetForm.bind(this));
        },

        handleTopicTypeChange: function() {
            const type = $(this).val();

            $('#aicg_preset_row').toggle(type === 'preset');
            $('#aicg_custom_row').toggle(type === 'custom');
        },

        updateTemperatureLabel: function() {
            $('#aicg_temperature_value').text($(this).val());
        },

        handleSubmit: function(e) {
            e.preventDefault();

            const form = $('#aicg-article-form');
            const formData = new FormData(form[0]);
            formData.append('action', 'aicg_generate_article');
            formData.append('nonce', aicgAdmin.nonce);

            // Show progress
            form.hide();
            $('#aicg-progress-area').show();
            $('#aicg-result-area, #aicg-error-area').hide();

            // Reset progress
            this.resetProgress();

            // Start generation
            this.generateArticle(formData);
        },

        generateArticle: function(formData) {
            const self = this;

            // Simulate progress steps
            this.updateProgress(1, aicgAdmin.strings.step1, 10);

            setTimeout(() => this.updateProgress(2, aicgAdmin.strings.step2, 30), 1000);
            setTimeout(() => this.updateProgress(3, aicgAdmin.strings.step3, 60), 3000);

            $.ajax({
                url: aicgAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    self.updateProgress(4, aicgAdmin.strings.step4, 90);

                    setTimeout(function() {
                        if (response.success) {
                            self.showSuccess(response.data);
                        } else {
                            self.showError(response.data);
                        }
                    }, 500);
                },
                error: function(xhr, status, error) {
                    self.showError(error || aicgAdmin.strings.error);
                }
            });
        },

        resetProgress: function() {
            $('.aicg-step').removeClass('active completed');
            $('#aicg-progress-fill').css('width', '0%');
            $('#aicg-status-text').text(aicgAdmin.strings.generating);
        },

        updateProgress: function(step, text, percent) {
            // Mark previous steps as completed
            for (let i = 1; i < step; i++) {
                $(`#step-${['title', 'content', 'image', 'publish'][i - 1]}`).addClass('completed').removeClass('active');
            }

            // Mark current step as active
            const stepId = ['title', 'content', 'image', 'publish'][step - 1];
            $(`#step-${stepId}`).addClass('active');

            // Update progress bar
            $('#aicg-progress-fill').css('width', percent + '%');
            $('#aicg-status-text').text(text);
        },

        showSuccess: function(data) {
            $('#aicg-progress-area').hide();
            $('#aicg-result-area').show();

            $('#aicg-result-message').text(data.message);
            $('#aicg-view-post').attr('href', data.view_url);
            $('#aicg-edit-post').attr('href', data.edit_url);
        },

        showError: function(message) {
            $('#aicg-progress-area').hide();
            $('#aicg-error-area').show();
            $('#aicg-error-message').text(message);
        },

        resetForm: function() {
            $('#aicg-result-area, #aicg-error-area, #aicg-progress-area').hide();
            $('#aicg-article-form').show()[0].reset();
            $('#aicg_topic_type').trigger('change');
        }
    };

    /**
     * News Generator
     */
    const NewsGenerator = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Select all topics
            $(document).on('change', '#aicg_select_all_topics', this.handleSelectAll);

            // Form submit
            $(document).on('submit', '#aicg-news-form', this.handleSubmit.bind(this));

            // Generate another
            $(document).on('click', '#aicg-generate-another-news, #aicg-news-try-again', this.resetForm.bind(this));
        },

        handleSelectAll: function() {
            const checked = $(this).is(':checked');
            $('.aicg-topic-checkbox').prop('checked', checked);
        },

        handleSubmit: function(e) {
            e.preventDefault();

            const form = $('#aicg-news-form');
            const formData = new FormData(form[0]);
            formData.append('action', 'aicg_generate_news');
            formData.append('nonce', aicgAdmin.nonce);

            // Show progress
            form.hide();
            $('#aicg-news-progress-area').show();
            $('#aicg-news-result-area, #aicg-news-error-area').hide();

            this.resetProgress();
            this.generateNews(formData);
        },

        generateNews: function(formData) {
            const self = this;

            // Simulate progress
            this.updateProgress(1, 'Obteniendo titulares...', 10);

            setTimeout(() => this.updateProgress(2, 'Buscando noticias por tema...', 30), 1500);
            setTimeout(() => this.updateProgress(3, 'Generando resúmenes...', 60), 4000);

            $.ajax({
                url: aicgAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    self.updateProgress(4, 'Publicando...', 90);

                    setTimeout(function() {
                        if (response.success) {
                            self.showSuccess(response.data);
                        } else {
                            self.showError(response.data);
                        }
                    }, 500);
                },
                error: function(xhr, status, error) {
                    self.showError(error || aicgAdmin.strings.error);
                }
            });
        },

        resetProgress: function() {
            $('[id^="news-step-"]').removeClass('active completed');
            $('#aicg-news-progress-fill').css('width', '0%');
            $('#aicg-news-status-text').text(aicgAdmin.strings.generating);
        },

        updateProgress: function(step, text, percent) {
            const steps = ['headlines', 'topics', 'summary', 'publish'];

            for (let i = 0; i < step - 1; i++) {
                $(`#news-step-${steps[i]}`).addClass('completed').removeClass('active');
            }

            $(`#news-step-${steps[step - 1]}`).addClass('active');
            $('#aicg-news-progress-fill').css('width', percent + '%');
            $('#aicg-news-status-text').text(text);
        },

        showSuccess: function(data) {
            $('#aicg-news-progress-area').hide();
            $('#aicg-news-result-area').show();

            $('#aicg-news-result-message').text(data.message);
            $('#aicg-view-news-post').attr('href', data.view_url);
            $('#aicg-edit-news-post').attr('href', data.edit_url);

            // Show stats
            const statsHtml = `
                <p><strong>Noticias procesadas:</strong> ${data.news_count}</p>
                <p><strong>Temas:</strong> ${data.topics_processed.join(', ')}</p>
                <p><strong>Tokens:</strong> ${data.tokens_used} | <strong>Costo:</strong> $${data.cost}</p>
            `;
            $('#aicg-news-stats').html(statsHtml);
        },

        showError: function(message) {
            $('#aicg-news-progress-area').hide();
            $('#aicg-news-error-area').show();
            $('#aicg-news-error-message').text(message);
        },

        resetForm: function() {
            $('#aicg-news-result-area, #aicg-news-error-area, #aicg-news-progress-area').hide();
            $('#aicg-news-form').show()[0].reset();
            $('#aicg_select_all_topics').prop('checked', true).trigger('change');
        }
    };

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Initialize based on current page
        if ($('.aicg-settings-wrap').length) {
            SettingsPage.init();
        }

        if ($('.aicg-generate-article').length) {
            ArticleGenerator.init();
            // Trigger topic type change to set initial state
            $('#aicg_topic_type').trigger('change');
        }

        if ($('.aicg-generate-news').length) {
            NewsGenerator.init();
        }
    });

})(jQuery);
