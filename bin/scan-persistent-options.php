<?php
/**
 * Dev/CI-only scanner — NOT loaded at runtime.
 *
 * Discovers the persistent option names the plugin WRITES, so
 * bin/verify-metrics.sh can assert the "Persistent options" row in
 * docs/current-metrics.md against the code instead of a self-confirming list.
 *
 * It uses token_get_all() (not regex) so it cannot false-match option-like
 * substrings in strings/comments (e.g. a 'pre_update_option_*' filter name),
 * and it resolves class constants CLASS-SCOPED: `self::CONST` against the
 * enclosing class, `Class::CONST` against that named class. That scoping is what
 * makes a second class defining its own `OPTION_KEY = 'wp_sudo_other'` discover
 * as a distinct option instead of collapsing onto the first same-named constant.
 *
 * Only the four write APIs whose FIRST argument is the option name are parsed
 * (update_option / add_option / update_site_option / add_site_option). The
 * *_network_option writes take the option name as their SECOND argument; rather
 * than silently mis-read arg 1, the scanner FAILS CLOSED on them (exit 3), as it
 * does on any option-name argument it cannot resolve to a string literal.
 *
 * CLI:  php bin/scan-persistent-options.php <file-or-dir> [...]  -> prints the
 *       sorted, space-joined wp_sudo_* option names, or exits 3 with a message.
 *
 * @package WP_Sudo\Dev
 */

declare( strict_types = 1 );

namespace WP_Sudo\Dev;

final class Persistent_Option_Scanner {

	/** Write APIs whose first argument is the option name. */
	private const WRITE_FUNCS = array( 'update_option', 'add_option', 'update_site_option', 'add_site_option' );

	/** Network write APIs whose option name is argument 2 (not parsed — fail closed). */
	private const NETWORK_FUNCS = array( 'update_network_option', 'add_network_option' );

	/**
	 * Scan a set of PHP sources.
	 *
	 * @param array<string,string> $sources label => PHP source code.
	 * @return list<string> Sorted, unique wp_sudo_* option names written.
	 * @throws \RuntimeException On an unresolvable option-name argument or a network-option write.
	 */
	public function scan_sources( array $sources ): array {
		$class_consts = array(); // class => [ CONST => value ]
		$writes       = array();

		foreach ( $sources as $label => $src ) {
			$parsed = $this->parse( (string) $src, (string) $label );
			foreach ( $parsed['consts'] as $cls => $map ) {
				$class_consts[ $cls ] = ( $class_consts[ $cls ] ?? array() ) + $map;
			}
			foreach ( $parsed['writes'] as $w ) {
				$writes[] = $w;
			}
		}

		$names = array();
		foreach ( $writes as $w ) {
			$name = $this->resolve( $w, $class_consts );
			if ( str_starts_with( $name, 'wp_sudo_' ) ) {
				$names[ $name ] = true;
			}
		}

		$out = array_keys( $names );
		sort( $out );
		return $out;
	}

	/**
	 * Scan a list of files.
	 *
	 * @param list<string> $files Absolute file paths.
	 * @return list<string>
	 */
	public function scan_files( array $files ): array {
		$sources = array();
		foreach ( $files as $file ) {
			$sources[ $file ] = (string) file_get_contents( $file );
		}
		return $this->scan_sources( $sources );
	}

	/**
	 * Tokenize one source into its class constants and option-write descriptors.
	 *
	 * @return array{consts: array<string,array<string,string>>, writes: list<array<string,mixed>>}
	 */
	private function parse( string $src, string $label ): array {
		$sig = array();
		foreach ( token_get_all( $src ) as $tok ) {
			if ( is_array( $tok ) ) {
				if ( T_WHITESPACE === $tok[0] || T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) {
					continue;
				}
				$sig[] = array( $tok[0], $tok[1] );
			} else {
				$sig[] = array( null, $tok ); // single-char punctuation
			}
		}

		$consts        = array();
		$writes        = array();
		$current_class = null;
		$count         = count( $sig );

		for ( $i = 0; $i < $count; $i++ ) {
			list( $id, $text ) = $sig[ $i ];

			if ( T_CLASS === $id && $i + 1 < $count && T_STRING === $sig[ $i + 1 ][0] ) {
				$current_class            = $sig[ $i + 1 ][1];
				$consts[ $current_class ] = $consts[ $current_class ] ?? array();
				continue;
			}

			if ( T_CONST === $id && null !== $current_class && $i + 1 < $count && T_STRING === $sig[ $i + 1 ][0] ) {
				$const_name = $sig[ $i + 1 ][1];
				for ( $j = $i + 2; $j < $count; $j++ ) {
					if ( null === $sig[ $j ][0] && ';' === $sig[ $j ][1] ) {
						break;
					}
					if ( null === $sig[ $j ][0] && '=' === $sig[ $j ][1] ) {
						if ( $j + 1 < $count && T_CONSTANT_ENCAPSED_STRING === $sig[ $j + 1 ][0] ) {
							$consts[ $current_class ][ $const_name ] = $this->unquote( $sig[ $j + 1 ][1] );
						}
						break;
					}
				}
				continue;
			}

			$func = $this->call_name( $id, $text );
			if ( null === $func ) {
				continue;
			}
			// Must be a direct call `func(` and not a method / static-member / definition.
			if ( ! ( $i + 1 < $count && null === $sig[ $i + 1 ][0] && '(' === $sig[ $i + 1 ][1] ) ) {
				continue;
			}
			$prev = $i > 0 ? $sig[ $i - 1 ][0] : null;
			if ( in_array( $prev, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue;
			}

			if ( in_array( $func, self::NETWORK_FUNCS, true ) ) {
				$writes[] = array( 'kind' => 'network', 'label' => $label, 'func' => $func );
				continue;
			}

			// Collect the first-argument tokens (up to the first top-level comma or the closing paren).
			$depth = 0;
			$arg   = array();
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$t = $sig[ $j ];
				if ( null === $t[0] && '(' === $t[1] ) {
					$depth++;
					if ( 1 === $depth ) {
						continue;
					}
				}
				if ( null === $t[0] && ')' === $t[1] ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
				}
				if ( null === $t[0] && ',' === $t[1] && 1 === $depth ) {
					break;
				}
				if ( $depth >= 1 ) {
					$arg[] = $t;
				}
			}
			$writes[] = array( 'kind' => 'arg', 'label' => $label, 'class' => $current_class, 'arg' => $arg );
		}

		return array( 'consts' => $consts, 'writes' => $writes );
	}

	/**
	 * Return the bare function name for a call token, or null if it is not a write API.
	 * Handles both unqualified (`update_option`) and fully-qualified (`\update_option`) names.
	 */
	private function call_name( ?int $id, string $text ): ?string {
		if ( T_STRING === $id ) {
			$name = $text;
		} elseif ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $id ) {
			$name = ltrim( $text, '\\' );
		} else {
			return null;
		}
		return ( in_array( $name, self::WRITE_FUNCS, true ) || in_array( $name, self::NETWORK_FUNCS, true ) ) ? $name : null;
	}

	/**
	 * Resolve one write descriptor to an option name string, or throw.
	 *
	 * @param array<string,mixed>                       $w
	 * @param array<string,array<string,string>>        $class_consts
	 * @throws \RuntimeException
	 */
	private function resolve( array $w, array $class_consts ): string {
		if ( 'network' === $w['kind'] ) {
			throw new \RuntimeException( sprintf( '%s(): the option name is argument 2 for network options; the scanner does not parse it (in %s).', $w['func'], $w['label'] ) );
		}

		/** @var list<array{0:?int,1:string}> $arg */
		$arg = $w['arg'];

		if ( 1 === count( $arg ) && T_CONSTANT_ENCAPSED_STRING === $arg[0][0] ) {
			return $this->unquote( $arg[0][1] );
		}

		if ( 3 === count( $arg )
			&& ( T_STRING === $arg[0][0] || T_STATIC === $arg[0][0] )
			&& T_DOUBLE_COLON === $arg[1][0]
			&& T_STRING === $arg[2][0]
		) {
			$qualifier  = $arg[0][1];
			$const_name = $arg[2][1];
			$cls        = ( 'self' === $qualifier || 'static' === $qualifier ) ? ( $w['class'] ?? null ) : $qualifier;
			if ( null !== $cls && isset( $class_consts[ $cls ][ $const_name ] ) ) {
				return $class_consts[ $cls ][ $const_name ];
			}
			throw new \RuntimeException( sprintf( 'Unresolved option constant %s::%s in %s.', $cls ?? '(no class scope)', $const_name, $w['label'] ) );
		}

		$repr = implode( ' ', array_map( static fn( $t ) => $t[1], $arg ) );
		throw new \RuntimeException( sprintf( 'Unresolvable option-name argument "%s" in %s.', $repr, $w['label'] ) );
	}

	/** Strip surrounding quotes from a T_CONSTANT_ENCAPSED_STRING literal. */
	private function unquote( string $s ): string {
		$q = $s[0] ?? '';
		if ( ( "'" === $q || '"' === $q ) && substr( $s, -1 ) === $q ) {
			$inner = substr( $s, 1, -1 );
			return "'" === $q ? str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $inner ) : stripcslashes( $inner );
		}
		return $s;
	}
}

// CLI entry point. Skipped when the file is require()'d (e.g. under PHPUnit),
// where $argv[0] is the test runner rather than this script.
if ( 'cli' === PHP_SAPI && isset( $argv ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$files = array();
	foreach ( array_slice( $argv, 1 ) as $path ) {
		if ( is_dir( $path ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( $f->isFile() && 'php' === $f->getExtension() ) {
					$files[] = $f->getPathname();
				}
			}
		} elseif ( is_file( $path ) ) {
			$files[] = $path;
		}
	}

	try {
		$scanner = new Persistent_Option_Scanner();
		$names   = $scanner->scan_files( $files );
	} catch ( \RuntimeException $e ) {
		fwrite( STDERR, 'verify-metrics: ' . $e->getMessage() . "\n" );
		exit( 3 );
	}

	echo implode( ' ', $names ) . "\n";
	exit( 0 );
}
