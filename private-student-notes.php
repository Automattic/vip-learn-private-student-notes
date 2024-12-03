<?php
/**
 * Plugin Name:       Private Student Notes
 * Plugin URI:        learn.wpvip.com
 * Description:       A custom block allowing logged-in Sensei students to add and view private notes
 * Requires at least: 6.6
 * Requires PHP:      7.2
 * Version:           0.1.0
 * Author:            VIP Learn
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       private-student-notes
 *
 * @package VipLearn
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require "inc/private-student-notes.class.php";

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * Behind the scenes, it registers also all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 */
function vip_learn_private_student_notes_block_init() {
	
	$psn = new Private_Student_Notes;

	register_block_type( __DIR__ . '/build' );

	$psn->localize_script();

}
add_action( 'init', 'vip_learn_private_student_notes_block_init' );
