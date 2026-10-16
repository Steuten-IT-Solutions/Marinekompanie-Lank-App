<?php
/**
 * Galerie aus Google Drive.
 *
 * Ordnerstruktur in Drive:  Galerie → Jahr → Album → Bilder
 *                                                   └ intern → Bilder nur für Mitglieder
 *
 * Ein Sync (stündlich per WP-Cron oder per Knopf) gleicht den Index in eigenen
 * Tabellen ab. Danach werden die Bilder einmalig heruntergeladen und in zwei
 * Größen (400 px / 1600 px) auf dem eigenen Server abgelegt:
 *
 * - öffentliche Bilder: uploads/mkl-gallery/<album>/<key>-<größe>.jpg (statisch, schnell)
 * - interne Bilder:     uploads/mkl-gallery-intern/<key>-<größe>.jpg, ausgeliefert
 *                       nur über /app/foto/<id>/<größe> nach Prüfung von mkl_member.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Gallery {

	const ROOT_OPTION   = 'mkl_gallery_root';
	const STATUS_OPTION = 'mkl_gallery_status';
	const CRON_HOOK     = 'mkl_gallery_cron';
	const INTERN_FOLDER = 'intern';

	const SIZES = array(
		'thumb' => 400,
		'large' => 1600,
	);

	const PENDING = 'pending';
	const READY   = 'ready';
	const FAILED  = 'error';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( get_option( self::ROOT_OPTION ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function cron() {
		self::sync();
		self::process_pending( 25 );
	}

	public static function albums_table() {
		return MKL_DB::table( 'albums' );
	}

	public static function photos_table() {
		return MKL_DB::table( 'photos' );
	}

	/* ------------------------------------------------------------------ */
	/* Sync mit Google Drive                                               */
	/* ------------------------------------------------------------------ */

	/** @return array|WP_Error Statistik */
	public static function sync() {
		$root = get_option( self::ROOT_OPTION, '' );
		if ( ! $root ) {
			return self::fail( 'Es ist noch kein Galerie-Ordner eingetragen.' );
		}
		$years = MKL_Drive::list_children( $root );
		if ( is_wp_error( $years ) ) {
			return self::fail( $years->get_error_message() );
		}

		global $wpdb;
		$seen_albums = array();
		$seen_photos = array();
		$stats       = array(
			'albums' => 0,
			'photos' => 0,
			'new'    => 0,
		);

		foreach ( array_filter( $years, array( 'MKL_Drive', 'is_folder' ) ) as $year ) {
			$albums = MKL_Drive::list_children( $year['id'] );
			if ( is_wp_error( $albums ) ) {
				return self::fail( $albums->get_error_message() );
			}
			foreach ( array_filter( $albums, array( 'MKL_Drive', 'is_folder' ) ) as $album ) {
				$children = MKL_Drive::list_children( $album['id'] );
				if ( is_wp_error( $children ) ) {
					return self::fail( $children->get_error_message() );
				}
				$images = array();
				foreach ( $children as $f ) {
					if ( MKL_Drive::is_image( $f ) ) {
						$images[] = array( $f, 0 );
					} elseif ( MKL_Drive::is_folder( $f ) && self::INTERN_FOLDER === mb_strtolower( trim( $f['name'] ) ) ) {
						$intern = MKL_Drive::list_children( $f['id'] );
						if ( is_wp_error( $intern ) ) {
							return self::fail( $intern->get_error_message() );
						}
						foreach ( array_filter( $intern, array( 'MKL_Drive', 'is_image' ) ) as $img ) {
							$images[] = array( $img, 1 );
						}
					}
				}
				if ( ! $images ) {
					continue;
				}

				$album_id      = self::upsert_album( $album, $year['name'], $images );
				$seen_albums[] = $album_id;
				++$stats['albums'];
				foreach ( $images as $pair ) {
					list( $file, $is_intern ) = $pair;
					$result                   = self::upsert_photo( $album_id, $file, $is_intern );
					$seen_photos[]            = $result['id'];
					$stats['photos']++;
					if ( $result['new'] ) {
						$stats['new']++;
					}
				}
			}
		}

		// Was in Drive nicht mehr vorhanden ist, verschwindet samt Dateien.
		$p    = self::photos_table();
		$a    = self::albums_table();
		$keep = $seen_photos ? implode( ',', array_map( 'intval', $seen_photos ) ) : '0';
		foreach ( $wpdb->get_results( "SELECT * FROM $p WHERE id NOT IN ($keep)", ARRAY_A ) as $gone ) { // phpcs:ignore WordPress.DB.PreparedSQL
			self::delete_files( $gone );
			$wpdb->delete( $p, array( 'id' => $gone['id'] ) );
		}
		$keep = $seen_albums ? implode( ',', array_map( 'intval', $seen_albums ) ) : '0';
		$wpdb->query( "DELETE FROM $a WHERE id NOT IN ($keep)" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$stats['pending'] = self::count_pending();
		update_option(
			self::STATUS_OPTION,
			array_merge(
				$stats,
				array(
					'last_sync' => time(),
					'error'     => '',
				)
			),
			false
		);
		return $stats;
	}

	private static function fail( $message ) {
		$status          = (array) get_option( self::STATUS_OPTION, array() );
		$status['error'] = $message;
		$status['last_sync'] = time();
		update_option( self::STATUS_OPTION, $status, false );
		return new WP_Error( 'mkl_gallery', $message );
	}

	private static function drive_time( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		// EXIF-Zeit „2026:06:27 18:45:10“ oder RFC 3339 von Drive.
		$value = preg_replace( '/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $value );
		$ts    = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	private static function upsert_album( array $folder, $year, array $images ) {
		global $wpdb;
		$t     = self::albums_table();
		$dates = array();
		foreach ( $images as $pair ) {
			$taken = self::drive_time( isset( $pair[0]['imageMediaMetadata']['time'] ) ? $pair[0]['imageMediaMetadata']['time'] : '' );
			if ( $taken ) {
				$dates[] = $taken;
			}
		}
		$sort = $dates ? min( $dates ) : self::drive_time( $folder['createdTime'] );
		$data = array(
			'drive_id'  => $folder['id'],
			'year'      => mb_substr( trim( $year ), 0, 20 ),
			'title'     => mb_substr( trim( $folder['name'] ), 0, 191 ),
			'sort_date' => $sort ? $sort : current_time( 'mysql', true ),
			'synced_at' => current_time( 'mysql', true ),
		);
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE drive_id = %s", $folder['id'] ) );
		if ( $id ) {
			$wpdb->update( $t, $data, array( 'id' => $id ) );
			return (int) $id;
		}
		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	/** @return array{id:int,new:bool} */
	private static function upsert_photo( $album_id, array $f, $is_intern ) {
		global $wpdb;
		$t    = self::photos_table();
		$meta = isset( $f['imageMediaMetadata'] ) ? $f['imageMediaMetadata'] : array();
		$md5  = isset( $f['md5Checksum'] ) ? $f['md5Checksum'] : md5( $f['id'] . ( isset( $f['modifiedTime'] ) ? $f['modifiedTime'] : '' ) );
		$data = array(
			'album_id'  => $album_id,
			'name'      => mb_substr( $f['name'], 0, 255 ),
			'is_intern' => $is_intern,
			'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'taken_at'  => self::drive_time( isset( $meta['time'] ) ? $meta['time'] : '' ),
		);
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE drive_file_id = %s", $f['id'] ), ARRAY_A );
		if ( ! $row ) {
			$wpdb->insert(
				$t,
				array_merge(
					$data,
					array(
						'drive_file_id' => $f['id'],
						'md5'           => $md5,
						'file_key'      => bin2hex( random_bytes( 16 ) ),
						'status'        => self::PENDING,
					)
				)
			);
			return array(
				'id'  => (int) $wpdb->insert_id,
				'new' => true,
			);
		}
		// Neuer Inhalt oder zwischen „intern“ und öffentlich verschoben: neu erzeugen.
		if ( $row['md5'] !== $md5 || (int) $row['is_intern'] !== (int) $is_intern || (int) $row['album_id'] !== (int) $album_id ) {
			self::delete_files( $row );
			$data['md5']      = $md5;
			$data['file_key'] = bin2hex( random_bytes( 16 ) );
			$data['status']   = self::PENDING;
			$data['error']    = '';
		}
		$wpdb->update( $t, $data, array( 'id' => $row['id'] ) );
		return array(
			'id'  => (int) $row['id'],
			'new' => false,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Bilder erzeugen                                                     */
	/* ------------------------------------------------------------------ */

	public static function count_pending() {
		global $wpdb;
		$t = self::photos_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = %s", self::PENDING ) );
	}

	/** Erzeugt Bilder, bis das Zeitbudget aufgebraucht ist. Neueste Alben zuerst. */
	public static function process_pending( $budget_seconds = 20 ) {
		global $wpdb;
		$p     = self::photos_table();
		$a     = self::albums_table();
		$start = microtime( true );
		$done  = 0;
		while ( microtime( true ) - $start < $budget_seconds ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT p.* FROM $p p JOIN $a a ON a.id = p.album_id WHERE p.status = %s ORDER BY a.sort_date DESC, p.taken_at ASC, p.id ASC LIMIT 1",
					self::PENDING
				),
				ARRAY_A
			);
			if ( ! $row ) {
				break;
			}
			self::generate( $row );
			++$done;
		}
		$status            = (array) get_option( self::STATUS_OPTION, array() );
		$status['pending'] = self::count_pending();
		update_option( self::STATUS_OPTION, $status, false );
		return $done;
	}

	/** @return bool */
	public static function generate( array $photo ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		wp_raise_memory_limit( 'image' );

		$tmp = MKL_Drive::download( $photo['drive_file_id'] );
		if ( is_wp_error( $tmp ) ) {
			return self::mark_failed( $photo, $tmp->get_error_message() );
		}
		$dir = self::dir( $photo );
		if ( ! wp_mkdir_p( $dir ) ) {
			wp_delete_file( $tmp );
			return self::mark_failed( $photo, 'Verzeichnis kann nicht angelegt werden.' );
		}
		self::protect_dirs();

		$size_info = null;
		foreach ( self::SIZES as $size => $max ) {
			$editor = wp_get_image_editor( $tmp );
			if ( is_wp_error( $editor ) ) {
				wp_delete_file( $tmp );
				return self::mark_failed( $photo, $editor->get_error_message() );
			}
			if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
				$editor->maybe_exif_rotate();
			}
			$editor->resize( $max, $max, false );
			$editor->set_quality( 'thumb' === $size ? 78 : 84 );
			$saved = $editor->save( self::path( $photo, $size ), 'image/jpeg' );
			if ( is_wp_error( $saved ) ) {
				wp_delete_file( $tmp );
				return self::mark_failed( $photo, $saved->get_error_message() );
			}
			if ( 'large' === $size ) {
				$size_info = $editor->get_size();
			}
		}
		wp_delete_file( $tmp );

		$wpdb->update(
			self::photos_table(),
			array(
				'status' => self::READY,
				'error'  => '',
				'width'  => $size_info ? (int) $size_info['width'] : (int) $photo['width'],
				'height' => $size_info ? (int) $size_info['height'] : (int) $photo['height'],
			),
			array( 'id' => $photo['id'] )
		);
		return true;
	}

	private static function mark_failed( array $photo, $message ) {
		global $wpdb;
		$wpdb->update(
			self::photos_table(),
			array(
				'status' => self::FAILED,
				'error'  => mb_substr( $message, 0, 255 ),
			),
			array( 'id' => $photo['id'] )
		);
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Dateien                                                             */
	/* ------------------------------------------------------------------ */

	private static function base( $intern ) {
		$up = wp_upload_dir( null, false );
		return array(
			'dir' => trailingslashit( $up['basedir'] ) . ( $intern ? 'mkl-gallery-intern' : 'mkl-gallery' ),
			'url' => trailingslashit( $up['baseurl'] ) . 'mkl-gallery',
		);
	}

	private static function dir( array $photo ) {
		$base = self::base( (int) $photo['is_intern'] );
		return (int) $photo['is_intern'] ? $base['dir'] : $base['dir'] . '/' . (int) $photo['album_id'];
	}

	public static function path( array $photo, $size ) {
		return self::dir( $photo ) . '/' . $photo['file_key'] . '-' . $size . '.jpg';
	}

	/** Verzeichnisauflistung verhindern; intern zusätzlich direkten Zugriff sperren (Apache). */
	private static function protect_dirs() {
		foreach ( array( 0, 1 ) as $intern ) {
			$dir = self::base( $intern )['dir'];
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php // Kein Zugriff.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( $intern && ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	public static function delete_files( array $photo ) {
		foreach ( array_keys( self::SIZES ) as $size ) {
			$file = self::path( $photo, $size );
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/** URL eines Bildes: öffentliche fertige Bilder direkt als Datei, sonst über /app/foto/. */
	public static function url( array $photo, $size ) {
		if ( ! (int) $photo['is_intern'] && self::READY === $photo['status'] ) {
			return self::base( 0 )['url'] . '/' . (int) $photo['album_id'] . '/' . $photo['file_key'] . '-' . $size . '.jpg';
		}
		return MKL_App_Page::app_url() . 'foto/' . (int) $photo['id'] . '/' . $size;
	}

	/**
	 * Liefert ein Bild über /app/foto/<id>/<größe> aus (für interne oder noch nicht erzeugte Bilder).
	 */
	public static function serve( $id, $size ) {
		global $wpdb;
		$t     = self::photos_table();
		$photo = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		if ( ! $photo || ! isset( self::SIZES[ $size ] ) ) {
			status_header( 404 );
			return;
		}
		$intern = (bool) (int) $photo['is_intern'];
		if ( $intern && ! MKL_Roles::is_member() ) {
			status_header( is_user_logged_in() ? 403 : 401 );
			return;
		}
		if ( self::READY !== $photo['status'] || ! file_exists( self::path( $photo, $size ) ) ) {
			if ( ! self::generate( $photo ) ) {
				status_header( 502 );
				return;
			}
		}
		$file = self::path( $photo, $size );
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( $intern ? 'Cache-Control: private, max-age=86400' : 'Cache-Control: public, max-age=31536000, immutable' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ------------------------------------------------------------------ */
	/* Abfragen                                                            */
	/* ------------------------------------------------------------------ */

	private static function visibility_sql( $member ) {
		return $member ? '' : ' AND p.is_intern = 0';
	}

	/** Jahre mit Alben, jeweils mit Titelbild und Anzahl. Gäste sehen nur öffentliche Bilder. */
	public static function overview( $member ) {
		global $wpdb;
		$p    = self::photos_table();
		$a    = self::albums_table();
		$vis  = self::visibility_sql( $member );
		$rows = $wpdb->get_results(
			"SELECT a.id, a.year, a.title, a.sort_date, COUNT(p.id) AS cnt, SUM(p.is_intern) AS intern_cnt
			 FROM $a a JOIN $p p ON p.album_id = a.id
			 WHERE p.status <> 'error' $vis
			 GROUP BY a.id, a.year, a.title, a.sort_date
			 ORDER BY a.year DESC, a.sort_date DESC", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$years = array();
		foreach ( $rows as $r ) {
			$cover = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $p p WHERE p.album_id = %d AND p.status <> 'error' $vis ORDER BY p.is_intern ASC, (p.status = 'ready') DESC, p.taken_at ASC, p.name ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
					$r['id']
				),
				ARRAY_A
			);
			$years[ $r['year'] ][] = array(
				'id'           => (int) $r['id'],
				'title'        => $r['title'],
				'count'        => (int) $r['cnt'],
				'intern_count' => $member ? (int) $r['intern_cnt'] : 0,
				'cover'        => $cover ? self::url( $cover, 'thumb' ) : null,
			);
		}
		$out = array();
		foreach ( $years as $year => $albums ) {
			$out[] = array(
				'year'   => (string) $year,
				'albums' => $albums,
			);
		}
		usort(
			$out,
			function ( $x, $y ) {
				return strnatcmp( $y['year'], $x['year'] );
			}
		);
		return $out;
	}

	/** @return array|null Album mit sichtbaren Fotos. */
	public static function album( $id, $member ) {
		global $wpdb;
		$p     = self::photos_table();
		$a     = self::albums_table();
		$album = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $a WHERE id = %d", $id ), ARRAY_A );
		if ( ! $album ) {
			return null;
		}
		$vis    = self::visibility_sql( $member );
		$photos = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $p p WHERE p.album_id = %d AND p.status <> 'error' $vis ORDER BY p.taken_at IS NULL, p.taken_at ASC, p.name ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$id
			),
			ARRAY_A
		);
		if ( ! $photos ) {
			return null;
		}
		return array(
			'id'     => (int) $album['id'],
			'title'  => $album['title'],
			'year'   => $album['year'],
			'photos' => array_map(
				function ( $ph ) {
					return array(
						'id'     => (int) $ph['id'],
						'thumb'  => self::url( $ph, 'thumb' ),
						'large'  => self::url( $ph, 'large' ),
						'width'  => (int) $ph['width'],
						'height' => (int) $ph['height'],
						'intern' => (bool) (int) $ph['is_intern'],
						'name'   => $ph['name'],
					);
				},
				$photos
			),
		);
	}
}
