<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft\Tests;

use Automattic\ShareADraft\IpAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * The CIDR validation and matching that backs the IP allowlist. This maths is
 * the security boundary — an off-by-one in the prefix mask is either a bypass
 * or a lockout — so both families and the byte-boundary edges are pinned down.
 *
 * @covers \Automattic\ShareADraft\IpAllowlist
 */
final class IpAllowlistTest extends TestCase {
	/**
	 * @dataProvider valid_ranges
	 */
	public function test_valid_ranges_are_accepted( string $range ): void {
		self::assertTrue( IpAllowlist::is_valid_range( $range ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function valid_ranges(): array {
		return [
			'bare IPv4'         => [ '203.0.113.7' ],
			'IPv4 CIDR'         => [ '203.0.113.0/24' ],
			'IPv4 single host'  => [ '203.0.113.7/32' ],
			'IPv4 whole space'  => [ '0.0.0.0/0' ],
			'bare IPv6'         => [ '2001:db8::1' ],
			'IPv6 CIDR'         => [ '2001:db8::/32' ],
			'IPv6 single host'  => [ '2001:db8::1/128' ],
			'IPv6 uncompressed' => [ '2001:0db8:0000:0000:0000:0000:0000:0001/64' ],
		];
	}

	/**
	 * @dataProvider invalid_ranges
	 */
	public function test_invalid_ranges_are_rejected( string $range ): void {
		self::assertFalse( IpAllowlist::is_valid_range( $range ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalid_ranges(): array {
		return [
			'empty'                   => [ '' ],
			'not an address'          => [ 'office' ],
			'IPv4 octet out of range' => [ '256.0.0.1' ],
			'IPv4 prefix too long'    => [ '203.0.113.0/33' ],
			'IPv6 prefix too long'    => [ '2001:db8::/129' ],
			'negative prefix'         => [ '203.0.113.0/-1' ],
			'non-numeric prefix'      => [ '203.0.113.0/abc' ],
			'empty prefix'            => [ '203.0.113.0/' ],
			'double slash'            => [ '203.0.113.0/24/24' ],
			'hostname'                => [ 'example.com/24' ],
			'whitespace inside'       => [ '203.0.113.0 /24' ],
		];
	}

	public function test_an_ip_inside_a_v4_range_matches(): void {
		self::assertTrue( IpAllowlist::matches( '203.0.113.200', [ '203.0.113.0/24' ] ) );
	}

	public function test_an_ip_outside_a_v4_range_does_not_match(): void {
		self::assertFalse( IpAllowlist::matches( '203.0.114.1', [ '203.0.113.0/24' ] ) );
	}

	public function test_a_non_byte_aligned_prefix_masks_correctly(): void {
		// /25 splits the last octet at 128: .127 is inside, .128 is outside.
		self::assertTrue( IpAllowlist::matches( '203.0.113.127', [ '203.0.113.0/25' ] ) );
		self::assertFalse( IpAllowlist::matches( '203.0.113.128', [ '203.0.113.0/25' ] ) );
	}

	public function test_a_bare_ip_range_matches_only_itself(): void {
		self::assertTrue( IpAllowlist::matches( '203.0.113.7', [ '203.0.113.7' ] ) );
		self::assertFalse( IpAllowlist::matches( '203.0.113.8', [ '203.0.113.7' ] ) );
	}

	public function test_a_zero_prefix_matches_everything_in_its_family(): void {
		self::assertTrue( IpAllowlist::matches( '198.51.100.1', [ '0.0.0.0/0' ] ) );
		// …but not the other family.
		self::assertFalse( IpAllowlist::matches( '2001:db8::1', [ '0.0.0.0/0' ] ) );
	}

	public function test_an_ipv6_ip_matches_an_ipv6_range(): void {
		self::assertTrue( IpAllowlist::matches( '2001:db8:0:1::5', [ '2001:db8::/32' ] ) );
		self::assertFalse( IpAllowlist::matches( '2001:db9::1', [ '2001:db8::/32' ] ) );
	}

	public function test_families_never_cross_match(): void {
		self::assertFalse( IpAllowlist::matches( '203.0.113.7', [ '2001:db8::/32' ] ) );
		self::assertFalse( IpAllowlist::matches( '2001:db8::1', [ '203.0.113.0/24' ] ) );
	}

	public function test_matching_any_one_range_in_the_set_is_enough(): void {
		self::assertTrue(
			IpAllowlist::matches( '198.51.100.7', [ '203.0.113.0/24', '198.51.100.0/24' ] )
		);
	}

	public function test_an_empty_range_list_matches_nothing(): void {
		self::assertFalse( IpAllowlist::matches( '203.0.113.7', [] ) );
	}

	public function test_an_unparseable_ip_never_matches(): void {
		self::assertFalse( IpAllowlist::matches( 'not-an-ip', [ '0.0.0.0/0' ] ) );
		self::assertFalse( IpAllowlist::matches( '', [ '0.0.0.0/0' ] ) );
	}

	public function test_sanitize_keeps_valid_entries_from_an_array(): void {
		self::assertSame(
			[ '203.0.113.0/24', '2001:db8::/32' ],
			IpAllowlist::sanitize( [ '203.0.113.0/24', '2001:db8::/32' ] )
		);
	}

	public function test_sanitize_splits_a_delimited_string(): void {
		self::assertSame(
			[ '203.0.113.0/24', '198.51.100.7', '2001:db8::/32' ],
			IpAllowlist::sanitize( "203.0.113.0/24, 198.51.100.7\n2001:db8::/32" )
		);
	}

	public function test_sanitize_drops_junk_and_dupes_without_fatals(): void {
		self::assertSame(
			[ '203.0.113.0/24' ],
			IpAllowlist::sanitize( [ '203.0.113.0/24', 'office', 42, null, [ 'nested' ], '203.0.113.0/24', ' ' ] )
		);
	}

	public function test_rejected_names_the_entries_sanitize_drops(): void {
		self::assertSame(
			[ '203.0.113.*', '10.0.0.0/255.255.255.0' ],
			IpAllowlist::rejected( "203.0.113.0/24, 203.0.113.*\n10.0.0.0/255.255.255.0, 203.0.113.*" )
		);
	}

	public function test_rejected_is_empty_when_every_entry_is_valid(): void {
		self::assertSame( [], IpAllowlist::rejected( '203.0.113.0/24, 2001:db8::/32' ) );
	}

	/**
	 * @dataProvider unusable_config_values
	 * @param mixed $raw
	 */
	public function test_sanitize_treats_unusable_values_as_empty( $raw ): void {
		self::assertSame( [], IpAllowlist::sanitize( $raw ) );
		self::assertSame( [], IpAllowlist::rejected( $raw ) );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function unusable_config_values(): array {
		return [
			'null'         => [ null ],
			'false'        => [ false ],
			'integer'      => [ 42 ],
			'empty string' => [ '' ],
			'empty array'  => [ [] ],
		];
	}
}
