=== Slow Actions ===
Contributors: satellitewp, maximejobin
Donate Link: http://www.satellitewp.com
Tags: debug, actions, profiling
Requires at least: 5.0
Tested up to: 6.1
Stable tag: 0.8.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Easily find out which actions and filters are the slowest during a page load.

== Description ==

This plugin lists the top 100 slowest actions and filters during a page request in WordPress. It helps you figure out performance bottlenecks in themes and plugins.

Requires [Debug Bar](http://wordpress.org/plugins/debug-bar/ "Debug Bar").

Current limitations:

* Does not time nested actions and filters due to a core bug
* Does not time actions and filters before plugins_loaded or muplugins_loaded if placed in mu-plugins
* Does not time actions and callbacks after wp_footer at priority 1000

== Screenshots ==

1. Screenshot

== Changelog ==

= 0.9 =
* First version