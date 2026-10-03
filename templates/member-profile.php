<?php
/**
 * Template: [pcio_me_edit_profile] shortcode.
 * Rendered by PCIO_VIS_Plugin::shortcode_edit_profile() via output buffer.
 *
 * $extra_sections  are set by the shortcode method before require.
 *
 * @var array<array{id:string,title:string,html:string}> $extra_sections
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="pcio-ms-profile">

    <div class="pcio-ms-profile__error" role="alert" aria-live="polite" style="display:none;"></div>
    <div class="pcio-ms-profile__success" role="status" aria-live="polite" style="display:none;"></div>

    <form id="pcio-ms-profile-form" class="pcio-ms-profile__form" novalidate>

        <fieldset class="pcio-ms-profile__section">
            <legend class="pcio-ms-profile__legend">
                <?php esc_html_e( 'Your details', 'pcio-vis-member-event' ); ?>
            </legend>

            <div class="pcio-ms-profile__field">
                <label for="pcio-ms-member-number">
                    <?php esc_html_e( 'Membership number', 'pcio-vis-member-event' ); ?>
                </label>
                <input type="text" id="pcio-ms-member-number" name="member_number" autocomplete="off" disabled>
            </div>

            <div class="pcio-ms-profile__field">
                <label for="pcio-ms-name">
                    <?php esc_html_e( 'Full name', 'pcio-vis-member-event' ); ?>
                    <span class="pcio-ms-profile__req" aria-hidden="true">*</span>
                </label>
                <input type="text" id="pcio-ms-name" name="name" autocomplete="name" required>
            </div>

            <div class="pcio-ms-profile__field">
                <label for="pcio-ms-email">
                    <?php esc_html_e( 'Email', 'pcio-vis-member-event' ); ?>
                    <span class="pcio-ms-profile__req" aria-hidden="true">*</span>
                </label>
                <input type="email" id="pcio-ms-email" name="email" autocomplete="email" required>
            </div>

            <div class="pcio-ms-profile__field">
                <label for="pcio-ms-phone">
                    <?php esc_html_e( 'Phone', 'pcio-vis-member-event' ); ?>
                </label>
                <input type="tel" id="pcio-ms-phone" name="phone" autocomplete="tel">
            </div>

            <div class="pcio-ms-profile__field">
                <label for="pcio-ms-address">
                    <?php esc_html_e( 'Address', 'pcio-vis-member-event' ); ?>
                </label>
                <textarea id="pcio-ms-address" name="address" autocomplete="street-address" rows="3"></textarea>
            </div>
        </fieldset>

        <?php foreach ( $extra_sections as $pcio_ms_section ) : ?>
            <?php if ( ! empty( $pcio_ms_section['html'] ) ) : ?>
                <?php echo $pcio_ms_section['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML produced by trusted extension filter. ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <p class="pcio-ms-profile__note">
            <?php esc_html_e( '* Required', 'pcio-vis-member-event' ); ?>
        </p>

        <div class="pcio-ms-profile__actions">
            <button type="submit" class="pcio-ms-profile__submit">
               <?php esc_html_e( 'Update', 'pcio-vis-member-event' ); ?>
            </button>
            <span class="pcio-ms-profile__spinner" style="display:none;" aria-hidden="true"></span>
        </div>

    </form>
</div>
