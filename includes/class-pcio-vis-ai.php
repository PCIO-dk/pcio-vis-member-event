<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Event text helpers built on the WordPress AI Client (WP 7.0+).
 *
 * Credentials and provider are configured by the site owner under
 * Settings → Connectors; this plugin never stores API keys itself.
 */
class PCIO_VIS_AI {

    const MAX_INPUT = 20000;

    /** Cached per request: the support check resolves providers and models. */
    private static ?bool $available = null;

    /** Whether the AI Client exists and a connected provider supports text generation. */
    public static function is_available(): bool {
        if ( null !== self::$available ) {
            return self::$available;
        }
        if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
            self::$available = false;
            return false;
        }
        try {
            self::$available = (bool) wp_ai_client_prompt()
                ->with_text( 'test' )
                ->is_supported_for_text_generation();
        } catch ( \Throwable $e ) {
            self::$available = false;
        }
        return self::$available;
    }

    /**
     * Shorten a (Danish) event text.
     *
     * @param string $text Plain-text description.
     * @return string|WP_Error The short text, or an error.
     */
    public static function shorten( string $text ) {
        if ( ! self::is_available() ) {
            return new WP_Error( 'pcio_vis_ai_unavailable', __( 'No AI provider is connected. Set one up under Settings → Connectors.', 'pcio-vis-member-event' ) );
        }

        $text = trim( mb_substr( $text, 0, self::MAX_INPUT ) );
        if ( '' === $text ) {
            return new WP_Error( 'pcio_vis_ai_empty', __( 'The description is empty.', 'pcio-vis-member-event' ) );
        }

        $prompt = PCIO_VIS_Google_DB::get_ai_prompt() . "\n\n" . $text;

        $result = wp_ai_client_prompt( $prompt )->generate_text();
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $result = trim( (string) $result );
        if ( '' === $result ) {
            return new WP_Error( 'pcio_vis_ai_empty_answer', __( 'The AI returned no text.', 'pcio-vis-member-event' ) );
        }
        return $result;
    }
}
