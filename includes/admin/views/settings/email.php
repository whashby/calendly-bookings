<?php
namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Modules\CB_Email;

$templates = CB_Email::templates();
$active = array_key_first($templates);
?>
<div class="cb-settings-card">
    <div class="cb-settings-card__header">
        <div>
            <h2><?php esc_html_e('Email templates', 'calendly-bookings'); ?></h2>
            <p><?php esc_html_e('Create reusable booking emails without editing PHP. Changes are applied to future notifications.', 'calendly-bookings'); ?></p>
        </div>
        <span class="cb-status-badge enabled"><?php esc_html_e('Template engine active', 'calendly-bookings'); ?></span>
    </div>

    <div class="cb-email-layout">
        <aside class="cb-email-template-list">
            <?php foreach ($templates as $key => $template): ?>
                <button type="button" class="cb-email-template-tab <?php echo $key === $active ? 'is-active' : ''; ?>" data-template="<?php echo esc_attr($key); ?>">
                    <span><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></span>
                    <small><?php echo !empty($template['enabled']) ? esc_html__('Enabled', 'calendly-bookings') : esc_html__('Disabled', 'calendly-bookings'); ?></small>
                </button>
            <?php endforeach; ?>
        </aside>

        <section class="cb-email-editor">
            <?php foreach ($templates as $key => $template): ?>
                <div class="cb-email-template-panel <?php echo $key === $active ? 'is-active' : ''; ?>" data-template-panel="<?php echo esc_attr($key); ?>">
                    <input type="hidden" name="email_template_key" value="<?php echo esc_attr($key); ?>">
                    <p>
                        <label>
                            <input type="checkbox" class="cb-email-enabled" <?php checked(!empty($template['enabled'])); ?>>
                            <?php esc_html_e('Enable this email', 'calendly-bookings'); ?>
                        </label>
                        <select class="cb-email-recipient">
                            <option value="customer" <?php selected($template['recipients'], 'customer'); ?>><?php esc_html_e('Customer', 'calendly-bookings'); ?></option>
                            <option value="admin" <?php selected($template['recipients'], 'admin'); ?>><?php esc_html_e('Administrator', 'calendly-bookings'); ?></option>
                        </select>
                    </p>
                    <p>
                        <label for="cb-email-subject-<?php echo esc_attr($key); ?>"><?php esc_html_e('Subject', 'calendly-bookings'); ?></label>
                        <input id="cb-email-subject-<?php echo esc_attr($key); ?>" class="widefat cb-email-subject" type="text" value="<?php echo esc_attr($template['subject']); ?>">
                    </p>
                    <p>
                        <label><?php esc_html_e('HTML body', 'calendly-bookings'); ?></label>
                        <textarea class="widefat cb-email-body" rows="16"><?php echo esc_textarea($template['body']); ?></textarea>
                    </p>
                    <div class="cb-email-preview-wrap">
                        <strong><?php esc_html_e('Live preview', 'calendly-bookings'); ?></strong>
                        <iframe class="cb-email-live-preview" title="<?php esc_attr_e('Email preview', 'calendly-bookings'); ?>"></iframe>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <div class="cb-email-tokens">
        <strong><?php esc_html_e('Available tokens', 'calendly-bookings'); ?></strong>
        <div>
            <?php foreach (CB_Email::tokens() as $token => $label): ?>
                <button type="button" class="button button-small cb-email-token" data-token="<?php echo esc_attr($token); ?>" title="<?php echo esc_attr($label); ?>"><?php echo esc_html($token); ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <hr>

    <h3><?php esc_html_e('Delivery', 'calendly-bookings'); ?></h3>
    <div class="cb-email-delivery-grid">
        <p><label for="cb_email_to"><?php esc_html_e('Administrator recipients', 'calendly-bookings'); ?></label>
            <input id="cb_email_to" class="regular-text" type="text" value="<?php echo esc_attr(get_option(CB_Constants::OPT_EMAIL_TO, get_option('admin_email', ''))); ?>">
            <span class="description"><?php esc_html_e('Separate multiple addresses with commas.', 'calendly-bookings'); ?></span>
        </p>
        <p><label for="cb_email_from"><?php esc_html_e('From address', 'calendly-bookings'); ?></label>
            <input id="cb_email_from" class="regular-text" type="email" value="<?php echo esc_attr(get_option(CB_Constants::OPT_EMAIL_FROM, '')); ?>">
        </p>
        <p><label for="cb_email_reply_to"><?php esc_html_e('Reply-To', 'calendly-bookings'); ?></label>
            <input id="cb_email_reply_to" class="regular-text" type="email" value="<?php echo esc_attr(get_option(CB_Constants::OPT_EMAIL_REPLY_TO, '')); ?>">
        </p>
        <p><label for="cb_email_bcc"><?php esc_html_e('BCC', 'calendly-bookings'); ?></label>
            <input id="cb_email_bcc" class="regular-text" type="text" value="<?php echo esc_attr(get_option(CB_Constants::OPT_EMAIL_BCC, '')); ?>">
        </p>
    </div>

    <div class="cb-settings-actions">
        <button type="button" class="button button-primary" id="cb-save-email-settings"><?php esc_html_e('Save email settings', 'calendly-bookings'); ?></button>
        <input type="email" id="cb-test-email-recipient" class="regular-text" placeholder="<?php esc_attr_e('Test recipient', 'calendly-bookings'); ?>" value="<?php echo esc_attr(get_option('admin_email')); ?>">
        <button type="button" class="button" id="cb-test-email"><?php esc_html_e('Send test', 'calendly-bookings'); ?></button>
        <button type="button" class="button" id="cb-preview-email"><?php esc_html_e('Refresh preview', 'calendly-bookings'); ?></button>
        <span id="cb-email-status" class="cb-inline-status" aria-live="polite"></span>
    </div>
</div>
