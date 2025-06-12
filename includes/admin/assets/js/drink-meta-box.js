jQuery(document).ready(function ($) {
    $('.upload-image-button').on('click', function (e) {
        e.preventDefault();

        let button = $(this),
            targetInput = $(button.data('target')),
            targetPreview = $(button.data('target') + '_preview');

        const mediaUploader = wp.media({
            title: 'Sélectionnez une image',
            button: { text: 'Utiliser cette image' },
            multiple: false
        }).on('select', function () {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            targetInput.val(attachment.id);
            targetPreview.html('<img src="' + attachment.url + '" style="max-width:100%; height:auto;">');
        }).open();
    });
});
