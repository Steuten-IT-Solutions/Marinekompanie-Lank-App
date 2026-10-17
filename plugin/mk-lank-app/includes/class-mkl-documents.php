<?php
/**
 * Dokumente für Mitglieder (zunächst nur die Satzung).
 *
 * - Nur die aktuelle Fassung wird aufbewahrt; eine neue Fassung ersetzt die alte.
 * - Die PDF liegt in uploads/mkl-private/ mit zufälligem Dateinamen. Direkter Abruf
 *   ist per .htaccess gesperrt (wirkt nur unter Apache, siehe README).
 * - Ausgeliefert wird nur über /app/dokument/<key>?v=<version> mit Rechteprüfung.
 *   Die Adresse liegt im Bereich des Service Workers, damit die PDF offline verfügbar ist.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Documents {

	/** Höchstgröße einer PDF: 20 MB. */
	const MAX_BYTES = 20971520;

	/** Bekannte Dokumente: key => Titel. */
	const DOCS = array(
		'satzung' => 'Satzung',
	);

	public static function exists( $key ) {
		return isset( self::DOCS[ $key ] );
	}

	private static function option( $key ) {
		return 'mkl_doc_' . $key;
	}

	/** Gespeicherte Angaben zu einem Dokument, oder null, wenn keins hinterlegt ist. */
	private static function meta( $key ) {
		$meta = get_option( self::option( $key ) );
		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! file_exists( self::dir() . '/' . $meta['file'] ) ) {
			return null;
		}
		return $meta;
	}

	/** Alle hinterlegten Dokumente für die App. */
	public static function all() {
		$out = array();
		foreach ( array_keys( self::DOCS ) as $key ) {
			$doc = self::get( $key );
			if ( $doc ) {
				$out[] = $doc;
			}
		}
		return $out;
	}

	public static function get( $key ) {
		$meta = self::exists( $key ) ? self::meta( $key ) : null;
		if ( ! $meta ) {
			return null;
		}
		return array(
			'key'        => $key,
			'title'      => self::DOCS[ $key ],
			'date'       => $meta['date'],
			'size'       => (int) $meta['size'],
			'version'    => $meta['version'],
			'url'        => MKL_App_Page::app_url() . 'dokument/' . $key . '?v=' . $meta['version'],
			'updated_at' => $meta['updated_at'],
		);
	}

	/**
	 * Speichert eine neue Fassung und löscht die alte.
	 *
	 * @param string $bytes Inhalt der PDF.
	 * @param string $date  Stand (Y-m-d).
	 * @return array|WP_Error Das Dokument wie in get().
	 */
	public static function store( $key, $bytes, $date ) {
		if ( ! self::exists( $key ) ) {
			return self::error( 'Unbekanntes Dokument.', 404 );
		}
		$size = strlen( $bytes );
		if ( 0 === $size ) {
			return self::error(
				sprintf(
					'Es ist keine Datei angekommen. Ist sie größer als das Upload-Limit des Servers (%s)?',
					size_format( wp_max_upload_size() )
				)
			);
		}
		if ( $size > self::MAX_BYTES ) {
			return self::error( sprintf( 'Die PDF darf höchstens %s groß sein.', size_format( self::MAX_BYTES ) ) );
		}
		if ( 0 !== strncmp( $bytes, '%PDF-', 5 ) ) {
			return self::error( 'Die Datei ist keine PDF.' );
		}

		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::error( 'Das Verzeichnis für Dokumente konnte nicht angelegt werden.', 500 );
		}
		self::protect_dir( $dir );

		$file = $key . '-' . wp_generate_password( 20, false ) . '.pdf';
		if ( false === file_put_contents( $dir . '/' . $file, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return self::error( 'Die Datei konnte nicht gespeichert werden.', 500 );
		}

		$old = self::meta( $key );
		update_option(
			self::option( $key ),
			array(
				'file'       => $file,
				'date'       => $date,
				'size'       => $size,
				'version'    => substr( hash( 'sha256', $bytes ), 0, 16 ),
				'updated_at' => gmdate( 'c' ),
			),
			false
		);
		if ( $old && $old['file'] !== $file ) {
			wp_delete_file( $dir . '/' . $old['file'] );
		}
		return self::get( $key );
	}

	/** Ändert nur den Stand. @return array|WP_Error */
	public static function set_date( $key, $date ) {
		$meta = self::exists( $key ) ? self::meta( $key ) : null;
		if ( ! $meta ) {
			return self::error( 'Es ist noch keine Datei hinterlegt.', 404 );
		}
		$meta['date'] = $date;
		update_option( self::option( $key ), $meta, false );
		return self::get( $key );
	}

	/** @return bool false, wenn nichts hinterlegt war. */
	public static function delete( $key ) {
		$meta = self::exists( $key ) ? self::meta( $key ) : null;
		delete_option( self::option( $key ) );
		if ( ! $meta ) {
			return false;
		}
		wp_delete_file( self::dir() . '/' . $meta['file'] );
		return true;
	}

	/**
	 * Liefert die PDF über /app/dokument/<key> aus – nur für Mitglieder.
	 */
	public static function serve( $key ) {
		if ( ! MKL_Roles::is_member() ) {
			status_header( is_user_logged_in() ? 403 : 401 );
			return;
		}
		$meta = self::exists( $key ) ? self::meta( $key ) : null;
		if ( ! $meta ) {
			status_header( 404 );
			return;
		}
		$file     = self::dir() . '/' . $meta['file'];
		$filename = self::DOCS[ $key ] . '.pdf';
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Cache-Control: private, no-cache' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public static function dir() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . 'mkl-private';
	}

	/** Direkten Zugriff sperren (Apache) und Verzeichnisauflistung verhindern. */
	private static function protect_dir( $dir ) {
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Kein Zugriff.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	private static function error( $message, $status = 400 ) {
		return new WP_Error( 'mkl_invalid', $message, array( 'status' => $status ) );
	}
}
