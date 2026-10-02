
document.addEventListener('DOMContentLoaded', function () {

    const uploadButton = document.getElementById(
        'wewc-upload-avatar'
    );

    const removeButton = document.getElementById(
        'wewc-remove-avatar'
    );

    const avatarInput = document.getElementById(
        'wewc_agent_avatar'
    );

    const avatarPreview = document.getElementById(
        'wewc-agent-avatar-preview'
    );

    if (
        !uploadButton ||
        !removeButton ||
        !avatarInput ||
        !avatarPreview
    ) {
        return;
    }

    let mediaFrame;

    // Open WordPress Media Library
    uploadButton.addEventListener('click', function () {

        if (mediaFrame) {
            mediaFrame.open();
            return;
        }

        mediaFrame = wp.media({

            title: 'Select Agent Profile Photo',

            button: {
                text: 'Use This Photo'
            },

            library: {
                type: 'image'
            },

            multiple: false

        });

        // Image selection
        mediaFrame.on('select', function () {

            const attachment = mediaFrame
                .state()
                .get('selection')
                .first()
                .toJSON();

            // Save attachment ID
            avatarInput.value = attachment.id;

            // Get thumbnail URL
            const imageUrl =
                attachment.sizes &&
                attachment.sizes.thumbnail
                    ? attachment.sizes.thumbnail.url
                    : attachment.url;

            // Update preview
            avatarPreview.src = imageUrl;

            avatarPreview.style.display = 'block';

            removeButton.style.display = 'inline-block';

        });

        mediaFrame.open();

    });


    // Remove selected image
    removeButton.addEventListener('click', function () {

        avatarInput.value = '0';

        avatarPreview.removeAttribute('src');

        avatarPreview.style.display = 'none';

        removeButton.style.display = 'none';

    });

});