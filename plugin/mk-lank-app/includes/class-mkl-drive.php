<?php
/**
 * Lesender Zugriff auf Google Drive über ein Dienstkonto (Service Account).
 *
 * Der JSON-Schlüssel des Dienstkontos wird in den Einstellungen hinterlegt und
 * verlässt nie den Server. Der Galerie-Ordner muss in Google Drive für die
 * E-Mail-Adresse des Dienstkontos freigegeben sein (Betrachter genügt).
 *
 * Ohne Bibliothek: Das Zugriffstoken wird per signiertem JWT (RS256) geholt.
 */

defined( 'ABSPATH' ) || exit;

class MKL_Drive {

	const OPTION_KEY   = 'mkl_drive_service_account';
	const SCOPE        = 'https://www.googleapis.com/auth/drive.readonly';
	const TOKEN_URI    = 'https://oauth2.googleapis.com/token';
	const FILES_URL    = 'https://www.googleapis.com/drive/v3/files';
	const FOLDER_MIME  = 'application/vnd.google-apps.folder';
	const TOKEN_CACHE  = 'mkl_drive_token';

	/** @return array{client_email:string, private_key:string}|null */
	public static function service_account() {
		$json = get_option( self::OPTION_KEY, '' );
		$data = $json ? json_decode( $json, true ) : null;
		return is_array( $data ) && ! empty( $data['client_email'] ) && ! empty( $data['private_key'] ) ? $data : null;
	}

	public static function client_email() {
		$sa = self::service_account();
		return $sa ? $sa['client_email'] : '';
	}

	/**
	 * Prüft und speichert den JSON-Schlüssel.
	 *
	 * @return true|WP_Error
	 */
	public static function save_service_account( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return new WP_Error( 'mkl_drive', 'Das ist kein gültiger JSON-Schlüssel eines Dienstkontos (client_email/private_key fehlen).' );
		}
		if ( ! openssl_pkey_get_private( $data['private_key'] ) ) {
			return new WP_Error( 'mkl_drive', 'Der private Schlüssel im JSON kann nicht gelesen werden.' );
		}
		$keep = array_intersect_key( $data, array_flip( array( 'type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'token_uri' ) ) );
		update_option( self::OPTION_KEY, wp_json_encode( $keep ), false );
		delete_transient( self::TOKEN_CACHE );
		return true;
	}

	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/** Signiertes JWT für den Token-Austausch (RFC 7523). */
	public static function build_jwt( array $sa, $now = null ) {
		$now    = $now ? $now : time();
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		$claims = array(
			'iss'   => $sa['client_email'],
			'scope' => self::SCOPE,
			'aud'   => ! empty( $sa['token_uri'] ) ? $sa['token_uri'] : self::TOKEN_URI,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);
		$input  = self::b64url( wp_json_encode( $header ) ) . '.' . self::b64url( wp_json_encode( $claims ) );
		$sig    = '';
		if ( ! openssl_sign( $input, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'mkl_drive', 'JWT konnte nicht signiert werden.' );
		}
		return $input . '.' . self::b64url( $sig );
	}

	/** @return string|WP_Error */
	public static function access_token() {
		$sa = self::service_account();
		if ( ! $sa ) {
			return new WP_Error( 'mkl_drive', 'Es ist noch kein Dienstkonto hinterlegt.' );
		}
		$cached = get_transient( self::TOKEN_CACHE );
		if ( is_array( $cached ) && $cached['email'] === $sa['client_email'] ) {
			return $cached['token'];
		}
		$jwt = self::build_jwt( $sa );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}
		$res = wp_remote_post(
			! empty( $sa['token_uri'] ) ? $sa['token_uri'] : self::TOKEN_URI,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || empty( $body['access_token'] ) ) {
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
			return new WP_Error( 'mkl_drive', 'Google-Anmeldung des Dienstkontos fehlgeschlagen: ' . $msg );
		}
		$ttl = max( 60, (int) ( isset( $body['expires_in'] ) ? $body['expires_in'] : 3600 ) - 300 );
		set_transient(
			self::TOKEN_CACHE,
			array(
				'email' => $sa['client_email'],
				'token' => $body['access_token'],
			),
			$ttl
		);
		return $body['access_token'];
	}

	/**
	 * Alle Kinder eines Ordners (mit Paging).
	 *
	 * @return array[]|WP_Error
	 */
	public static function list_children( $folder_id ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$files      = array();
		$page_token = null;
		do {
			$params = array(
				'q'                         => sprintf( "'%s' in parents and trashed = false", str_replace( "'", "\\'", $folder_id ) ),
				'fields'                    => 'nextPageToken,files(id,name,mimeType,createdTime,modifiedTime,md5Checksum,imageMediaMetadata(width,height,time))',
				'pageSize'                  => 1000,
				'orderBy'                   => 'name',
				'supportsAllDrives'         => 'true',
				'includeItemsFromAllDrives' => 'true',
			);
			if ( $page_token ) {
				$params['pageToken'] = $page_token;
			}
			$res = wp_remote_get(
				self::FILES_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ),
				array(
					'timeout' => 30,
					'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( 200 !== wp_remote_retrieve_response_code( $res ) || ! is_array( $body ) ) {
				$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . wp_remote_retrieve_response_code( $res );
				return new WP_Error( 'mkl_drive', 'Google Drive: ' . $msg );
			}
			foreach ( isset( $body['files'] ) ? $body['files'] : array() as $f ) {
				$files[] = $f;
			}
			$page_token = isset( $body['nextPageToken'] ) ? $body['nextPageToken'] : null;
		} while ( $page_token );
		return $files;
	}

	/**
	 * Lädt eine Datei in eine temporäre Datei.
	 *
	 * @return string|WP_Error Pfad der temporären Datei (Aufrufer löscht sie).
	 */
	public static function download( $file_id ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = wp_remote_get(
			self::FILES_URL . '/' . rawurlencode( $file_id ) . '?alt=media&supportsAllDrives=true',
			array(
				'timeout' => 90,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'mkl_drive', 'Download fehlgeschlagen: HTTP ' . wp_remote_retrieve_response_code( $res ) );
		}
		$tmp = wp_tempnam( 'mkl-drive-' . $file_id );
		file_put_contents( $tmp, wp_remote_retrieve_body( $res ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $tmp;
	}

	public static function is_folder( array $f ) {
		return self::FOLDER_MIME === $f['mimeType'];
	}

	public static function is_image( array $f ) {
		return 0 === strpos( $f['mimeType'], 'image/' );
	}

	/** Ordner-ID aus einer Drive-URL oder direkt. */
	public static function parse_folder_id( $input ) {
		$input = trim( (string) $input );
		if ( preg_match( '#/folders/([A-Za-z0-9_-]+)#', $input, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#[?&]id=([A-Za-z0-9_-]+)#', $input, $m ) ) {
			return $m[1];
		}
		return preg_match( '/^[A-Za-z0-9_-]+$/', $input ) ? $input : '';
	}
}
