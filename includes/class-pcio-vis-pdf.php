<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;

/**
 * Shared PDF + QR helper for the PCIO VIS Member Event plugin and its extensions.
 *
 * Wraps Dompdf with a sensible, offline-safe configuration (DejaVu Sans for full
 * Latin/Æøå support, no remote requests, a writable temp/font-cache directory under
 * the uploads folder). Extensions render their own HTML and call render() to get the
 * raw PDF bytes back.
 */
class PCIO_VIS_PDF {

    /** Sub-directory of wp-uploads used for Dompdf temp + font cache. */
    const TMP_SUBDIR = 'pcio-me/tmp';

    /**
     * Whether the Dompdf library is available (vendor autoload loaded).
     */
    public static function available(): bool {
        return class_exists( '\\Dompdf\\Dompdf' );
    }

    /**
     * Render an HTML document to raw PDF bytes.
     *
     * @param string $html        Full HTML document.
     * @param string $paper       Paper size, e.g. 'A4'.
     * @param string $orientation 'portrait' or 'landscape'.
     * @return string|null PDF bytes, or null on failure.
     */
    public static function render( string $html, string $paper = 'A4', string $orientation = 'portrait' ) {
        if ( ! self::available() ) {
            return null;
        }

        $tmp = self::tmp_dir();

        try {
            $options = new Options();
            $options->set( 'isRemoteEnabled', false );
            $options->set( 'isHtml5ParserEnabled', true );
            $options->set( 'defaultFont', 'DejaVu Sans' );
            $options->set( 'tempDir', $tmp );
            $options->set( 'fontCache', $tmp );
            $options->set( 'chroot', dirname( $tmp ) );

            $dompdf = new Dompdf( $options );
            $dompdf->loadHtml( $html, 'UTF-8' );
            $dompdf->setPaper( $paper, $orientation );
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch ( \Throwable $e ) {
            return null;
        }

        return ( is_string( $pdf ) && '' !== $pdf ) ? $pdf : null;
    }

    /**
     * Render a QR code as a PNG data URI (embeddable in PDF HTML).
     *
     * @param string $text    Payload to encode.
     * @param int    $version QR version (size), default 6.
     * @param int    $scale   Pixel scale per module.
     */
    public static function qr_data_uri( string $text, int $version = 6, int $scale = 5 ): string {
        if ( ! class_exists( '\\chillerlan\\QRCode\\QRCode' ) ) {
            return '';
        }
        $options = new QROptions( [
            'version'      => $version,
            'outputType'   => QROutputInterface::GDIMAGE_PNG,
            'eccLevel'     => EccLevel::M,
            'outputBase64' => true,
            'scale'        => $scale,
            'imageBase64'  => true,
        ] );
        return ( new QRCode( $options ) )->render( $text );
    }

    /**
     * Return (creating if needed) a writable temp/font-cache directory.
     */
    private static function tmp_dir(): string {
        $uploads = wp_upload_dir();
        $path    = trailingslashit( $uploads['basedir'] ) . self::TMP_SUBDIR;
        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
            self::write_index_guard( $path );
        }
        return $path;
    }

    /**
     * Drop an empty index.html into a directory to prevent directory listing,
     * using the WordPress filesystem abstraction.
     */
    private static function write_index_guard( string $dir ): void {
        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( WP_Filesystem() ) {
            $wp_filesystem->put_contents( trailingslashit( $dir ) . 'index.html', '' );
        }
    }
}
