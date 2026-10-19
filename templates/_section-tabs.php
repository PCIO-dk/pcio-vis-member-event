<?php
/**
 * Shared page-link tabs. The caller supplies $pcio_me_section and active page.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$pcio_me_section_tabs = PCIO_VIS_Navigation::tabs( $pcio_me_section, $pcio_me_active_page );
?>
<?php if ( count( $pcio_me_section_tabs ) > 1 ) : ?>
<nav class="me-fin-tabs me-section-tabs">
    <?php foreach ( $pcio_me_section_tabs as $pcio_me_section_tab ) : ?>
    <a class="me-fin-tab<?php echo $pcio_me_section_tab['active'] ? ' me-fin-tab-active' : ''; ?>" href="<?php echo esc_url( $pcio_me_section_tab['url'] ); ?>"<?php if ( $pcio_me_section_tab['active'] ) : ?> aria-current="page"<?php endif; ?>><?php echo esc_html( $pcio_me_section_tab['label'] ); ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
