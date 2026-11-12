<?php
/**
 * Mock for VIP Support User class used in tests
 */

namespace Automattic\VIP\Support_User;

if ( ! class_exists( '\Automattic\VIP\Support_User\User' ) ) {
	class User {
		public static $mock_vip_support_users = [];

		public static function user_has_vip_support_role( $user_id ) {
			return in_array( $user_id, self::$mock_vip_support_users, true );
		}

		public static function is_a8c_email( $email ) {
			if ( ! is_email( $email ) ) {
				return false;
			}
			list( , $domain ) = explode( '@', $email, 2 );
			$a8c_domains      = array(
				'a8c.com',
				'automattic.com',
				'matticspace.com',
				'wpvip.com',
			);
			return in_array( $domain, $a8c_domains, true );
		}

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		public static function is_verified_automattician( $user_id ) {
			return false;
		}

		public static function reset_mock() {
			self::$mock_vip_support_users = [];
		}
	}
}
