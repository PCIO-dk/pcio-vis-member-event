<?php
/**
 * Template: [pcio_me_member_signup] shortcode.
 * Rendered by PCIO_VIS_Member_Signup::render() via output buffer.
 *
 * $extra_sections and $has_payment are set by the render() method before require.
 *
 * @var array<array{id:string,title:string,html:string}> $extra_sections
 * @var bool $has_payment
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="pcio-ms-signup">

    <div class="pcio-ms-signup__error" role="alert" aria-live="polite" style="display:none;"></div>
    <div class="pcio-ms-signup__success" role="status" aria-live="polite" style="display:none;"></div>

    <form id="pcio-ms-signup-form" class="pcio-ms-signup__form" novalidate>

        <fieldset class="pcio-ms-signup__section">
            <legend class="pcio-ms-signup__legend">
                <?php esc_html_e( 'Your details', 'pcio-vis-member-event' ); ?>
            </legend>

            <div class="pcio-ms-signup__field">
                <label for="pcio-ms-name">
                    <?php esc_html_e( 'Full name', 'pcio-vis-member-event' ); ?>
                    <span class="pcio-ms-signup__req" aria-hidden="true">*</span>
                </label>
                <input type="text" id="pcio-ms-name" name="name" autocomplete="name" required>
            </div>

            <div class="pcio-ms-signup__field">
                <label for="pcio-ms-email">
                    <?php esc_html_e( 'Email', 'pcio-vis-member-event' ); ?>
                    <span class="pcio-ms-signup__req" aria-hidden="true">*</span>
                </label>
                <input type="email" id="pcio-ms-email" name="email" autocomplete="email" required>
            </div>

            <div class="pcio-ms-signup__field">
                <label for="pcio-ms-phone">
                    <?php esc_html_e( 'Phone', 'pcio-vis-member-event' ); ?>
                </label>
                <input type="tel" id="pcio-ms-phone" name="phone" autocomplete="tel">
            </div>

            <div class="pcio-ms-signup__field">
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

        <p class="pcio-ms-signup__note">
            <?php esc_html_e( '* Required', 'pcio-vis-member-event' ); ?>
        </p>

        <div class="pcio-ms-signup__actions">
            <button type="submit" class="pcio-ms-signup__submit">
                <?php if ( $has_payment ) : ?>
                    <?php esc_html_e( 'Continue to payment', 'pcio-vis-member-event' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Register', 'pcio-vis-member-event' ); ?>
                <?php endif; ?>
            </button>
            <span class="pcio-ms-signup__spinner" style="display:none;" aria-hidden="true"></span>
        </div>

    </form>
</div>
