<?php
/**
 * JWT token handling for Cognito authentication
 *
 * @package WP_Cognito_Auth
 */

namespace WP_Cognito_Auth;

/**
 * Class JWT
 *
 * Handles JWT token validation and decoding
 */
class JWT {
	/**
	 * Decode and validate JWT token
	 *
	 * @param string $jwt  The JWT token.
	 * @param array  $jwks The JWKS data.
	 * @return array Decoded payload.
	 * @throws \Exception If token is invalid.
	 */
	public static function decode( $jwt, $jwks ) {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			throw new \Exception( 'Invalid JWT format' );
		}

		[$header, $payload, $signature] = $parts;

		$decoded_header  = json_decode( self::base64url_decode( $header ), true );
		$decoded_payload = json_decode( self::base64url_decode( $payload ), true );

		if ( ! $decoded_header || ! $decoded_payload ) {
			throw new \Exception( 'Invalid JWT encoding' );
		}

		if ( ! self::verify_signature( $header . '.' . $payload, $signature, $decoded_header, $jwks ) ) {
			throw new \Exception( 'Invalid JWT signature' );
		}

		self::verify_claims( $decoded_payload );

		return $decoded_payload;
	}

	/**
	 * Verify JWT signature
	 *
	 * @param string $data      The data to verify.
	 * @param string $signature The signature.
	 * @param array  $header    The JWT header.
	 * @param array  $jwks      The JWKS data.
	 * @return bool Whether signature is valid.
	 */
	private static function verify_signature( $data, $signature, $header, $jwks ) {
		if ( ! isset( $header['kid'] ) || ! isset( $header['alg'] ) ) {
			return false;
		}

		if ( 'RS256' !== $header['alg'] ) {
			return false;
		}

		$key = null;
		foreach ( $jwks['keys'] as $jwk ) {
			if ( $jwk['kid'] === $header['kid'] ) {
				$key = $jwk;
				break;
			}
		}

		if ( ! $key ) {
			return false;
		}

		$public_key = self::jwk_to_pem( $key );
		if ( ! $public_key ) {
			return false;
		}

		$decoded_signature = self::base64url_decode( $signature );

		return openssl_verify( $data, $decoded_signature, $public_key, OPENSSL_ALGO_SHA256 ) === 1;
	}

	/**
	 * Verify JWT claims
	 *
	 * @param array $payload The JWT payload.
	 * @throws \Exception If claims are invalid.
	 */
	private static function verify_claims( $payload ) {
		$now = time();

		if ( isset( $payload['exp'] ) && $payload['exp'] < $now ) {
			throw new \Exception( 'Token has expired' );
		}

		if ( isset( $payload['nbf'] ) && $payload['nbf'] > $now ) {
			throw new \Exception( 'Token not yet valid' );
		}

		if ( isset( $payload['iat'] ) && $payload['iat'] > ( $now + 300 ) ) {
			throw new \Exception( 'Token issued in the future' );
		}

		$expected_audience = get_option( 'wp_cognito_auth_client_id' );
		if ( $expected_audience && isset( $payload['aud'] ) && $expected_audience !== $payload['aud'] ) {
			throw new \Exception( 'Invalid audience' );
		}

		$user_pool_id    = get_option( 'wp_cognito_auth_user_pool_id' );
		$region          = get_option( 'wp_cognito_auth_region' );
		$expected_issuer = "https://cognito-idp.{$region}.amazonaws.com/{$user_pool_id}";

		if ( isset( $payload['iss'] ) && $expected_issuer !== $payload['iss'] ) {
			throw new \Exception( 'Invalid issuer' );
		}

		if ( isset( $payload['token_use'] ) && 'id' !== $payload['token_use'] ) {
			throw new \Exception( 'Token is not an ID token' );
		}
	}

	/**
	 * Convert JWK to PEM format
	 *
	 * @param array $jwk The JWK data.
	 * @return string|false PEM formatted key or false on failure.
	 */
	private static function jwk_to_pem( $jwk ) {
		if ( 'RSA' !== $jwk['kty'] ) {
			return false;
		}

		if ( ! isset( $jwk['n'] ) || ! isset( $jwk['e'] ) ) {
			return false;
		}

		$n = self::base64url_decode( $jwk['n'] );
		$e = self::base64url_decode( $jwk['e'] );

		return self::create_rsa_pem( $n, $e );
	}

	/**
	 * Create RSA PEM from modulus and exponent
	 *
	 * @param string $modulus  The modulus.
	 * @param string $exponent The exponent.
	 * @return string PEM formatted key.
	 */
	private static function create_rsa_pem( $modulus, $exponent ) {
		$modulus  = self::encode_der_integer( $modulus );
		$exponent = self::encode_der_integer( $exponent );

		$rsa_public_key = self::encode_der_sequence( $modulus . $exponent );

		$algorithm_identifier = self::encode_der_sequence(
			self::encode_der_oid( '1.2.840.113549.1.1.1' ) .
			self::encode_der_null()
		);

		$subject_public_key_info = self::encode_der_sequence(
			$algorithm_identifier .
			self::encode_der_bit_string( $rsa_public_key )
		);

		$pem  = "-----BEGIN PUBLIC KEY-----\n";
		$pem .= chunk_split( base64_encode( $subject_public_key_info ), 64, "\n" );
		$pem .= "-----END PUBLIC KEY-----\n";

		return $pem;
	}

	/**
	 * Encode DER integer
	 *
	 * @param string $bytes The bytes to encode.
	 * @return string DER encoded integer.
	 */
	private static function encode_der_integer( $bytes ) {
		$bytes = ltrim( $bytes, "\x00" );

		if ( ord( $bytes[0] ) & 0x80 ) {
			$bytes = "\x00" . $bytes;
		}

		return "\x02" . self::encode_der_length( strlen( $bytes ) ) . $bytes;
	}

	/**
	 * Encode DER sequence
	 *
	 * @param string $content The content to encode.
	 * @return string DER encoded sequence.
	 */
	private static function encode_der_sequence( $content ) {
		return "\x30" . self::encode_der_length( strlen( $content ) ) . $content;
	}

	/**
	 * Encode DER bit string
	 *
	 * @param string $content The content to encode.
	 * @return string DER encoded bit string.
	 */
	private static function encode_der_bit_string( $content ) {
		return "\x03" . self::encode_der_length( strlen( $content ) + 1 ) . "\x00" . $content;
	}

	/**
	 * Encode DER OID
	 *
	 * @param string $oid The OID to encode.
	 * @return string DER encoded OID.
	 */
	private static function encode_der_oid( $oid ) {
		$parts   = explode( '.', $oid );
		$encoded = chr( 40 * $parts[0] + $parts[1] );

		$parts_count = count( $parts );
		for ( $i = 2; $i < $parts_count; $i++ ) {
			$encoded .= self::encode_der_oid_part( $parts[ $i ] );
		}

		return "\x06" . self::encode_der_length( strlen( $encoded ) ) . $encoded;
	}

	/**
	 * Encode DER OID part
	 *
	 * @param string $part The OID part to encode.
	 * @return string DER encoded OID part.
	 */
	private static function encode_der_oid_part( $part ) {
		$part = intval( $part );
		if ( $part < 128 ) {
			return chr( $part );
		}

		$encoded = '';
		$temp    = $part;
		$bytes   = array();

		while ( $temp > 0 ) {
			array_unshift( $bytes, $temp & 0x7F );
			$temp >>= 7;
		}

		$bytes_count = count( $bytes );
		for ( $i = 0; $i < $bytes_count - 1; $i++ ) {
			$bytes[ $i ] |= 0x80;
		}

		foreach ( $bytes as $byte ) {
			$encoded .= chr( $byte );
		}

		return $encoded;
	}

	/**
	 * Encode DER null
	 *
	 * @return string DER encoded null.
	 */
	private static function encode_der_null() {
		return "\x05\x00";
	}

	/**
	 * Encode DER length
	 *
	 * @param int $length The length to encode.
	 * @return string DER encoded length.
	 */
	private static function encode_der_length( $length ) {
		if ( $length < 128 ) {
			return chr( $length );
		}

		$encoded_length = '';
		$temp           = $length;

		while ( $temp > 0 ) {
			$encoded_length = chr( $temp & 0xFF ) . $encoded_length;
			$temp         >>= 8;
		}

		return chr( 0x80 | strlen( $encoded_length ) ) . $encoded_length;
	}

	/**
	 * Decode base64url string
	 *
	 * @param string $data The data to decode.
	 * @return string Decoded data.
	 */
	public static function base64url_decode( $data ) {
		$remainder = strlen( $data ) % 4;
		if ( $remainder ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}
		return base64_decode( strtr( $data, '-_', '+/' ) );
	}

	/**
	 * Encode base64url string
	 *
	 * @param string $data The data to encode.
	 * @return string Encoded data.
	 */
	public static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
