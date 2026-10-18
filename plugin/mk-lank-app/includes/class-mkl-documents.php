<?php
/**
 * Dokumente für Mitglieder: Satzung, Protokolle, Formulare usw.
 *
 * - Erlaubt sind PDF, Office-Dateien (Word, Excel, PowerPoint, OpenDocument) und
 *   Bilder (JPG, PNG). Geprüft werden Dateiendung und Dateisignatur.
 * - Je Dokument: Titel, Kategorie, Stand und Sichtbarkeit (alle Mitglieder oder nur
 *   Spieße/Admins). Eine neue Fassung ersetzt die alte Datei.
 * - Die Dateien liegen in uploads/mkl-private/ mit zufälligem Namen. Direkter Abruf
 *   ist per .htaccess gesperrt (wirkt nur unter Apache, siehe README).
 * - Ausgeliefert wird nur über /app/dokument/<id>?v=<version> mit Rechteprüfung.
 *   Die Adresse liegt im Bereich des Service Workers; was einmal geöffnet wurde,
 *   ist danach offline verfügbar.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Documents {

	/** Höchstgröße einer Datei: 20 MB. */
	const MAX_BYTES = 20971520;

	const VISIBLE_MEMBERS = 'mitglieder';
	const VISIBLE_SPIESS  = 'spiess';

	/**
	 * Erlaubte Dateitypen: Endung => [MIME-Typ, Signatur, Art].
	 * Art: pdf/bild werden in der App angezeigt, datei nur heruntergeladen.
	 */
	const TYPES = array(
		'pdf'  => array( 'application/pdf', '%PDF-', 'pdf' ),
		'jpg'  => array( 'image/jpeg', "\xFF\xD8\xFF", 'bild' ),
		'jpeg' => array( 'image/jpeg', "\xFF\xD8\xFF", 'bild' ),
		'png'  => array( 'image/png', "\x89PNG\r\n\x1A\n", 'bild' ),
		'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', "PK\x03\x04", 'datei' ),
		'xlsx' => array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "PK\x03\x04", 'datei' ),
		'pptx' => array( 'application/vnd.openxmlformats-officedocument.presentationml.presentation', "PK\x03\x04", 'datei' ),
		'odt'  => array( 'application/vnd.oasis.opendocument.text', "PK\x03\x04", 'datei' ),
		'ods'  => array( 'application/vnd.oasis.opendocument.spreadsheet', "PK\x03\x04", 'datei' ),
		'odp'  => array( 'application/vnd.oasis.opendocument.presentation', "PK\x03\x04", 'datei' ),
		'doc'  => array( 'application/msword', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'datei' ),
		'xls'  => array( 'application/vnd.ms-excel', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'datei' ),
		'ppt'  => array( 'application/vnd.ms-powerpoint', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'datei' ),
	);

	public static function table() {
		return MKL_DB::table( 'documents' );
	}

	public static function categories_table() {
		return MKL_DB::table( 'doc_categories' );
	}

	/* ------------------------------------------------------------------ */
	/* Lesen                                                               */
	/* ------------------------------------------------------------------ */

	/** Darf das aktuelle Konto Dokumente dieser Sichtbarkeit sehen? */
	public static function can_see( $visibility, $user_id = null ) {
		if ( ! MKL_Roles::is_member( $user_id ) ) {
			return false;
		}
		return self::VISIBLE_SPIESS !== $visibility || MKL_Roles::is_spiess( $user_id );
	}

	/** Alle Dokumente, die das aktuelle Konto sehen darf, nach Titel. */
	public static function visible() {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT * FROM $t ORDER BY title ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $r ) {
			if ( self::can_see( $r['visibility'] ) ) {
				$out[] = self::format( $r );
			}
		}
		return $out;
	}

	public static function get( $id ) {
		$row = self::row( $id );
		return $row ? self::format( $row ) : null;
	}

	private static function row( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function format( array $r ) {
		$type = self::TYPES[ $r['ext'] ];
		return array(
			'id'          => (int) $r['id'],
			'title'       => $r['title'],
			'category_id' => $r['category_id'] ? (int) $r['category_id'] : null,
			'date'        => $r['doc_date'],
			'visibility'  => $r['visibility'],
			'ext'         => $r['ext'],
			'kind'        => $type[2],
			'mime'        => $type[0],
			'size'        => (int) $r['size'],
			'version'     => $r['version'],
			'url'         => MKL_App_Page::app_url() . 'dokument/' . (int) $r['id'] . '?v=' . $r['version'],
			'updated_at'  => gmdate( 'c', strtotime( $r['updated_at'] . ' UTC' ) ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Schreiben                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Legt ein Dokument an.
	 *
	 * @param array  $meta  title, category_id, date, visibility.
	 * @param string $bytes Inhalt der Datei.
	 * @param string $name  Ursprünglicher Dateiname (für die Endung).
	 * @return array|WP_Error
	 */
	public static function create( array $meta, $bytes, $name, $by ) {
		global $wpdb;
		$meta = self::validate_meta( $meta, true );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$file = self::store_file( $bytes, $name );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array_merge(
				$meta,
				$file,
				array(
					'uploaded_by' => $by,
					'created_at'  => $now,
					'updated_at'  => $now,
				)
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/** Ersetzt die Datei eines Dokuments (neue Fassung). @return array|WP_Error */
	public static function replace_file( $id, $bytes, $name, $by ) {
		global $wpdb;
		$old = self::row( $id );
		if ( ! $old ) {
			return self::error( 'Dokument nicht gefunden.', 404 );
		}
		$file = self::store_file( $bytes, $name );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$wpdb->update(
			self::table(),
			array_merge(
				$file,
				array(
					'uploaded_by' => $by,
					'updated_at'  => current_time( 'mysql', true ),
				)
			),
			array( 'id' => $id )
		);
		self::delete_file( $old['file'] );
		return self::get( $id );
	}

	/** Ändert Titel, Kategorie, Stand oder Sichtbarkeit. @return array|WP_Error */
	public static function update( $id, array $meta ) {
		global $wpdb;
		if ( ! self::row( $id ) ) {
			return self::error( 'Dokument nicht gefunden.', 404 );
		}
		$meta = self::validate_meta( $meta, false );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		if ( $meta ) {
			$meta['updated_at'] = current_time( 'mysql', true );
			$wpdb->update( self::table(), $meta, array( 'id' => $id ) );
		}
		return self::get( $id );
	}

	/** @return bool false, wenn es das Dokument nicht gab. */
	public static function delete( $id ) {
		global $wpdb;
		$row = self::row( $id );
		if ( ! $row ) {
			return false;
		}
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		self::delete_file( $row['file'] );
		return true;
	}

	/**
	 * Prüft die Angaben. Beim Anlegen sind Titel und Stand Pflicht.
	 *
	 * @return array|WP_Error Spalten für die Datenbank.
	 */
	private static function validate_meta( array $meta, $creating ) {
		$out = array();
		if ( $creating || array_key_exists( 'title', $meta ) ) {
			$title = trim( sanitize_text_field( (string) ( isset( $meta['title'] ) ? $meta['title'] : '' ) ) );
			if ( '' === $title ) {
				return self::error( 'Bitte einen Titel angeben.' );
			}
			if ( mb_strlen( $title ) > 191 ) {
				return self::error( 'Der Titel ist zu lang.' );
			}
			$out['title'] = $title;
		}
		if ( $creating || array_key_exists( 'category_id', $meta ) ) {
			$cat = isset( $meta['category_id'] ) && $meta['category_id'] ? (int) $meta['category_id'] : null;
			if ( $cat && ! self::get_category( $cat ) ) {
				return self::error( 'Kategorie nicht gefunden.' );
			}
			$out['category_id'] = $cat;
		}
		if ( $creating || array_key_exists( 'date', $meta ) ) {
			$date = isset( $meta['date'] ) ? (string) $meta['date'] : '';
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return self::error( 'Bitte einen gültigen Stand angeben.' );
			}
			$out['doc_date'] = $date;
		}
		if ( $creating || array_key_exists( 'visibility', $meta ) ) {
			$vis = isset( $meta['visibility'] ) && '' !== $meta['visibility'] ? (string) $meta['visibility'] : self::VISIBLE_MEMBERS;
			if ( ! in_array( $vis, array( self::VISIBLE_MEMBERS, self::VISIBLE_SPIESS ), true ) ) {
				return self::error( 'Ungültige Sichtbarkeit.' );
			}
			$out['visibility'] = $vis;
		}
		return $out;
	}

	/**
	 * Prüft und speichert eine Datei.
	 *
	 * @return array|WP_Error Spalten file, ext, size, version.
	 */
	private static function store_file( $bytes, $name ) {
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
			return self::error( sprintf( 'Die Datei darf höchstens %s groß sein.', size_format( self::MAX_BYTES ) ) );
		}
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			return self::error( 'Erlaubt sind PDF, Word, Excel, PowerPoint, OpenDocument sowie JPG- und PNG-Bilder.' );
		}
		$signature = self::TYPES[ $ext ][1];
		if ( 0 !== strncmp( $bytes, $signature, strlen( $signature ) ) ) {
			return self::error( sprintf( 'Die Datei ist keine gültige .%s-Datei.', $ext ) );
		}

		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::error( 'Das Verzeichnis für Dokumente konnte nicht angelegt werden.', 500 );
		}
		self::protect_dir( $dir );
		$file = 'dok-' . wp_generate_password( 20, false ) . '.' . $ext;
		if ( false === file_put_contents( $dir . '/' . $file, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return self::error( 'Die Datei konnte nicht gespeichert werden.', 500 );
		}
		return array(
			'file'    => $file,
			'ext'     => $ext,
			'size'    => $size,
			'version' => substr( hash( 'sha256', $bytes ), 0, 16 ),
		);
	}

	private static function delete_file( $file ) {
		if ( $file && file_exists( self::dir() . '/' . $file ) ) {
			wp_delete_file( self::dir() . '/' . $file );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Kategorien                                                          */
	/* ------------------------------------------------------------------ */

	public static function categories() {
		global $wpdb;
		$t    = self::categories_table();
		$rows = $wpdb->get_results( "SELECT * FROM $t ORDER BY sort ASC, title ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'format_category' ), $rows );
	}

	public static function get_category( $id ) {
		global $wpdb;
		$t   = self::categories_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::format_category( $row ) : null;
	}

	/** @return array|WP_Error */
	public static function save_category( $id, $title, $sort ) {
		global $wpdb;
		$title = trim( sanitize_text_field( (string) $title ) );
		if ( '' === $title ) {
			return self::error( 'Bitte einen Namen für die Kategorie angeben.' );
		}
		if ( mb_strlen( $title ) > 191 ) {
			return self::error( 'Der Name ist zu lang.' );
		}
		if ( $id ) {
			if ( ! self::get_category( $id ) ) {
				return self::error( 'Kategorie nicht gefunden.', 404 );
			}
			$data = array( 'title' => $title );
			if ( null !== $sort ) {
				$data['sort'] = (int) $sort;
			}
			$wpdb->update( self::categories_table(), $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert(
				self::categories_table(),
				array(
					'title' => $title,
					'sort'  => (int) $sort,
				),
				array( '%s', '%d' )
			);
			$id = (int) $wpdb->insert_id;
		}
		return self::get_category( $id );
	}

	/** Nur leere Kategorien lassen sich löschen. @return true|WP_Error */
	public static function delete_category( $id ) {
		global $wpdb;
		if ( ! self::get_category( $id ) ) {
			return self::error( 'Kategorie nicht gefunden.', 404 );
		}
		$t     = self::table();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE category_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $count > 0 ) {
			return self::error( sprintf( 'In der Kategorie liegen noch %d Dokument(e). Bitte zuerst verschieben oder löschen.', $count ) );
		}
		$wpdb->delete( self::categories_table(), array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	private static function format_category( array $r ) {
		return array(
			'id'    => (int) $r['id'],
			'title' => $r['title'],
			'sort'  => (int) $r['sort'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Auslieferung                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Liefert eine Datei über /app/dokument/<id> aus – nur an Konten, die sie sehen dürfen.
	 * PDFs und Bilder inline, Office-Dateien als Download.
	 */
	public static function serve( $id ) {
		$row = self::row( $id );
		if ( ! MKL_Roles::is_member() ) {
			status_header( is_user_logged_in() ? 403 : 401 );
			return;
		}
		if ( ! $row || ! self::can_see( $row['visibility'] ) || ! file_exists( self::dir() . '/' . $row['file'] ) ) {
			status_header( 404 );
			return;
		}
		$file  = self::dir() . '/' . $row['file'];
		$type  = self::TYPES[ $row['ext'] ];
		$name  = self::download_name( $row['title'], $row['ext'] );
		$mode  = 'datei' === $type[2] ? 'attachment' : 'inline';
		header( 'Content-Type: ' . $type[0] );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: ' . $mode . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode( $row['title'] . '.' . $row['ext'] ) );
		header( 'Cache-Control: private, no-cache' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** ASCII-Dateiname für ältere Browser, z. B. „Protokoll Maerz.pdf“. */
	private static function download_name( $title, $ext ) {
		$base = strtr( $title, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss' ) );
		$base = preg_replace( '/[^A-Za-z0-9 ._-]+/', '', remove_accents( $base ) );
		$base = trim( $base );
		return ( '' === $base ? 'Dokument' : $base ) . '.' . $ext;
	}

	/* ------------------------------------------------------------------ */
	/* Ablage                                                              */
	/* ------------------------------------------------------------------ */

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

	/**
	 * Übernimmt die Satzung aus Version 0.4 (Option mkl_doc_satzung) als normales Dokument.
	 */
	public static function migrate_statute() {
		global $wpdb;
		$old = get_option( 'mkl_doc_satzung' );
		if ( ! is_array( $old ) || empty( $old['file'] ) ) {
			delete_option( 'mkl_doc_satzung' );
			return;
		}
		$path = self::dir() . '/' . $old['file'];
		if ( file_exists( $path ) ) {
			$now = current_time( 'mysql', true );
			$wpdb->insert(
				self::table(),
				array(
					'title'       => 'Satzung',
					'category_id' => null,
					'doc_date'    => $old['date'],
					'visibility'  => self::VISIBLE_MEMBERS,
					'file'        => $old['file'],
					'ext'         => 'pdf',
					'size'        => (int) $old['size'],
					'version'     => $old['version'],
					'uploaded_by' => 0,
					'created_at'  => $now,
					'updated_at'  => $now,
				)
			);
		}
		delete_option( 'mkl_doc_satzung' );
	}

	private static function error( $message, $status = 400 ) {
		return new WP_Error( 'mkl_invalid', $message, array( 'status' => $status ) );
	}
}
