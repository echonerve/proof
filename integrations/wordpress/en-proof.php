/**
 * EchoNerve Proof: live test numbers from github.com/echonerve/proof.
 *
 * en_proof_num( 'total' | 'qtharness' | 'verifier' )  -> passed count (int)
 * [en_tests system="total|qtharness|verifier"]         -> same, as a shortcode
 * [en_proof]                                           -> the /proof/ page body
 *
 * Systems not yet in public CI fall back to their self-reported count and are
 * labelled that way on /proof/. Data is cached for an hour; if GitHub is
 * unreachable the last good copy is used, so the site never shows a blank.
 */
if ( ! defined( 'EN_PROOF_URL' ) ) {
	define( 'EN_PROOF_URL', 'https://raw.githubusercontent.com/echonerve/proof/main/data/latest.json' );
}

function en_proof_selfreported() {
	// Remove a line once that system publishes through CI.
	return array(
		'qtharness' => array( 'name' => 'qtharness', 'passed' => 657 ),
		'verifier'  => array( 'name' => 'EchoNerve Verifier', 'passed' => 164 ),
	);
}

function en_proof_data() {
	$cached = get_transient( 'en_proof_data' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$data = null;
	$res  = wp_remote_get( EN_PROOF_URL, array( 'timeout' => 5 ) );
	if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
	}
	if ( is_array( $data ) && isset( $data['systems'] ) && is_array( $data['systems'] ) ) {
		update_option( 'en_proof_last_good', $data, false );
	} else {
		$data = get_option( 'en_proof_last_good', array( 'updated' => '', 'systems' => array() ) );
	}
	set_transient( 'en_proof_data', $data, HOUR_IN_SECONDS );
	return $data;
}

function en_proof_num( $which = 'total' ) {
	$live  = en_proof_data();
	$sys   = isset( $live['systems'] ) ? $live['systems'] : array();
	$self  = en_proof_selfreported();
	$count = function ( $key ) use ( $sys, $self ) {
		if ( isset( $sys[ $key ]['passed'] ) ) {
			return (int) $sys[ $key ]['passed'];
		}
		return isset( $self[ $key ] ) ? (int) $self[ $key ]['passed'] : 0;
	};
	if ( 'total' === $which ) {
		$keys = array_unique( array_merge( array_keys( $self ), array_keys( $sys ) ) );
		return array_sum( array_map( $count, $keys ) );
	}
	return $count( $which );
}

add_shortcode( 'en_tests', function ( $atts ) {
	$a = shortcode_atts( array( 'system' => 'total' ), $atts );
	return esc_html( number_format_i18n( en_proof_num( sanitize_key( $a['system'] ) ) ) );
} );

add_shortcode( 'en_proof', function () {
	$live = en_proof_data();
	$sys  = isset( $live['systems'] ) ? $live['systems'] : array();
	$self = en_proof_selfreported();
	$rows = '';
	foreach ( $sys as $key => $s ) {
		$ok    = ( 'green' === ( $s['status'] ?? '' ) );
		$rows .= sprintf(
			'<tr><td><a href="%1$s">%2$s</a></td><td><span class="enp-chip %3$s">%4$s</span></td><td>%5$d</td><td>%6$d</td><td>%7$d</td><td><code>%8$s</code></td><td>%9$s</td><td>Public CI</td></tr>',
			esc_url( 'https://github.com/echonerve/proof/blob/main/data/' . rawurlencode( $key ) . '/latest.json' ),
			esc_html( $s['name'] ?? $key ),
			$ok ? 'ok' : 'bad',
			$ok ? 'green' : 'failing',
			(int) ( $s['passed'] ?? 0 ),
			(int) ( $s['failed'] ?? 0 ),
			(int) ( $s['skipped'] ?? 0 ),
			esc_html( $s['commit'] ?? '' ),
			esc_html( str_replace( array( 'T', 'Z' ), array( ' ', ' UTC' ), $s['run_at'] ?? '' ) )
		);
	}
	foreach ( $self as $key => $s ) {
		if ( isset( $sys[ $key ] ) ) {
			continue;
		}
		$rows .= sprintf(
			'<tr class="enp-self"><td>%1$s</td><td><span class="enp-chip self">self-reported</span></td><td>%2$d</td><td>–</td><td>–</td><td>–</td><td>–</td><td>Not yet in public CI</td></tr>',
			esc_html( $s['name'] ),
			(int) $s['passed']
		);
	}
	$verified = 0;
	foreach ( $sys as $s ) {
		$verified += (int) ( $s['passed'] ?? 0 );
	}
	$total = en_proof_num( 'total' );
	ob_start();
	?>
<style>
.enp{max-width:960px;margin:0 auto;font-size:15px;line-height:1.6}
.enp-kpis{display:flex;flex-wrap:wrap;gap:16px;margin:24px 0}
.enp-kpi{flex:1 1 200px;border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:16px 18px}
.enp-kpi b{display:block;font-size:2rem;line-height:1.1}
.enp-kpi span{font-size:13px;opacity:.75}
.enp-wrap{overflow-x:auto}
.enp table{width:100%;border-collapse:collapse;min-width:720px}
.enp th,.enp td{text-align:left;padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.1);white-space:nowrap}
.enp th{font-size:12px;text-transform:uppercase;letter-spacing:.06em;opacity:.7}
.enp-chip{font-size:12px;padding:2px 10px;border-radius:999px;border:1px solid}
.enp-chip.ok{color:#34D399;border-color:#34D399}
.enp-chip.bad{color:#F87171;border-color:#F87171}
.enp-chip.self{color:#F59E0B;border-color:#F59E0B}
.enp-self td{opacity:.85}
</style>
<div class="enp">
  <div class="enp-kpis">
    <div class="enp-kpi"><b><?php echo esc_html( number_format_i18n( $verified ) ); ?></b><span>tests passing in public CI</span></div>
    <div class="enp-kpi"><b><?php echo esc_html( number_format_i18n( $total ) ); ?></b><span>tests reported green, including self-reported</span></div>
    <div class="enp-kpi"><b><?php echo esc_html( $live['updated'] ? str_replace( array( 'T', 'Z' ), array( ' ', ' UTC' ), $live['updated'] ) : 'awaiting first run' ); ?></b><span>last public run</span></div>
  </div>
  <div class="enp-wrap"><table>
    <thead><tr><th>System</th><th>Status</th><th>Passed</th><th>Failed</th><th>Skipped</th><th>Commit</th><th>Run</th><th>Source</th></tr></thead>
    <tbody><?php echo $rows; // phpcs:ignore -- built from escaped parts above ?></tbody>
  </table></div>
  <p>How this works: each system's full test suite runs on a clean GitHub machine nightly and on every change. The result, pass or fail, is committed to the public <a href="https://github.com/echonerve/proof">echonerve/proof</a> repository with every test name. The code is private (some of it is client work). The results are not, and nothing in that repository is edited by hand. Rows marked <em>self-reported</em> are not in public CI yet; their numbers come from my own runs, and they move into public CI next.</p>
</div>
	<?php
	return ob_get_clean();
} );
