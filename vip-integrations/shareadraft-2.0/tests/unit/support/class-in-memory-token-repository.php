<?php

declare(strict_types = 1);

namespace Automattic\ShareADraft\Tests\Support;

use Automattic\ShareADraft\PreviewLink;
use Automattic\ShareADraft\Token;
use Automattic\ShareADraft\TokenRepository;

/**
 * In-memory {@see TokenRepository} for unit tests. Mirrors the postmeta adapter's
 * contract (multiple links per post, matched by token hash) without a database,
 * including its compare-and-swap semantics on {@see self::add_use()} so the
 * service's retry loop is exercised for real.
 */
final class InMemoryTokenRepository implements TokenRepository {
	/** @var array<int, list<PreviewLink>> Links keyed by post ID. */
	private array $links = [];

	/**
	 * @var callable|null Runs immediately before each add_use write, so a test
	 *                    can simulate a competing request landing in the window
	 *                    between the service reading a link and writing it back.
	 */
	private $before_add_use;

	/** Whether {@see self::save()} reports a failed write, as a vetoed insert would. */
	private bool $fail_saves = false;

	/** How many stored rows do not unserialise to a link; see {@see self::add_malformed_row()}. */
	private int $malformed_rows = 0;

	public function save( PreviewLink $link ): bool {
		if ( $this->fail_saves ) {
			return false;
		}

		$this->links[ $link->post_id() ][] = $link;

		return true;
	}

	public function find( int $post_id, Token $candidate ): ?PreviewLink {
		foreach ( $this->links[ $post_id ] ?? [] as $link ) {
			if ( $link->matches( $candidate ) ) {
				return $link;
			}
		}

		return null;
	}

	public function all_for_post( int $post_id ): array {
		return $this->links[ $post_id ] ?? [];
	}

	public function add_use( PreviewLink $link ): bool {
		if ( is_callable( $this->before_add_use ) ) {
			$hook                 = $this->before_add_use;
			$this->before_add_use = null;
			$hook();
		}

		$stored = $this->find_stored( $link );

		// Compare-and-swap: the caller's read must still be current.
		if ( null === $stored || $stored->use_count() !== $link->use_count() ) {
			return false;
		}

		$this->replace( $stored, $stored->with_use() );

		return true;
	}

	public function find_by_hash( int $post_id, string $token_hash ): ?PreviewLink {
		foreach ( $this->links[ $post_id ] ?? [] as $link ) {
			if ( hash_equals( $link->token_hash(), $token_hash ) ) {
				return $link;
			}
		}

		return null;
	}

	public function revoke( PreviewLink $link, int $revoked_at ): bool {
		$this->replace( $link, $link->with_revoked( $revoked_at ) );

		return true;
	}

	public function remove_recipient( PreviewLink $link, string $email, int $now ): bool {
		$this->replace( $link, $link->without_recipient( $email, $now ) );

		return true;
	}

	public function revoke_all_for_post( int $post_id, int $revoked_at, int $created_until ): int {
		return $this->revoke_matching( $post_id, null, $revoked_at, $created_until );
	}

	public function revoke_by_creator_for_post( int $post_id, int $created_by, int $revoked_at, int $created_until ): int {
		return $this->revoke_matching( $post_id, $created_by, $revoked_at, $created_until );
	}

	private function revoke_matching( int $post_id, ?int $created_by, int $revoked_at, int $created_until ): int {
		$revoked = 0;

		foreach ( $this->links[ $post_id ] ?? [] as $link ) {
			if ( $link->is_revoked() || $link->created_at() > $created_until ) {
				continue;
			}

			if ( null !== $created_by && $link->created_by() !== $created_by ) {
				continue;
			}

			$this->replace( $link, $link->with_revoked( $revoked_at ) );
			++$revoked;
		}

		return $revoked;
	}

	public function delete_all_for_post( int $post_id ): void {
		unset( $this->links[ $post_id ] );
	}

	public function delete_dead_for_post( int $post_id, int $dead_before, int $now ): int {
		$kept    = [];
		$deleted = 0;

		foreach ( $this->links[ $post_id ] ?? [] as $link ) {
			$dead_since = $link->dead_since( $now );

			if ( null !== $dead_since && $dead_since < $dead_before ) {
				++$deleted;
				continue;
			}

			$kept[] = $link;
		}

		$this->links[ $post_id ] = $kept;

		return $deleted;
	}

	public function post_ids_with_links( int $after_post_id, int $limit ): array {
		$ids = [];

		foreach ( $this->links as $post_id => $links ) {
			if ( [] !== $links && $post_id > $after_post_id ) {
				$ids[] = $post_id;
			}
		}

		sort( $ids );

		return array_slice( $ids, 0, $limit );
	}

	public function post_ids_with_recipient( string $email, int $offset, int $limit ): array {
		$ids = [];

		foreach ( $this->links as $post_id => $links ) {
			foreach ( $links as $link ) {
				if ( $link->is_recipient( $email ) ) {
					$ids[] = $post_id;
					break;
				}
			}
		}

		sort( $ids );

		return array_slice( $ids, $offset, $limit );
	}

	public function page_of_links( int $offset, int $limit, ?int $created_by = null ): array {
		// The adapter drops a row it cannot unserialise, so the page comes back short.
		return array_values( array_filter( array_slice( $this->rows( $created_by ), $offset, $limit ) ) );
	}

	public function count_links( ?int $created_by = null ): int {
		return count( $this->rows( $created_by ) );
	}

	/**
	 * Store a row that does not unserialise to a link, as database corruption
	 * or another plugin writing the meta key would. It is counted, and takes a
	 * slot in its page, but never comes back as a link.
	 */
	public function add_malformed_row(): void {
		++$this->malformed_rows;
	}

	/**
	 * Every stored row, newest first, with null standing in for a malformed one.
	 *
	 * @return list<PreviewLink|null>
	 */
	private function rows( ?int $created_by ): array {
		$all = [];

		foreach ( $this->links as $links ) {
			foreach ( $links as $link ) {
				if ( null === $created_by || $link->created_by() === $created_by ) {
					$all[] = $link;
				}
			}
		}

		// The adapter returns newest first; the fake keeps insertion order, so
		// reverse to approximate it.
		return array_reverse( [ ...$all, ...array_fill( 0, $this->malformed_rows, null ) ] );
	}

	/**
	 * Arrange for a competing write to land just before the next add_use call.
	 */
	public function on_next_add_use( callable $hook ): void {
		$this->before_add_use = $hook;
	}

	/**
	 * Make every later save fail, as a database error or a vetoed insert would.
	 */
	public function fail_saves(): void {
		$this->fail_saves = true;
	}

	private function find_stored( PreviewLink $link ): ?PreviewLink {
		foreach ( $this->links[ $link->post_id() ] ?? [] as $stored ) {
			if ( hash_equals( $stored->token_hash(), $link->token_hash() ) ) {
				return $stored;
			}
		}

		return null;
	}

	private function replace( PreviewLink $old, PreviewLink $replacement ): void {
		$post_id = $old->post_id();

		foreach ( $this->links[ $post_id ] ?? [] as $index => $stored ) {
			if ( hash_equals( $stored->token_hash(), $old->token_hash() ) ) {
				$links           = $this->links[ $post_id ];
				$links[ $index ] = $replacement;
				// Rebuild via array_values so the property keeps its list<> shape.
				$this->links[ $post_id ] = array_values( $links );
				return;
			}
		}
	}
}
