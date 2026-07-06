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
            this.initSortableTopics();
            this.initUnsavedWarning();
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

            // News featured image selector
            $(document).on('click', '#aicg-select-news-featured', this.selectNewsFeatured);
            $(document).on('click', '#aicg-remove-news-featured', this.removeNewsFeatured);

            // Reference style preview
            $(document).on('change', '#aicg_reference_style', this.updateReferencePreview);

            // Reference color picker sync
            $(document).on('input change', '#aicg_reference_color', this.syncColorToText);
            $(document).on('input change', '#aicg_reference_color_text', this.syncTextToColor);

            // Reference size slider
            $(document).on('input change', '#aicg_reference_size', this.updateReferenceSizeDisplay);

            // Update existing post toggle
            $(document).on('change', '#aicg_news_update_existing', this.toggleExistingPostSelector);

            // Reset system prompt
            $(document).on('click', '#aicg-reset-system-prompt', this.resetSystemPrompt);

            // Reset image prompts
            $(document).on('click', '#aicg-reset-article-image-prompt', this.resetArticleImagePrompt);
            $(document).on('click', '#aicg-reset-news-image-prompt', this.resetNewsImagePrompt);

            // News sources management
            $(document).on('click', '#aicg-add-news-source', this.addNewsSource);
            $(document).on('click', '.aicg-remove-source', this.removeNewsSource);

            // Reset search template
            $(document).on('click', '#aicg-reset-search-template', this.resetSearchTemplate);

            // Schedule frequency change - show/hide time hint
            $(document).on('change', '#aicg_schedule_news_frequency', this.toggleScheduleTimeHint);

            // Toggle image sources visibility
            $(document).on('change', '#aicg_news_generate_image', this.toggleImageSources);

            // Cargar modelos del proveedor seleccionado
            $(document).on('click', '#aicg-load-models', this.loadModels);
        },

        initUnsavedWarning: function() {
            const form = $('.aicg-settings-wrap form');
            if (!form.length) {
                return;
            }
            let initialState = form.serialize();

            $(window).on('beforeunload', function() {
                if (form.serialize() !== initialState) {
                    return aicgAdmin.strings.unsavedChanges;
                }
            });

            // No advertir al enviar el formulario (guardar)
            form.on('submit', function() {
                $(window).off('beforeunload');
            });
        },

        loadModels: function(e) {
            e.preventDefault();
            const btn = $(this);
            const provider = $('#aicg_text_provider').val();
            const resultSpan = $('#aicg-load-models-result');

            btn.prop('disabled', true);
            resultSpan.removeClass('success error').text(aicgAdmin.strings.loadingModels);

            $.ajax({
                url: aicgAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aicg_fetch_models',
                    provider: provider,
                    nonce: aicgAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.models) {
                        const datalist = $('#aicg_default_model_list').empty();
                        response.data.models.forEach(function(model) {
                            $('<option>').attr('value', model.id).text(model.name).appendTo(datalist);
                        });
                        resultSpan.addClass('success').text(
                            '✓ ' + response.data.models.length + ' ' + aicgAdmin.strings.modelsLoaded
                        );
                    } else {
                        resultSpan.addClass('error').text('✗ ' + (response.data || aicgAdmin.strings.error));
                    }
                },
                error: function() {
                    resultSpan.addClass('error').text('✗ ' + aicgAdmin.strings.error);
                },
                complete: function() {
                    btn.prop('disabled', false);
                }
            });
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

        initSortableTopics: function() {
            const container = $('#aicg-news-topics-container');
            if (container.length && typeof $.fn.sortable !== 'undefined') {
                container.sortable({
                    handle: '.aicg-drag-handle',
                    items: '.aicg-sortable-item',
                    axis: 'y',
                    cursor: 'move',
                    opacity: 0.7,
                    placeholder: 'aicg-sortable-placeholder',
                    update: function() {
                        SettingsPage.reindexTopics();
                    }
                });
            }
        },

        reindexTopics: function() {
            $('#aicg-news-topics-container .aicg-news-topic-row').each(function(index) {
                $(this).find('.aicg-topic-nombre').attr('name', `aicg_news_topics[${index}][nombre]`);
                $(this).find('.aicg-topic-imagen').attr('name', `aicg_news_topics[${index}][imagen]`);
            });
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
                    btn.prop('disabled', false).text(aicgAdmin.strings.testConnection);
                }
            });
        },

        addNewsTopic: function(e) {
            e.preventDefault();
            const container = $('#aicg-news-topics-container');
            const index = container.find('.aicg-news-topic-row').length;

            const row = `
                <div class="aicg-news-topic-row aicg-sortable-item">
                    <span class="aicg-drag-handle dashicons dashicons-menu" title="${aicgAdmin.strings.dragToReorder}"></span>
                    <input type="text"
                           name="aicg_news_topics[${index}][nombre]"
                           value=""
                           placeholder="${aicgAdmin.strings.topicName}"
                           class="regular-text aicg-topic-nombre">
                    <input type="url"
                           name="aicg_news_topics[${index}][imagen]"
                           value=""
                           placeholder="${aicgAdmin.strings.imageUrlOptional}"
                           class="regular-text aicg-topic-imagen">
                    <button type="button" class="button aicg-remove-topic">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            `;

            container.append(row);
            // Reinicializar sortable después de agregar nuevo elemento
            SettingsPage.initSortableTopics();
        },

        removeNewsTopic: function(e) {
            e.preventDefault();
            $(this).closest('.aicg-news-topic-row').remove();
            // Reindexar después de eliminar
            SettingsPage.reindexTopics();
        },

        selectWatermark: function(e) {
            e.preventDefault();

            const frame = wp.media({
                title: aicgAdmin.strings.selectWatermark,
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
        },

        selectNewsFeatured: function(e) {
            e.preventDefault();

            const frame = wp.media({
                title: 'Seleccionar Imagen Destacada para Noticias',
                button: { text: 'Usar esta imagen' },
                multiple: false,
                library: { type: 'image' }
            });

            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                $('#aicg_news_featured_image').val(attachment.id);
                $('#aicg-news-featured-preview').html(`<img src="${attachment.url}" alt="Featured">`);
                $('#aicg-remove-news-featured').show();
            });

            frame.open();
        },

        removeNewsFeatured: function(e) {
            e.preventDefault();
            $('#aicg_news_featured_image').val('');
            $('#aicg-news-featured-preview').empty();
            $(this).hide();
        },

        updateReferencePreview: function() {
            const style = $(this).val();

            // Hide all previews
            $('.aicg-reference-preview > div').hide();

            // Show selected preview
            $(`.aicg-preview-${style}`).show();
        },

        syncColorToText: function() {
            const color = $(this).val();
            $('#aicg_reference_color_text').val(color);
            SettingsPage.updatePreviewColors(color);
        },

        syncTextToColor: function() {
            let color = $(this).val();

            // Validate hex format
            if (/^#[0-9A-Fa-f]{6}$/.test(color) || /^#[0-9A-Fa-f]{3}$/.test(color)) {
                $('#aicg_reference_color').val(color);
                SettingsPage.updatePreviewColors(color);
            }
        },

        updatePreviewColors: function(color) {
            // Update circle and square backgrounds
            $('.aicg-preview-circle a, .aicg-preview-square a').css('background', color);

            // Update inline link color
            $('.aicg-preview-inline a').css('color', color);

            // Update badge colors (light background, dark text)
            const bgColor = SettingsPage.hexToRgba(color, 0.15);
            $('.aicg-preview-badge a').css({
                'background': bgColor,
                'color': color
            });
        },

        hexToRgba: function(hex, alpha) {
            // Remove #
            hex = hex.replace('#', '');

            // Expand short format
            if (hex.length === 3) {
                hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            }

            const r = parseInt(hex.substring(0, 2), 16);
            const g = parseInt(hex.substring(2, 4), 16);
            const b = parseInt(hex.substring(4, 6), 16);

            return `rgba(${r}, ${g}, ${b}, ${alpha})`;
        },

        toggleExistingPostSelector: function() {
            const checked = $(this).is(':checked');
            if (checked) {
                $('#aicg-existing-post-selector').slideDown();
            } else {
                $('#aicg-existing-post-selector').slideUp();
            }
        },

        toggleImageSources: function() {
            const checked = $(this).is(':checked');
            if (checked) {
                $('#aicg-image-sources-row').slideDown();
            } else {
                $('#aicg-image-sources-row').slideUp();
            }
        },

        resetSystemPrompt: function() {
            const defaultPrompt = $(this).data('default');
            $('#aicg_news_system_prompt').val(defaultPrompt);
        },

        resetArticleImagePrompt: function() {
            const defaultPrompt = $(this).data('default');
            $('#aicg_article_image_prompt').val(defaultPrompt);
        },

        resetNewsImagePrompt: function() {
            const defaultPrompt = $(this).data('default');
            $('#aicg_news_image_prompt').val(defaultPrompt);
        },

        addNewsSource: function(e) {
            e.preventDefault();
            const container = $('#aicg-news-sources-container');
            const index = container.find('.aicg-news-source-row').length;

            const row = `
                <div class="aicg-news-source-row" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                    <label style="display: flex; align-items: center;">
                        <input type="checkbox"
                               name="aicg_news_sources[${index}][activo]"
                               value="1"
                               checked>
                    </label>
                    <input type="text"
                           name="aicg_news_sources[${index}][nombre]"
                           value=""
                           placeholder="${aicgAdmin.strings.sourceName}"
                           class="regular-text"
                           style="width: 200px;">
                    <input type="url"
                           name="aicg_news_sources[${index}][url]"
                           value=""
                           placeholder="${aicgAdmin.strings.rssFeedUrl}"
                           class="regular-text"
                           style="flex: 1;">
                    <button type="button" class="button aicg-remove-source">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            `;

            container.append(row);
        },

        removeNewsSource: function(e) {
            e.preventDefault();
            $(this).closest('.aicg-news-source-row').remove();
            // Reindexar fuentes
            SettingsPage.reindexSources();
        },

        reindexSources: function() {
            $('#aicg-news-sources-container .aicg-news-source-row').each(function(index) {
                $(this).find('input[name*="[activo]"]').attr('name', `aicg_news_sources[${index}][activo]`);
                $(this).find('input[name*="[nombre]"]').attr('name', `aicg_news_sources[${index}][nombre]`);
                $(this).find('input[name*="[url]"]').attr('name', `aicg_news_sources[${index}][url]`);
            });
        },

        resetSearchTemplate: function() {
            const defaultTemplate = $(this).data('default');
            $('#aicg_news_search_template').val(defaultTemplate);
        },

        updateReferenceSizeDisplay: function() {
            const value = $(this).val();
            $('#aicg_reference_size_value').text(value + 'px');
        },

        toggleScheduleTimeHint: function() {
            const frequency = $(this).val();
            const $hint = $('#aicg-schedule-time-hint');
            const $timeSelect = $('#aicg_schedule_news_time');

            if (frequency === 'hourly') {
                $hint.hide();
                $timeSelect.prop('disabled', true).css('opacity', '0.5');
            } else {
                $hint.show();
                $timeSelect.prop('disabled', false).css('opacity', '1');
            }
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

        // Validación cliente antes de enviar
        validate: function(form) {
            const min = parseInt(form.find('[name="min_words"]').val(), 10);
            const max = parseInt(form.find('[name="max_words"]').val(), 10);
            if (!isNaN(min) && !isNaN(max) && max < min) {
                return aicgAdmin.strings.error + ': máx. palabras < mín. palabras';
            }
            return null;
        },

        handleSubmit: function(e) {
            e.preventDefault();

            const form = $('#aicg-article-form');

            const validationError = this.validate(form);
            if (validationError) {
                window.alert(validationError);
                return;
            }

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

            this.updateProgress(aicgAdmin.strings.queued, 2);

            // Encolar el trabajo; la generación corre en segundo plano y se
            // sigue el progreso real por polling del estado del job.
            $.ajax({
                url: aicgAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success && response.data.job_id) {
                        AICGJob.poll(response.data.job_id, {
                            onProgress: function(state) {
                                self.updateProgress(state.message, state.percent);
                            },
                            onDone: function(state) {
                                self.updateProgress(state.message, 100);
                                setTimeout(function() { self.showSuccess(state.result); }, 300);
                            },
                            onError: function(message) {
                                self.showError(message);
                            }
                        });
                    } else {
                        self.showError(response.data || aicgAdmin.strings.error);
                    }
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

        updateProgress: function(text, percent) {
            $('#aicg-progress-fill').css('width', percent + '%');
            $('#aicg-status-text').text(text);

            // Iluminar los pasos visuales según el progreso real
            const steps = ['title', 'content', 'image', 'publish'];
            const current = percent >= 90 ? 4 : (percent >= 55 ? 3 : (percent >= 25 ? 2 : 1));
            steps.forEach(function(id, i) {
                const $step = $('#step-' + id);
                if (i < current - 1) {
                    $step.addClass('completed').removeClass('active');
                } else if (i === current - 1) {
                    $step.addClass('active').removeClass('completed');
                } else {
                    $step.removeClass('active completed');
                }
            });
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

            this.updateProgress(aicgAdmin.strings.queued, 2);

            $.ajax({
                url: aicgAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success && response.data.job_id) {
                        AICGJob.poll(response.data.job_id, {
                            onProgress: function(state) {
                                self.updateProgress(state.message, state.percent);
                            },
                            onDone: function(state) {
                                self.updateProgress(state.message, 100);
                                setTimeout(function() { self.showSuccess(state.result); }, 300);
                            },
                            onError: function(message) {
                                self.showError(message);
                            }
                        });
                    } else {
                        self.showError(response.data || aicgAdmin.strings.error);
                    }
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

        updateProgress: function(text, percent) {
            $('#aicg-news-progress-fill').css('width', percent + '%');
            $('#aicg-news-status-text').text(text);

            // Iluminar los pasos visuales según el progreso real
            const steps = ['headlines', 'topics', 'summary', 'publish'];
            const current = percent >= 90 ? 4 : (percent >= 55 ? 3 : (percent >= 15 ? 2 : 1));
            steps.forEach(function(id, i) {
                const $step = $('#news-step-' + id);
                if (i < current - 1) {
                    $step.addClass('completed').removeClass('active');
                } else if (i === current - 1) {
                    $step.addClass('active').removeClass('completed');
                } else {
                    $step.removeClass('active completed');
                }
            });
        },

        showSuccess: function(data) {
            $('#aicg-news-progress-area').hide();
            $('#aicg-news-result-area').show();

            $('#aicg-news-result-message').text(data.message);
            $('#aicg-view-news-post').attr('href', data.view_url);
            $('#aicg-edit-news-post').attr('href', data.edit_url);

            // Build detailed stats HTML
            let statsHtml = `
                <div style="margin-bottom: 15px;">
                    <p><strong>Total noticias procesadas:</strong> ${data.news_count}</p>
                    <p><strong>Tokens:</strong> ${data.tokens_used} | <strong>Costo:</strong> $${data.cost}</p>
                </div>
            `;

            // Show details per section if available
            if (data.topics_details && data.topics_details.length > 0) {
                statsHtml += `<div style="border-top: 1px solid #ddd; padding-top: 15px;">
                    <strong>Detalle por sección:</strong>
                    <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f5f5f5;">
                                <th style="padding: 8px; text-align: left; border-bottom: 1px solid #ddd;">Sección</th>
                                <th style="padding: 8px; text-align: center; border-bottom: 1px solid #ddd;">Fuentes</th>
                                <th style="padding: 8px; text-align: center; border-bottom: 1px solid #ddd;">Imágenes</th>
                                <th style="padding: 8px; text-align: left; border-bottom: 1px solid #ddd;">Estado</th>
                            </tr>
                        </thead>
                        <tbody>`;

                data.topics_details.forEach(function(topic) {
                    let statusIcon = '';
                    let statusText = '';
                    let rowStyle = '';

                    if (topic.status === 'success') {
                        statusIcon = '✓';
                        statusText = 'Procesado';
                        rowStyle = 'color: #155724;';
                    } else if (topic.status === 'no_news') {
                        statusIcon = '⚠';
                        statusText = 'Sin noticias';
                        rowStyle = 'color: #856404; background: #fff3cd;';
                    } else if (topic.status === 'error') {
                        statusIcon = '✗';
                        statusText = 'Error';
                        rowStyle = 'color: #721c24; background: #f8d7da;';
                    } else {
                        statusIcon = '○';
                        statusText = topic.message || 'Pendiente';
                        rowStyle = 'color: #666;';
                    }

                    let imagesInfo = '-';
                    if (topic.images_count > 0) {
                        let sourceLabel = '';
                        if (topic.images_source === 'og') {
                            sourceLabel = ' (de fuentes)';
                        } else if (topic.images_source === 'static') {
                            sourceLabel = ' (estática)';
                        } else if (topic.images_source === 'generated') {
                            sourceLabel = ' (generada)';
                        }
                        imagesInfo = topic.images_count + sourceLabel;
                    }

                    statsHtml += `
                        <tr style="${rowStyle}">
                            <td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>${AICGJob.escapeHtml(topic.name)}</strong></td>
                            <td style="padding: 8px; text-align: center; border-bottom: 1px solid #eee;">${parseInt(topic.news_count || 0, 10)}</td>
                            <td style="padding: 8px; text-align: center; border-bottom: 1px solid #eee;">${AICGJob.escapeHtml(imagesInfo)}</td>
                            <td style="padding: 8px; border-bottom: 1px solid #eee;">${statusIcon} ${AICGJob.escapeHtml(statusText)}</td>
                        </tr>`;
                });

                statsHtml += `</tbody></table></div>`;
            }

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
     * Polling del estado de un trabajo en segundo plano
     */
    const AICGJob = {
        // Intervalo de sondeo (ms) y nº máx. de intentos (~5 min a 3s)
        INTERVAL: 3000,
        MAX_ATTEMPTS: 100,

        escapeHtml: function(str) {
            return $('<div>').text(str == null ? '' : String(str)).html();
        },

        poll: function(jobId, callbacks) {
            let attempts = 0;

            const check = function() {
                attempts++;

                if (attempts > AICGJob.MAX_ATTEMPTS) {
                    callbacks.onError(aicgAdmin.strings.timeout);
                    return;
                }

                $.ajax({
                    url: aicgAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'aicg_job_status',
                        job_id: jobId,
                        nonce: aicgAdmin.nonce
                    },
                    success: function(response) {
                        if (!response.success) {
                            callbacks.onError((response && response.data) || aicgAdmin.strings.error);
                            return;
                        }

                        const state = response.data;

                        if (state.status === 'done') {
                            callbacks.onDone(state);
                        } else if (state.status === 'error') {
                            callbacks.onError(state.error || aicgAdmin.strings.error);
                        } else {
                            // queued o running: seguir sondeando
                            if (state.message) {
                                callbacks.onProgress(state);
                            }
                            setTimeout(check, AICGJob.INTERVAL);
                        }
                    },
                    error: function() {
                        // Error de red transitorio: reintentar hasta agotar intentos
                        setTimeout(check, AICGJob.INTERVAL);
                    }
                });
            };

            setTimeout(check, AICGJob.INTERVAL);
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
