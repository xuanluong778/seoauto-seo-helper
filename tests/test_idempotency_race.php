<?php
/**
 * Idempotency race tests — unique article map + request_id replay.
 *
 * Simulates two concurrent creates for the same source_article_id.
 * Run: php tests/test_idempotency_race.php
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

$failed = 0;

function check( string $msg, bool $ok ): void {
	global $failed;
	if ( $ok ) {
		echo "PASS  {$msg}\n";
		return;
	}
	++$failed;
	echo "FAIL  {$msg}\n";
}

/**
 * In-memory stand-in for UNIQUE(connection_id, source_article_id) + UNIQUE(request_id)
 * with a mutex to emulate GET_LOCK across interleaved workers.
 */
final class FakeAtomicStore {
	/** @var array<string,array{post_id:int,status:string,response?:array<string,mixed>}> */
	private array $requests = array();

	/** @var array<string,int> connection|article => post_id */
	private array $articles = array();

	/** @var array<string,bool> */
	private array $locks = array();

	/** @var int */
	public int $insert_attempts = 0;

	/** @var int */
	public int $create_calls = 0;

	private function art_key( int $connection_id, string $article_id ): string {
		return $connection_id . '|' . $article_id;
	}

	public function claim_request( string $request_id, string $article_id, int $connection_id ): string {
		if ( isset( $this->requests[ $request_id ] ) ) {
			$row = $this->requests[ $request_id ];
			if ( $row['status'] === 'completed' ) {
				return 'replay';
			}
			return 'pending';
		}
		$this->requests[ $request_id ] = array(
			'post_id' => 0,
			'status'  => 'pending',
		);
		return 'claimed';
	}

	/**
	 * @param array<string,mixed> $response
	 */
	public function complete( string $request_id, int $post_id, array $response ): void {
		$this->requests[ $request_id ] = array(
			'post_id'  => $post_id,
			'status'   => 'completed',
			'response' => $response,
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function replay( string $request_id ): ?array {
		$row = $this->requests[ $request_id ] ?? null;
		if ( ! is_array( $row ) || $row['status'] !== 'completed' ) {
			return null;
		}
		$out = $row['response'] ?? array( 'post_id' => $row['post_id'] );
		$out['idempotent_replay'] = true;
		return $out;
	}

	public function find_post( int $connection_id, string $article_id ): int {
		return (int) ( $this->articles[ $this->art_key( $connection_id, $article_id ) ] ?? 0 );
	}

	/** Emulates INSERT … UNIQUE — only first succeeds. */
	public function insert_map( int $connection_id, string $article_id, int $post_id ): bool {
		++$this->insert_attempts;
		$key = $this->art_key( $connection_id, $article_id );
		if ( isset( $this->articles[ $key ] ) ) {
			return false;
		}
		$this->articles[ $key ] = $post_id;
		return true;
	}

	public function lock( string $article_id ): bool {
		if ( ! empty( $this->locks[ $article_id ] ) ) {
			return false;
		}
		$this->locks[ $article_id ] = true;
		return true;
	}

	public function unlock( string $article_id ): void {
		unset( $this->locks[ $article_id ] );
	}

	/**
	 * Create path under lock — mirrors Post_Service execute_locked(create).
	 *
	 * @return array{ok:bool,post_id?:int,error?:string,race?:bool}
	 */
	public function create_under_lock( string $request_id, string $article_id, int $connection_id, bool $force_create = false ): array {
		$claim = $this->claim_request( $request_id, $article_id, $connection_id );
		if ( $claim === 'replay' ) {
			$r = $this->replay( $request_id );
			return array( 'ok' => true, 'post_id' => (int) ( $r['post_id'] ?? 0 ), 'replay' => true );
		}
		if ( $claim === 'pending' ) {
			return array( 'ok' => false, 'error' => 'pending' );
		}

		if ( ! $this->lock( $article_id ) ) {
			return array( 'ok' => false, 'error' => 'lock' );
		}

		try {
			$mapped = $this->find_post( $connection_id, $article_id );
			if ( $mapped > 0 && ! $force_create ) {
				return array( 'ok' => false, 'error' => 'article_exists', 'post_id' => $mapped );
			}

			++$this->create_calls;
			$new_post_id = 1000 + $this->create_calls;

			$inserted = $this->insert_map( $connection_id, $article_id, $new_post_id );
			if ( ! $inserted ) {
				return array(
					'ok'      => false,
					'error'   => 'article_exists',
					'post_id' => $this->find_post( $connection_id, $article_id ),
					'race'    => true,
				);
			}

			$response = array( 'post_id' => $new_post_id, 'status' => 'draft' );
			$this->complete( $request_id, $new_post_id, $response );
			return array( 'ok' => true, 'post_id' => $new_post_id );
		} finally {
			$this->unlock( $article_id );
		}
	}

	/**
	 * Simulate two concurrent creates: both pass pre-lock checks, then race on UNIQUE insert.
	 *
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 */
	public function concurrent_create_race( string $article_id, int $connection_id ): array {
		// Different request_ids (two Celery tasks), same article.
		$r1 = 'req-A';
		$r2 = 'req-B';

		// Both claim successfully (different request ids).
		check( 'worker1 claims request', $this->claim_request( $r1, $article_id, $connection_id ) === 'claimed' );
		check( 'worker2 claims request', $this->claim_request( $r2, $article_id, $connection_id ) === 'claimed' );

		// Worker1 holds lock and inserts map.
		check( 'worker1 acquires lock', $this->lock( $article_id ) );
		++$this->create_calls;
		$post1 = 1000 + $this->create_calls;
		check( 'worker1 unique map insert wins', $this->insert_map( $connection_id, $article_id, $post1 ) );
		$this->complete( $r1, $post1, array( 'post_id' => $post1, 'status' => 'draft' ) );
		$this->unlock( $article_id );

		// Worker2 tries lock then insert — unique conflict.
		check( 'worker2 acquires lock after release', $this->lock( $article_id ) );
		$mapped = $this->find_post( $connection_id, $article_id );
		$out2   = array();
		if ( $mapped > 0 ) {
			$out2 = array( 'ok' => false, 'error' => 'article_exists', 'post_id' => $mapped );
		} else {
			++$this->create_calls;
			$post2    = 1000 + $this->create_calls;
			$inserted = $this->insert_map( $connection_id, $article_id, $post2 );
			$out2     = $inserted
				? array( 'ok' => true, 'post_id' => $post2 )
				: array( 'ok' => false, 'error' => 'article_exists', 'race' => true, 'post_id' => $this->find_post( $connection_id, $article_id ) );
		}
		$this->unlock( $article_id );

		$out1 = array( 'ok' => true, 'post_id' => $post1 );
		return array( $out1, $out2 );
	}
}

$store = new FakeAtomicStore();

// --- Same request_id retry (Celery) ---
$a = $store->create_under_lock( 'celery-1', 'article-99', 7 );
$b = $store->create_under_lock( 'celery-1', 'article-99', 7 );
check( 'first create succeeds', ! empty( $a['ok'] ) && (int) $a['post_id'] === 1001 );
check( 'retry same request_id replays', ! empty( $b['ok'] ) && ! empty( $b['replay'] ) && (int) $b['post_id'] === 1001 );
check( 'retry does not create second post', $store->create_calls === 1 );
check( 'article map has single post', $store->find_post( 7, 'article-99' ) === 1001 );

// --- Create rejected when article exists ---
$c = $store->create_under_lock( 'celery-2', 'article-99', 7 );
check( 'second request_id create rejected', empty( $c['ok'] ) && ( $c['error'] ?? '' ) === 'article_exists' );
check( 'rejected points to mapped post', (int) ( $c['post_id'] ?? 0 ) === 1001 );

// --- force_create controlled path still needs explicit flag (simulated separately) ---
$store2 = new FakeAtomicStore();
$store2->create_under_lock( 'f1', 'art-force', 1 );
// Manually allow remap via insert after find — force path updates map in real code; here assert exists blocks without force.
$blocked = $store2->create_under_lock( 'f2', 'art-force', 1, false );
check( 'without force_create blocked', ( $blocked['error'] ?? '' ) === 'article_exists' );

// --- Concurrent race: two workers, one wins UNIQUE ---
$store3 = new FakeAtomicStore();
[ $w1, $w2 ] = $store3->concurrent_create_race( 'shared-article', 42 );
check( 'concurrent winner ok', ! empty( $w1['ok'] ) );
check( 'concurrent loser rejected', empty( $w2['ok'] ) && ( $w2['error'] ?? '' ) === 'article_exists' );
check( 'both see same post_id', (int) $w1['post_id'] === (int) ( $w2['post_id'] ?? 0 ) );
check( 'only one map row', $store3->find_post( 42, 'shared-article' ) === (int) $w1['post_id'] );
check( 'unique insert attempted once by winner (loser saw map)', $store3->insert_attempts === 1 );

// --- True parallel attempt on UNIQUE insert (no lock) ---
$store4 = new FakeAtomicStore();
$connection = 9;
$article    = 'parallel-art';
// Both think map empty, both try insert — second fails unique.
$ok1 = $store4->insert_map( $connection, $article, 501 );
$ok2 = $store4->insert_map( $connection, $article, 502 );
check( 'parallel unique: first wins', $ok1 === true );
check( 'parallel unique: second fails', $ok2 === false );
check( 'parallel unique: canonical post_id is first', $store4->find_post( $connection, $article ) === 501 );

echo $failed === 0 ? "\nAll idempotency race tests passed.\n" : "\n{$failed} test(s) failed.\n";
exit( $failed === 0 ? 0 : 1 );
