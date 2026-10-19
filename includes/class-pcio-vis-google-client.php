<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Minimal Google Calendar v3 client for service accounts.
 *
 * Authenticates with the OAuth 2.0 JWT-bearer flow (RS256 assertion signed
 * with the service account's private key via OpenSSL) and talks to the
 * Calendar REST API through the WordPress HTTP API. Only the endpoints the
 * sync needs are implemented: list, insert, update and delete events.
 *
 * Every method returns WP_Error on failure; the error data carries the HTTP
 * status as ['status' => int] when Google answered.
 */
class PCIO_VIS_Google_Client {

    const SCOPE         = 'https://www.googleapis.com/auth/calendar';
    const TOKEN_URI     = 'https://oauth2.googleapis.com/token';
    const API_BASE      = 'https://www.googleapis.com/calendar/v3/calendars/';
    const TIMEOUT       = 30;
    const MAX_PAGES     = 100;

    private array $credentials;
    private string $access_token = '';
    private int $token_expires   = 0;

    /**
     * @param array $credentials Decoded service-account JSON.
     */
    private function __construct( array $credentials ) {
        $this->credentials = $credentials;
    }

    /**
     * Build a client from the service-account JSON (the GOOGLE_KEY value).
     *
     * @param string $json Credentials JSON.
     * @return self|WP_Error
     */
    public static function from_json( string $json ) {
        $credentials = self::parse_credentials( $json );
        if ( is_wp_error( $credentials ) ) {
            return $credentials;
        }
        return new self( $credentials );
    }

    /**
     * Validate service-account JSON and return the decoded array.
     *
     * @param string $json Credentials JSON.
     * @return array|WP_Error
     */
    public static function parse_credentials( string $json ) {
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'pcio_google_key_json', __( 'The Google key is not valid JSON.', 'pcio-vis-member-event' ) );
        }
        if ( ( $data['type'] ?? '' ) !== 'service_account' || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
            return new WP_Error(
                'pcio_google_key_type',
                __( 'The Google key must be a service-account key containing client_email and private_key.', 'pcio-vis-member-event' )
            );
        }
        if ( ! function_exists( 'openssl_pkey_get_private' ) || false === openssl_pkey_get_private( (string) $data['private_key'] ) ) {
            return new WP_Error( 'pcio_google_key_pem', __( 'The private key in the Google key could not be read.', 'pcio-vis-member-event' ) );
        }
        return $data;
    }

    public function get_client_email(): string {
        return (string) $this->credentials['client_email'];
    }

    // ── Authentication ────────────────────────────────────────────

    /**
     * Fetch (and cache for this request) an OAuth access token.
     *
     * @return string|WP_Error
     */
    public function get_access_token() {
        if ( '' !== $this->access_token && time() < $this->token_expires ) {
            return $this->access_token;
        }

        $assertion = $this->build_assertion();
        if ( is_wp_error( $assertion ) ) {
            return $assertion;
        }

        $response = wp_remote_post(
            (string) ( $this->credentials['token_uri'] ?? self::TOKEN_URI ),
            [
                'timeout' => self::TIMEOUT,
                'body'    => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $assertion,
                ],
            ]
        );
        $body = self::decode_response( $response );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        if ( empty( $body['access_token'] ) ) {
            return new WP_Error( 'pcio_google_token', __( 'Google did not return an access token.', 'pcio-vis-member-event' ) );
        }

        $this->access_token  = (string) $body['access_token'];
        $this->token_expires = time() + max( 60, (int) ( $body['expires_in'] ?? 3600 ) ) - 60;
        return $this->access_token;
    }

    /**
     * Signed RS256 JWT asserting the service account identity.
     *
     * @return string|WP_Error
     */
    private function build_assertion() {
        $now    = time();
        $header = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
        $claims = [
            'iss'   => $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud'   => (string) ( $this->credentials['token_uri'] ?? self::TOKEN_URI ),
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $unsigned  = self::base64url( (string) wp_json_encode( $header ) ) . '.' . self::base64url( (string) wp_json_encode( $claims ) );
        $signature = '';
        $key       = openssl_pkey_get_private( (string) $this->credentials['private_key'] );
        if ( false === $key || ! openssl_sign( $unsigned, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
            return new WP_Error( 'pcio_google_sign', __( 'Could not sign the Google authentication request.', 'pcio-vis-member-event' ) );
        }
        return $unsigned . '.' . self::base64url( $signature );
    }

    private static function base64url( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding, not obfuscation.
    }

    // ── Calendar API ──────────────────────────────────────────────

    /**
     * List expanded single events overlapping [$time_min, $time_max), following all pages.
     *
     * @param string $calendar_id Google calendar ID.
     * @param string $time_min    RFC 3339 lower bound.
     * @param string $time_max    RFC 3339 upper bound.
     * @return array[]|WP_Error Google event resources.
     */
    public function list_events( string $calendar_id, string $time_min, string $time_max ) {
        $items = [];
        $query = [
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'timeMin'      => $time_min,
            'timeMax'      => $time_max,
            'maxResults'   => 2500,
        ];

        for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
            $body = $this->request( 'GET', $calendar_id, '', $query );
            if ( is_wp_error( $body ) ) {
                return $body;
            }
            foreach ( (array) ( $body['items'] ?? [] ) as $item ) {
                if ( is_array( $item ) && ! empty( $item['id'] ) ) {
                    $items[] = $item;
                }
            }
            if ( empty( $body['nextPageToken'] ) ) {
                return $items;
            }
            $query['pageToken'] = (string) $body['nextPageToken'];
        }

        return new WP_Error( 'pcio_google_paging', __( 'Too many result pages returned by Google.', 'pcio-vis-member-event' ) );
    }

    /**
     * Probe access to a calendar (used by the admin "Test connection" button).
     *
     * @return array|WP_Error Calendar metadata.
     */
    public function get_calendar( string $calendar_id ) {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }
        $response = wp_remote_get(
            self::API_BASE . rawurlencode( $calendar_id ),
            [
                'timeout' => self::TIMEOUT,
                'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            ]
        );
        return self::decode_response( $response );
    }

    /** @return array|WP_Error Created event resource. */
    public function insert_event( string $calendar_id, array $event ) {
        return $this->request( 'POST', $calendar_id, '', [], $event );
    }

    /** @return array|WP_Error Updated event resource. */
    public function update_event( string $calendar_id, string $event_id, array $event ) {
        return $this->request( 'PUT', $calendar_id, $event_id, [], $event );
    }

    /** @return true|WP_Error */
    public function delete_event( string $calendar_id, string $event_id ) {
        $result = $this->request( 'DELETE', $calendar_id, $event_id );
        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Perform an authenticated request against /calendars/{id}/events[/{eventId}].
     *
     * @return array|WP_Error Decoded JSON body (empty array for 204).
     */
    private function request( string $method, string $calendar_id, string $event_id = '', array $query = [], ?array $body = null ) {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url = self::API_BASE . rawurlencode( $calendar_id ) . '/events';
        if ( '' !== $event_id ) {
            $url .= '/' . rawurlencode( $event_id );
        }
        if ( $query ) {
            $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }

        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ];
        if ( null !== $body ) {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body']                    = (string) wp_json_encode( $body );
        }

        return self::decode_response( wp_remote_request( $url, $args ) );
    }

    /**
     * Turn an HTTP response into a decoded body or a WP_Error carrying the status.
     *
     * @param array|WP_Error $response wp_remote_* result.
     * @return array|WP_Error
     */
    private static function decode_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = (string) wp_remote_retrieve_body( $response );
        $data   = '' === $raw ? [] : json_decode( $raw, true );

        if ( $status < 200 || $status >= 300 ) {
            $message = '';
            if ( is_array( $data ) ) {
                $message = is_array( $data['error'] ?? null )
                    ? (string) ( $data['error']['message'] ?? '' )
                    : (string) ( $data['error_description'] ?? $data['error'] ?? '' );
            }
            return new WP_Error(
                'pcio_google_http',
                sprintf(
                    /* translators: 1: HTTP status code, 2: error message from Google */
                    __( 'Google API error %1$d: %2$s', 'pcio-vis-member-event' ),
                    $status,
                    '' !== $message ? $message : __( 'unknown error', 'pcio-vis-member-event' )
                ),
                [ 'status' => $status ]
            );
        }

        return is_array( $data ) ? $data : [];
    }
}
