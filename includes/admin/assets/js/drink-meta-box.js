jQuery(document).ready(function ($) {
    // Upload d'image via la médiathèque WordPress
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
            
            // Mettre à jour l'aperçu
            let previewHtml = '<img src="' + attachment.url + '" style="max-width:100%; height:auto;">';
            previewHtml += '<button class="button remove-image-button" data-target="' + button.data('target') + '">Supprimer</button>';
            targetPreview.html(previewHtml);
            
            // Réattacher l'événement au nouveau bouton
            attachRemoveEvent();
        }).open();
    });

    // Supprimer une image
    function attachRemoveEvent() {
        $('.remove-image-button').off('click').on('click', function(e) {
            e.preventDefault();
            let button = $(this),
                targetInput = $(button.data('target')),
                targetPreview = $(button.data('target') + '_preview');
            
            targetInput.val('');
            targetPreview.html('<p class="description">Aucune image sélectionnée</p>');
        });
    }
    attachRemoveEvent();

    // Télécharger une image depuis une URL
    $('.download-image-button').on('click', function(e) {
        e.preventDefault();
        
        let button = $(this),
            url = button.data('url'),
            postId = button.data('post-id'),
            metaKey = button.data('meta-key'),
            originalText = button.text();
        
        // Désactiver le bouton et afficher le statut
        button.prop('disabled', true).text(dci_meta_box.downloading);
        
        // Requête AJAX pour télécharger l'image
        $.ajax({
            url: dci_meta_box.ajax_url,
            type: 'POST',
            data: {
                action: 'dci_download_single_image',
                nonce: dci_meta_box.nonce,
                image_url: url,
                post_id: postId,
                meta_key: metaKey
            },
            success: function(response) {
                if (response.success) {
                    // Recharger la page pour afficher la nouvelle image
                    location.reload();
                } else {
                    alert(dci_meta_box.download_error + ': ' + response.data.message);
                    button.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert(dci_meta_box.download_error);
                button.prop('disabled', false).text(originalText);
            }
        });
    });
});