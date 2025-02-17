<?php
/**
 * Plugin Name: Slow Actions
 * Description: Easily find the slowest actions and filters during a page request.
 * Version: 0.8.5
 * Author: SatelliteWP
 * Author URI: https://www.satellitewp.com
 * License: GPLv2 or later
 */

class Slow_Actions {
	public $start;
	public $flow;

	function __construct() {
		$this->start = microtime( true );
		$this->flow = array();

		add_action( 'all', array( $this, 'time_start' ), -1 );
		add_filter( 'debug_bar_panels', array( $this, 'debug_bar_panels' ), 9000 );
		// add_action( 'wp_footer', function() { print_r( $this->flow ); }, 9000 );
	}

	function time_start() {
		if ( ! isset( $this->flow[ current_filter() ] ) ) {
			$this->flow[ current_filter() ] = array(
				'count' => 0,
				'stack' => array(),
				'time' => 0,
				'callbacks' => array(),
				#'subtimes' => array(),
			);

			// @todo: add support for nesting filters, see #17817
			add_action( current_filter(), array( $this, 'time_stop' ), 999999999 );
		}

		++$this->flow[ current_filter() ]['count'];
		array_push( $this->flow[ current_filter() ]['stack'], microtime( true ) );
		#array_push( $this->flow[ current_filter() ]['subtimes'], microtime( true ) );
	}

	function time_stop( $value = null ) {
		$time = array_pop( $this->flow[ current_filter() ]['stack'] );
		$this->flow[ current_filter() ]['time'] += microtime( true ) - $time;
		#$this->flow[ current_filter() ]['subtimes'][] = microtime( true ) - $time;

		// Remove time_stop filter from the list
		remove_action( current_filter(), array( $this, 'time_stop' ), 999999999 );

		// In case this was a filter.
		return $value;
	}

	function debug_bar_panels( $panels ) {
		require_once( dirname( __FILE__ ) . '/class-debug-bar-slow-actions-panel.php' );
		$panel = new Debug_Bar_Slow_Actions_Panel( 'Slow Actions' );
		$panel->set_callback( array( $this, 'panel_callback' ) );
		$panels[] = $panel;
		return $panels;
	}

	function panel_callback() {

		// Hack wp_footer: this callback is executed late into wp_footer, but not after, so
		// let's assume it is the last call in wp_footer and manually stop the timer, otherwise
		// we won't get a wp_footer entry in the output.
		if ( ! empty( $this->flow['wp_footer']['stack'] ) ) {
			$time = array_pop( $this->flow['wp_footer']['stack'] );
			if ( $time ) {
                $this->flow['wp_footer']['time'] += microtime( true );
			}
		}

		printf( '<div id="dbsa-container">%s</div>', $this->output() );
	}

	function sort_actions_by_time( $a, $b ) {
		if ( $a['total'] == $b['total'] )
        	return 0;

    	return ( $a['total'] > $b['total'] ) ? -1 : 1;
	}

	public static function get_callback_to_text( $callback ) {
		$result = null;

		if ( is_array( $callback['function'] ) && count( $callback['function'] ) == 2 ) {
			list( $object_or_class, $method ) = $callback['function'];
			if ( is_object( $object_or_class ) ) {
				$object_or_class = get_class( $object_or_class );
			}

			$result = sprintf( '%s::%s', $object_or_class, $method );
		} 
		elseif ( is_object( $callback['function'] ) ) {
			// Probably an anonymous function.
			$result = get_class( $callback['function'] );
		} 
		else {
			$result = $callback['function'];
		}

		return $result;
	}

	function output() {
		global $wp_filter;

		$output = '';
		$total_actions = 0;
		$total_actions_time = 0;

		foreach ( $this->flow as $action => $data ) {
			$total = $data['time'] * 1000;

			$this->flow[ $action ]['total'] = $total;
			$total_actions_time += $total;
			$total_actions += $data['count'];

			$this->flow[ $action ]['callbacks_count'] = 0;

			if ( ! isset( $wp_filter[ $action ] ) ) {
				continue;
			}

			#var_dump( $action, $wp_filter[ $action ]->timings );exit;
			// Add all filter callbacks.
			foreach ( $wp_filter[ $action ] as $priority => $callbacks ) {
				if ( ! isset( $this->flow[ $action ]['callbacks'][ $priority ] ) ) {
					$this->flow[ $action ]['callbacks'][ $priority ] = array();
				}

				#if ( 'template_redirect' == $action ) { var_dump($wp_filter[ $action ]->timings);exit; }
				foreach ( $callbacks as $key => $callback ) {
					$callback_text = $this->get_callback_to_text( $callback );
					#$this->flow[ $action ]['callbacks'][ $priority ][] = $callback_text;
					
					$timing = null;
					if ( isset( $wp_filter[ $action ]->timings[$callback_text . '_' . $priority] ) ) {
						$timing = $wp_filter[ $action ]->timings[$callback_text . '_' . $priority];
					}
					$this->flow[ $action ]['callbacks'][ $priority ][] = [ 
						'callback' => $callback_text,
						'timing' => $timing
					];

					$this->flow[ $action ]['callbacks_count']++;
				}

				#var_dump($this->flow[ $action ]['callbacks'][ $priority ]);exit;
			}
		}

		uasort( $this->flow, array( $this, 'sort_actions_by_time' ) );
		$slowest_action = reset( $this->flow );

		$table = '<table>';
		$table .= '<tr>';
		$table .= '<th>Action or Filter</th>';
		$table .= '<th style="text-align: right;">Callbacks</th>';
		$table .= '<th style="text-align: right;">Calls</th>';
		$table .= '<th style="text-align: right;">Per Call</th>';
		$table .= '<th style="text-align: right;">Total</th>';
		$table .= '</tr>';

		foreach ( array_slice( $this->flow, 0, 100 ) as $action => $data ) {

			$callbacks_output = '<ol class="dbsa-callbacks">';
			foreach ( $data['callbacks'] as $priority => $callbacks ) {
				$i = 1;
				foreach ( $callbacks as $callback ) {
					#$callbacks_output .= sprintf( '<li value="%d">%s [%d]</li>', $priority, $callback, $priority );
					$timing =  $callback['timing'];
					$time = -1;
					if ( null != $timing && isset( $timing['time'] ) ) {
						$time = $timing['time'];
					}
					$callbacks_output .= sprintf( '<li value="%d">%s [%d] [%.2f ms]</li>', $priority, $callback['callback'], $priority, round( $time, 2 ) );

					#$callbacks_output .= sprintf( '<li value="%d">%s</li>', $priority, $callback['callback'], $priority, round( $time, 2 ) );

					#$time = 0;
					#$time = ( $data['stack'][$i] * 1000 )- ( $data['stack'][$i-1] * 1000 );
					#$callbacks_output .= sprintf( '<li value="%d">%s (%.2fms)</li>', $priority, $callback, $time );
					#$i++;
				}
			}
			$callbacks_output .= '</ol>';

			$table .= '<tr>';
			$table .= sprintf( '<td><span class="dbsa-action">%s</span> %s</td>', $action, $callbacks_output );
			$table .= sprintf( '<td style="text-align: right;">%d</td>', $data['callbacks_count'] );
			$table .= sprintf( '<td style="text-align: right;">%d</td>', $data['count'] );
			$table .= sprintf( '<td style="text-align: right;">%.2fms</td>', $data['total'] / $data['count'] );
			$table .= sprintf( '<td style="text-align: right;">%.2fms</td>', $data['total'] );
			$table .= '</tr>';
		}
		$table .= '</table>';

		$output .= sprintf( '<h2><span>Unique actions:</span> %d</h2>', count( $this->flow ) );
		$output .= sprintf( '<h2><span>Total actions:</span> %d</h2>', $total_actions );
		$output .= sprintf( '<h2><span>Actions Execution time:</span> %.2fms</h2>', $total_actions_time );
		$output .= sprintf( '<h2><span>Slowest Action:</span> %.2fms</h2>', $slowest_action['total'] );

		$output .= '<div class="clear"></div>';
		$output .= '<h3>Slow Actions</h3>';

		$output .= $table;

		$output .= <<<EOD
		<style>
			#dbsa-container table {
				border-spacing: 0;
				width: 100%;
			}
			#dbsa-container td,
			#dbsa-container th {
				padding: 6px;
				border-bottom: solid 1px #ddd;
			}
			#dbsa-container td {
				font: 12px Monaco, 'Courier New', Courier, Fixed !important;
				line-height: 180% !important;
				cursor: pointer;
				vertical-align: top;
			}
			#dbsa-container tr:hover {
				background: #e8e8e8;
			}
			#dbsa-container th {
				font-weight: 600;
			}
			#dbsa-container h3 {
				float: none;
				clear: both;
				font-family: georgia, times, serif;
				font-size: 22px;
				margin: 15px 10px 15px 0 !important;
			}
			ol.dbsa-callbacks {
				list-style: decimal;
				padding-left: 50px;
				color: #777;
				margin-top: 10px;
				display: none;
			}
			.dbsa-expanded ol.dbsa-callbacks {
				display: block;
			}
			.dbsa-action:before {
				content: '\\f140';
				display: inline-block;
				-webkit-font-smoothing: antialiased;
				font: normal 20px/1 'dashicons';
				vertical-align: top;
				color: #aaa;
				margin-right: 4px;
				margin-left: -6px;
			}
			.dbsa-expanded .dbsa-action:before {
				content: '\\f142';
			}
		</style>
EOD;
		$output .= <<<EOD
		<script>
			(function($){
				$('#dbsa-container td').on('click', function() {
					$(this).parents('tr').toggleClass('dbsa-expanded');
				});
			}(jQuery));
		</script>
EOD;

		return $output;
	}

	protected $filter_times = [];

	public function set_time( $filter_name, $callback, $priority, $time ) {
		$this->filter_times[$filter_id] = $time;
	}
}

$swp_sa = new Slow_Actions();
