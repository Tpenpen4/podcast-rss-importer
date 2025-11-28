(function($){
    'use strict';

    $(function(){
        const dialog = $('#feed-form-dialog');
        const formPrefix = 'pod_form';
        const feedList = $('#feed-list');
        const noFeedsRow = $('#no-feeds-row');

        function showNotification(message, type = 'success') {
            const notice = $('<div class="pod-importer-notice"></div>').text(message);
            if (type !== 'success') {
                notice.addClass('error');
            }
            $('#pod-importer-notifier-container').append(notice);
            setTimeout(() => notice.addClass('show'), 10);
            setTimeout(() => {
                notice.removeClass('show');
                setTimeout(() => notice.remove(), 300);
            }, 4000);
        }

        function toggleSpinner(button, show) {
            if (show) {
                button.prop('disabled', true).append(podImporter.spinner);
            } else {
                button.prop('disabled', false).find('.spinner').remove();
            }
        }

        function toggleSchedule() {
            const type = $('#' + formPrefix + '-schedule-type').val();
            $('.schedule-box').hide();
            if (type === 'interval') $('#' + formPrefix + '-interval-box').show();
            else if (type === 'time') $('#' + formPrefix + '-time-box').show();
            else if (type === 'weekly') $('#' + formPrefix + '-weekly-box').show();
        }

        function openFormModal(id) {
            const title = (id === null) ? podImporter.text.add_feed : podImporter.text.edit_feed;
            
            $.post(podImporter.ajax_url, {
                action: 'pod_importer_render_form',
                nonce: podImporter.nonce,
                id: id,
            }).done(function(response){
                if (response.success) {
                    dialog.html(response.data.html).dialog({
                        title: title,
                        modal: true,
                        width: 680,
                        maxWidth: '95%',
                        buttons: [
                            {
                                text: podImporter.text.save,
                                click: function() {
                                    saveForm($(this));
                                }
                            },
                            {
                                text: podImporter.text.close,
                                click: function() {
                                    $(this).dialog("close");
                                }
                            }
                        ],
                        open: function() {
                            toggleSchedule();
                        },
                        close: function() {
                            $(this).dialog('destroy');
                        }
                    });
                } else {
                    showNotification(podImporter.text.error, 'error');
                }
            });
        }

        function saveForm(dialogInstance) {
            const form = $('#pod-importer-form');
            const saveButton = dialogInstance.closest('.ui-dialog').find('.ui-dialog-buttonpane button:first');
            const data = form.serialize();
            
            toggleSpinner(saveButton, true);

            $.post(podImporter.ajax_url, 'action=pod_importer_save_feed&nonce=' + podImporter.nonce + '&' + data)
            .done(function(response){
                if (response.success) {
                    const newRow = $(response.data.rowHtml);
                    const existingRow = feedList.find('tr[data-id="' + response.data.id + '"]');
                    if (existingRow.length) {
                        existingRow.replaceWith(newRow);
                    } else {
                        feedList.append(newRow);
                    }
                    newRow.css('background-color', '#e7f7e9').animate({ backgroundColor: '#fff' }, 1500);
                    dialogInstance.dialog("close");
                    showNotification(response.data.message);
                    noFeedsRow.hide();
                } else {
                    showNotification(podImporter.text.error, 'error');
                }
            }).fail(function(){
                showNotification(podImporter.text.error, 'error');
            }).always(function(){
                toggleSpinner(saveButton, false);
            });
        }

        $('#add-new-feed').on('click', function(e){
            e.preventDefault();
            openFormModal(null);
        });

        feedList.on('click', '.edit-feed-btn', function(e){
            e.preventDefault();
            const id = $(this).closest('tr').data('id');
            openFormModal(id);
        });

        feedList.on('click', '.remove-feed-btn', function(e){
            e.preventDefault();
            if (!confirm(podImporter.text.confirm_delete)) return;

            const button = $(this);
            const row = button.closest('tr');
            const id = row.data('id');
            
            toggleSpinner(button, true);

            $.post(podImporter.ajax_url, {
                action: 'pod_importer_delete_feed',
                nonce: podImporter.nonce,
                id: id
            }).done(function(response){
                if (response.success) {
                    row.fadeOut(300, function(){ 
                        $(this).remove();
                        if (feedList.find('tr').length === 0) {
                            noFeedsRow.show();
                        }
                    });
                    showNotification(response.data.message);
                } else {
                    showNotification(podImporter.text.error, 'error');
                    toggleSpinner(button, false);
                }
            }).fail(function(){
                showNotification(podImporter.text.error, 'error');
                toggleSpinner(button, false);
            });
        });

        feedList.on('click', '.fetch-now-btn', function(e){
            e.preventDefault();
            const button = $(this);
            const id = button.closest('tr').data('id');

            toggleSpinner(button, true);

            $.post(podImporter.ajax_url, {
                action: 'pod_importer_fetch_now',
                nonce: podImporter.nonce,
                id: id
            }).done(function(response){
                if (response.success) {
                    showNotification(response.data.message);
                } else {
                    showNotification(podImporter.text.error, 'error');
                }
            }).fail(function(){
                showNotification(podImporter.text.error, 'error');
            }).always(function(){
                toggleSpinner(button, false);
            });
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest('.dropdown').length) {
                $('.dropdown-menu').removeClass('show');
            }
        });

        feedList.on('click', '.dropdown-toggle', function(e) {
            e.stopPropagation();
            const menu = $(this).siblings('.dropdown-menu');
            $('.dropdown-menu').not(menu).removeClass('show');
            menu.toggleClass('show');
        });

        dialog.on('change', '#' + formPrefix + '-schedule-type', toggleSchedule);

        dialog.on('click', '.add-time', function(e){
            e.preventDefault();
            const list = $(this).siblings('.time-list');
            const name = list.find('input[type=time]').first().attr('name');
            const newItem = $('<div class="time-item"><input type="time" name="' + name + '" value="07:00"><button type="button" class="button button-small remove-time">&times;</button></div>');
            list.append(newItem);
        });

        dialog.on('click', '.remove-time', function(e){
            e.preventDefault();
            const timeItem = $(this).closest('.time-item');
            if (timeItem.siblings().length > 0) {
                timeItem.remove();
            } else {
                timeItem.find('input[type=time]').val('07:00');
            }
        });
    });

})(jQuery);