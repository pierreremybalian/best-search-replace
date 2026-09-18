<?php
/**
 * Classifies a single occurrence by what surrounds it, so the user can decide
 * per category ("inside an e-mail address", "part of a longer domain", ...)
 * instead of per string.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Classifies a single occurrence by what surrounds it, so matches can be skipped
 * by category rather than one at a time.
 */
class BESR_Context {

    /**
     * Flags the user may include/exclude at review time.
     */
    const TOGGLEABLE = array( 'inside_email', 'longer_domain', 'inside_word', 'in_hash_like_string', 'in_serialized_key', 'case_differs' );

    /**
     * Flags that can never be included.
     */
    const HARD = array( 'inside_serialized_class_name', 'inside_serialized_prop_name' );

    const WINDOW = 256;

    /**
     * @param string       $subject The (decoded) string the match was found in.
     * @param int          $offset  Byte offset of the hit.
     * @param int          $length  Byte length of the hit.
     * @param array        $branch  Ruleset branch meta.
     * @param BESR_Ruleset $rules   The compiled ruleset.
     * @param array        $meta    role (value|array_key|prop_name|class_name), column, table.
     * @return string[]
     */
    public static function classify( string $subject, int $offset, int $length, array $branch, BESR_Ruleset $rules, array $meta ): array {
        $flags = array();
        $role  = $meta['role'] ?? 'value';
        if ( 'class_name' === $role ) {
            return array( 'inside_serialized_class_name' );
        }
        if ( 'prop_name' === $role ) {
            return array( 'inside_serialized_prop_name' );
        }
        if ( 'array_key' === $role ) {
            $flags[] = 'in_serialized_key';
        }

        $term   = $rules->term;
        $hit    = substr( $subject, $offset, $length );
        $before = substr( $subject, max( 0, $offset - self::WINDOW ), min( $offset, self::WINDOW ) );
        $after  = substr( $subject, $offset + $length, self::WINDOW );
        $before = false === $before ? '' : $before;
        $after  = false === $after ? '' : $after;

        $email_prefix = false;
        $email_suffix = false;
        $longer_pre   = false;
        $longer_suf   = false;

        if ( $term['is_hostlike'] && ! $term['has_at'] && $branch['schemeless'] ) {
            $email_prefix = (bool) preg_match( '/[A-Za-z0-9._%+-]+(?:@|%40)$/', $before );
            $email_suffix = (bool) preg_match( '/^(?:@|%40)[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $after );
            if ( $email_prefix || $email_suffix ) {
                $flags[] = 'inside_email';
            }
        }

        if ( $term['is_hostlike'] ) {
            if ( $branch['schemeless'] && ! $email_prefix && preg_match( '/[A-Za-z0-9-]+\.$/', $before ) ) {
                $longer_pre = true;
            }
            // Only when the term ends at the host: "old.com" in "old.com.au".
            if ( '' === $term['path'] && '' === $term['port'] && preg_match( '/^\.[A-Za-z]{2,}(?![A-Za-z0-9-])/', $after ) ) {
                $longer_suf = true;
            }
            if ( $longer_pre || $longer_suf ) {
                $flags[] = 'longer_domain';
            }
        }

        $prev = '' !== $before ? substr( $before, -1 ) : '';
        $next = '' !== $after ? substr( $after, 0, 1 ) : '';
        if ( ( $branch['first_is_word'] && ! $longer_pre && ! $email_prefix && BESR_Term::is_word_byte( $prev ) )
            || ( $branch['last_is_word'] && ! $longer_suf && ! $email_suffix && BESR_Term::is_word_byte( $next ) ) ) {
            $flags[] = 'inside_word';
        }

        if ( ! $term['is_hostlike'] && ! preg_match( '/[.:\/\s]/', $rules->search ) ) {
            $run_before = preg_match( '/[A-Za-z0-9+\/=_-]+$/', $before, $m1 ) ? strlen( $m1[0] ) : 0;
            $run_after  = preg_match( '/^[A-Za-z0-9+\/=_-]+/', $after, $m2 ) ? strlen( $m2[0] ) : 0;
            if ( $run_before + $length + $run_after >= 40 && ( $run_before + $run_after ) > 0 ) {
                $flags[] = 'in_hash_like_string';
            }
        }

        if ( $rules->case_insensitive && $hit !== $branch['literal'] && 'urlencoded' !== $branch['encoding'] ) {
            $flags[] = 'case_differs';
        }

        return $flags;
    }

    public static function label( string $flag ): string {
        $labels = array(
            'inside_email'                 => __( 'inside an e-mail address', 'best-search-replace' ),
            'longer_domain'                => __( 'part of a longer domain', 'best-search-replace' ),
            'inside_word'                  => __( 'inside a longer word', 'best-search-replace' ),
            'in_hash_like_string'          => __( 'inside a code or hash', 'best-search-replace' ),
            'in_serialized_key'            => __( 'used as a data key', 'best-search-replace' ),
            'inside_serialized_class_name' => __( 'inside a PHP class name', 'best-search-replace' ),
            'inside_serialized_prop_name'  => __( 'inside a PHP property name', 'best-search-replace' ),
            'case_differs'                 => __( 'different letter case', 'best-search-replace' ),
        );
        return $labels[ $flag ] ?? $flag;
    }

    /**
     * Plain-English explanation for the review screen.
     */
    public static function explain( string $flag, string $search, string $replace ): array {
        $host = BESR_Term::parse( $search );
        $h    = $host['is_hostlike'] ? $host['bare_host'] : $search;
        $r    = BESR_Term::parse( $replace );
        $rh   = $r['is_hostlike'] ? $r['bare_host'] : $replace;
        switch ( $flag ) {
            case 'inside_email':
                return array(
                    'title' => __( 'matches are inside e-mail addresses', 'best-search-replace' ),
                    /* translators: 1: old address, 2: new address */
                    'text'  => sprintf( __( 'Replacing here would turn admin@%1$s into admin@%2$s. When you are moving a site, e-mail addresses usually should not change.', 'best-search-replace' ), $h, $rh ),
                );
            case 'longer_domain':
                return array(
                    'title' => __( 'matches are part of a longer domain', 'best-search-replace' ),
                    /* translators: 1: old host, 2: new host */
                    'text'  => sprintf( __( 'shop.%1$s or %1$s.au contain %1$s, so they would become shop.%2$s or %2$s.au. Include them only if those addresses moved too.', 'best-search-replace' ), $h, $rh ),
                );
            case 'inside_word':
                return array(
                    'title' => __( 'matches are inside a longer word', 'best-search-replace' ),
                    'text'  => __( 'Your text appears in the middle of other words. Check the examples; skip these if they look accidental.', 'best-search-replace' ),
                );
            case 'in_hash_like_string':
                return array(
                    'title' => __( 'matches are inside codes or hashes', 'best-search-replace' ),
                    'text'  => __( 'The text sits inside a long run of letters and digits, such as a token or an encoded blob. Changing it would most likely corrupt that value.', 'best-search-replace' ),
                );
            case 'in_serialized_key':
                return array(
                    'title' => __( 'matches are used as data keys', 'best-search-replace' ),
                    'text'  => __( 'The text is the name of a setting inside stored PHP data, not a value. Plugins look settings up by name, so renaming keys usually breaks them.', 'best-search-replace' ),
                );
            case 'inside_serialized_class_name':
            case 'inside_serialized_prop_name':
                return array(
                    'title' => __( 'matches are inside PHP class or property names', 'best-search-replace' ),
                    'text'  => __( 'These are never changed: renaming a class or property inside stored data would make it unreadable.', 'best-search-replace' ),
                );
            case 'case_differs':
                return array(
                    'title' => __( 'matches use different letter case', 'best-search-replace' ),
                    'text'  => __( 'Found because "Ignore letter case" is on. They will be replaced with your exact spelling.', 'best-search-replace' ),
                );
        }
        return array( 'title' => $flag, 'text' => '' );
    }
}
