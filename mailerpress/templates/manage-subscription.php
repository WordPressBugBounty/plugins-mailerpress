<?php

defined('ABSPATH') || exit;
global $wpdb;
$accessToken = wp_unslash($_GET['cid'] ?? '');

$contact = null;
$contactId = null;

if ($accessToken) {
    $contactTable = $wpdb->prefix . 'mailerpress_contact';
    $contact = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM {$contactTable}
        WHERE access_token = %s
        LIMIT 1
    ", $accessToken));

    if ($contact) {
        $contactId = $contact->contact_id;
    }
}

$listTable = $wpdb->prefix . 'mailerpress_lists';
$allLists = $wpdb->get_results("SELECT list_id, name FROM {$listTable}");

$subscribedListIds = [];
if ($contactId) {
    $contactListTable = $wpdb->prefix . 'mailerpress_contact_lists';
    $subscribedListIds = $wpdb->get_col($wpdb->prepare("
        SELECT list_id FROM {$contactListTable}
        WHERE contact_id = %d
    ", $contactId));
}

$isPreview = isset($_GET['mp_preview']) && wp_validate_boolean(sanitize_text_field(wp_unslash($_GET['mp_preview'])));
$disableListManagement = !empty($disableListManagement)
    || (isset($_GET['disable_list_management']) && wp_validate_boolean(sanitize_text_field(wp_unslash($_GET['disable_list_management']))));
?>

<form novalidate action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="post"
      class="mailerpress-manage-subscription">
    <p class="mailerpress_paragraph">
        <label><?php esc_html_e('Email*', 'mailerpress'); ?>
            <br><strong><?php echo esc_html($contact->email ?? ''); ?></strong></label>
        <?php if (!$disableListManagement) : ?>
            <span class="mailerpress-change-email-info">
                <?php esc_html_e('Uncheck lists to unsubscribe from them, then save your preferences.', 'mailerpress'); ?>
            </span>
        <?php endif; ?>
    </p>

    <div class="mailerpress-form-line">
        <label for="contact_first_name"><?php esc_html_e('First Name', 'mailerpress'); ?></label>
        <input id="contact_first_name" type="text" name="first_name"
               value="<?php echo esc_attr($contact->first_name ?? ''); ?>">
    </div>

    <div class="mailerpress-form-line">
        <label for="contact_last_name"><?php esc_html_e('Last Name', 'mailerpress'); ?></label>
        <input id="contact_last_name" type="text" name="last_name"
               value="<?php echo esc_attr($contact->last_name ?? ''); ?>">
    </div>

    <?php if (!$disableListManagement) : ?>
        <?php if (!empty($allLists)) : ?>
            <div class="mailerpress-form-line">
                <label><?php esc_html_e('Manage your subscriptions', 'mailerpress'); ?></label>
                <?php foreach ($allLists as $list) : ?>
                    <div style="margin-bottom: 5px;">
                        <label>
                            <input
                                    type="checkbox"
                                    name="subscribed_lists[]"
                                    value="<?php echo esc_attr($list->list_id); ?>"
                                    <?php checked(in_array($list->list_id, $subscribedListIds)); ?>
                            >
                            <?php echo esc_html($list->name); ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p><?php esc_html_e('No mailing lists available.', 'mailerpress'); ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$isPreview): ?>
        <div class="mailerpress-form-line">
            <input type="hidden" name="action" value="update_mailerpress_contact">
            <input type="hidden" name="mailerpress_cid" value="<?php echo esc_attr($accessToken ?? ''); ?>">
            <?php if ($disableListManagement) : ?>
                <input type="hidden" name="skip_lists" value="1">
            <?php endif; ?>
            <?php wp_nonce_field('mailerpress_update_contact_nonce', 'mailerpress_nonce'); ?>
            <p id="mailerpress-response-message" style="display:none; margin-top:10px;"></p>
            <button class="button mailerpress-submit-btn" type="submit">
                <?php esc_html_e('Save', 'mailerpress'); ?>
            </button>
        </div>
    <?php else: ?>
        <p>
            <?php esc_html_e('You can change the values, but submitting the form is not possible — this is for preview purposes only.',
                    'mailerpress'); ?>
        </p>
    <?php endif; ?>
</form>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('.mailerpress-manage-subscription');
        const submitBtn = document.querySelector('.mailerpress-submit-btn');
        const responseMsg = document.getElementById('mailerpress-response-message');
        const originalBtnText = submitBtn.innerHTML;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = submitBtn.dataset.loadingText || '<?php esc_attr_e('Saving...', 'mailerpress'); ?>';
            responseMsg.style.display = 'none';
            responseMsg.textContent = '';

            const formData = new FormData(form);

            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(res => {
                    responseMsg.textContent = res.data?.message || '<?php esc_attr_e('Unexpected response',
                            'mailerpress'); ?>';
                    responseMsg.style.color = res.success ? 'green' : 'red';
                    responseMsg.style.display = 'block';
                })
                .catch(err => {
                    responseMsg.textContent = '<?php esc_attr_e('Something went wrong. Please try again.',
                            'mailerpress'); ?>';
                    responseMsg.style.color = 'red';
                    responseMsg.style.display = 'block';
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                });
        });
    });
</script>
