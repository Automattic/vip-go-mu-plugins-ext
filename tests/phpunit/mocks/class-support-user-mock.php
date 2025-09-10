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
		
		public static function reset_mock() {
			self::$mock_vip_support_users = [];
		}
	}
}
