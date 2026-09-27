<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Database layer for document types and documents.
 *
 * Tables
 * ──────
 *   me_document_types  –  id, name (slug), title
 *   me_documents       –  id, type_id (FK), display_name, wp_attachment_id, document_date
 *
 * Documents are stored as WordPress media attachments; wp_attachment_id links
 * to the WP media library so files live inside the standard uploads folder and
 * are manageable from the WP admin.
 */
class PCIO_VIS_Docs_DB {

    const TABLE_TYPES = 'me_document_types';
    const TABLE_DOCS  = 'me_documents';

    public static function table_types(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_TYPES;
    }

    public static function table_docs(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_DOCS;
    }

    // ── Schema ────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;
        $types   = self::table_types();
        $docs    = self::table_docs();
        $charset = $wpdb->get_charset_collate();

        $sql_types = "CREATE TABLE IF NOT EXISTS {$types} (
            id int NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL DEFAULT '',
            title varchar(200) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY name (name)
        ) {$charset};";
        dbDelta( $sql_types );

        // NOTE: no FOREIGN KEY — dbDelta cannot manage constraints. The
        // type_id ↔ document_types link is enforced in PHP (see delete_type()).
        $sql_docs = "CREATE TABLE IF NOT EXISTS {$docs} (
            id int NOT NULL AUTO_INCREMENT,
            type_id int NOT NULL,
            display_name varchar(200) NOT NULL DEFAULT '',
            wp_attachment_id bigint NOT NULL DEFAULT 0,
            document_date date DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY type_id (type_id)
        ) {$charset};";
        dbDelta( $sql_docs );
    }

    // ── Document Types: Read ──────────────────────────────────────

    public static function get_all_types(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY `title` ASC', self::table_types() ),
            ARRAY_A
        ) ?: [];
    }

    public static function get_type( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', self::table_types(), $id ),
            ARRAY_A
        ) ?: null;
    }

    public static function get_type_by_name( string $name ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `name` = %s', self::table_types(), $name ),
            ARRAY_A
        ) ?: null;
    }

    // ── Document Types: Write ─────────────────────────────────────

    /** @return int|false  Inserted ID, or false on failure. */
    public static function create_type( array $data ) {
        global $wpdb;
        $result = $wpdb->insert(
            self::table_types(),
            [
                'name'  => sanitize_key(            $data['name']  ?? '' ),
                'title' => sanitize_text_field(     $data['title'] ?? '' ),
            ]
        );
        return $result ? $wpdb->insert_id : false;
    }

    public static function update_type( int $id, array $data ): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::table_types(),
            [
                'name'  => sanitize_key(        $data['name']  ?? '' ),
                'title' => sanitize_text_field( $data['title'] ?? '' ),
            ],
            [ 'id' => $id ]
        );
    }

    /**
     * Deletes a type. Returns false if documents still reference it.
     */
    public static function delete_type( int $id ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `type_id` = %d', self::table_docs(), $id )
        );
        if ( $count > 0 ) {
            return false; // blocked by existing documents
        }
        return (bool) $wpdb->delete( self::table_types(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Documents: Read ───────────────────────────────────────────

    /**
     * All documents, joined with their type, sorted by document_date DESC.
     * Adds attachment_url resolved from WP media library.
     */
    public static function get_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT d.*, t.name AS type_name, t.title AS type_title
                 FROM %i d
                 JOIN %i t ON t.id = d.type_id
                 ORDER BY d.document_date DESC, d.id DESC',
                self::table_docs(),
                self::table_types()
            ),
            ARRAY_A
        ) ?: [];

        return array_map( [ self::class, 'decorate' ], $rows );
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT d.*, t.name AS type_name, t.title AS type_title
                 FROM %i d
                 JOIN %i t ON t.id = d.type_id
                 WHERE d.id = %d',
                self::table_docs(),
                self::table_types(),
                $id
            ),
            ARRAY_A
        );
        return $row ? self::decorate( $row ) : null;
    }

    /**
     * Documents for a given type name, sorted date DESC.
     * Used by the [pcio_me_documents] shortcode.
     *
     * @param string $type_name  The `name` slug of the document type.
     * @param int    $limit      Maximum number of rows (0 = no limit).
     */
    public static function get_by_type( string $type_name, int $limit = 0 ): array {
        global $wpdb;
        if ( $limit > 0 ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT d.*, t.name AS type_name, t.title AS type_title
                     FROM %i d
                     JOIN %i t ON t.id = d.type_id
                     WHERE t.name = %s
                     ORDER BY d.document_date DESC, d.id DESC
                     LIMIT %d',
                    self::table_docs(),
                    self::table_types(),
                    $type_name,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT d.*, t.name AS type_name, t.title AS type_title
                     FROM %i d
                     JOIN %i t ON t.id = d.type_id
                     WHERE t.name = %s
                     ORDER BY d.document_date DESC, d.id DESC',
                    self::table_docs(),
                    self::table_types(),
                    $type_name
                ),
                ARRAY_A
            );
        }
        return array_map( [ self::class, 'decorate' ], $rows ?: [] );
    }

    // ── Documents: Write ──────────────────────────────────────────

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $result = $wpdb->insert( self::table_docs(), self::sanitize( $data ) );
        return $result ? $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return false !== $wpdb->update( self::table_docs(), self::sanitize( $data ), [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table_docs(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Internal helpers ──────────────────────────────────────────

    private static function sanitize( array $data ): array {
        $date = sanitize_text_field( $data['document_date'] ?? '' );
        return [
            'type_id'          => absint(              $data['type_id']          ?? 0  ),
            'display_name'     => sanitize_text_field( $data['display_name']     ?? '' ),
            'wp_attachment_id' => absint(              $data['wp_attachment_id'] ?? 0  ),
            'document_date'    => $date ?: null,
        ];
    }

    /**
     * Adds attachment_url and casts id fields to int.
     */
    private static function decorate( array $row ): array {
        $row['id']               = (int) $row['id'];
        $row['type_id']          = (int) $row['type_id'];
        $row['wp_attachment_id'] = (int) $row['wp_attachment_id'];
        $row['attachment_url']   = $row['wp_attachment_id']
            ? (string) wp_get_attachment_url( $row['wp_attachment_id'] )
            : '';
        $row['attachment_name']  = $row['wp_attachment_id']
            ? (string) get_the_title( $row['wp_attachment_id'] )
            : '';
        return $row;
    }
}
