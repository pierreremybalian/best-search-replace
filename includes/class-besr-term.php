<?php
/**
 * Understands what the user typed: a URL, a bare domain, an e-mail, a path, or plain text.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Works out what the user typed: a URL, a bare domain, an e-mail, a path or plain text.
 */
class BESR_Term {

    /**
     * @return array {
     *   raw, type (url|domain|email|path|text), has_scheme, scheme, has_www, host, bare_host, port, path,
     *   is_hostlike, has_at, first_is_word, last_is_word
     * }
     */
    public static function parse( string $term ): array {
        $out = array(
            'raw'           => $term,
            'type'          => 'text',
            'has_scheme'    => false,
            'scheme'        => '',
            'has_www'       => false,
            'host'          => '',
            'bare_host'     => '',
            'port'          => '',
            'path'          => '',
            'is_hostlike'   => false,
            'has_at'        => false !== strpos( $term, '@' ),
            'first_is_word' => self::is_word_byte( substr( $term, 0, 1 ) ),
            'last_is_word'  => self::is_word_byte( substr( $term, -1 ) ),
        );

        if ( '' === $term || preg_match( '/\s/', $term ) ) {
            return $out;
        }

        if ( preg_match( '/^[A-Za-z0-9._%+-]+@(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}$/', $term ) ) {
            $out['type'] = 'email';
            return $out;
        }

        // scheme://host(:port)(/path) or host(:port)(/path) where host has a dot or is localhost.
        if ( preg_match( '#^(?:(https?)://)?((?:www\.)?(?:[A-Za-z0-9-]+\.)+[A-Za-z0-9-]{2,}|(?:www\.)?localhost)(:\d{1,5})?(/.*)?$#i', $term, $m ) ) {
            $out['has_scheme']  = '' !== $m[1];
            $out['scheme']      = strtolower( $m[1] );
            $out['host']        = $m[2];
            $out['has_www']     = 0 === stripos( $m[2], 'www.' );
            $out['bare_host']   = $out['has_www'] ? substr( $m[2], 4 ) : $m[2];
            $out['port']        = $m[3] ?? '';
            $out['path']        = $m[4] ?? '';
            $out['is_hostlike'] = true;
            $out['type']        = $out['has_scheme'] ? 'url' : 'domain';
            return $out;
        }

        if ( 0 === strpos( $term, '/' ) && false === strpos( $term, '//' ) ) {
            $out['type'] = 'path';
        }

        return $out;
    }

    public static function is_word_byte( string $c ): bool {
        return '' !== $c && 1 === preg_match( '/[A-Za-z0-9_\x80-\xFF]/', $c );
    }

    /**
     * Human label for the detected type.
     */
    public static function type_label( string $type ): string {
        switch ( $type ) {
            case 'url':
                return __( 'Website address', 'best-search-replace' );
            case 'domain':
                return __( 'Domain name', 'best-search-replace' );
            case 'email':
                return __( 'E-mail address', 'best-search-replace' );
            case 'path':
                return __( 'Path', 'best-search-replace' );
        }
        return __( 'Plain text', 'best-search-replace' );
    }

    /**
     * Strip the scheme (and optionally www.) from a replacement so it can be rendered
     * in scheme-relative / bare form.
     */
    public static function schemeless( string $s ): string {
        return preg_replace( '#^https?://#i', '', $s );
    }
}
