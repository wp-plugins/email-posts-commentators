<?php

/**
 * Plugin Name: Email Posts Commentators
 * Plugin URI: http://wordpress.org/plugins/email-posts-commentators/
 * Description: Plugin to email commentators of posts
 * Version: 0.1
 * License: GPL
 * Author: Ashfame
 * Author URI: http://ashfame.com/
 * Notes: This plugin assumes your readers are OK with you emailing them. IF not, please refrain from using the plugin.
 */

// die if called directly
defined( 'ABSPATH' ) || die();

class Ashfame_Email_Posts_Commentators {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_scripts' ) );

		// Paid Support link in plugins listing
		add_filter( 'plugin_action_links', array( $this, 'custom_plugin_action_link' ), 10, 2 );
	}

	public function admin_page() {
		$this->hook = add_submenu_page( 'tools.php', 'Email Posts Commentators', 'Email Posts Commentators', 'manage_options', 'email-posts-commentators-page', array( $this, 'email_posts_commentators_callback' ) );
	}

	public function add_scripts( $hook ) {
		if ( $hook == $this->hook ) {
			wp_enqueue_script( 'epc_chosen', plugins_url( 'chosen/chosen.jquery.js', __FILE__ ), array( 'jquery' ), null );
			wp_enqueue_style( 'epc-chosen', plugins_url( 'chosen/chosen.css', __FILE__ ), array(), null );
		}
	}

	public function email_posts_commentators_callback() {
		?>
		<style>
			form { max-width: 700px; }
			form > select, form > input { width: 100%; }
		</style>
		<script>
			(function($){
				$(document).ready(function(){
					$('#selected-posts').chosen();
				});
			})(jQuery);
		</script>
		<div class="wrap">
			<h2>Email Posts Commentators</h2>
			<?php
			if ( isset( $_POST['submit'] ) ) {
				$result = $this->process();
				if ( $result ) {
					echo '<div id="setting-error-settings_updated" class="updated settings-error"><p><strong>' . $result . ( $result == 1 ? ' Email ' : ' Emails ' ) . 'sent successfully!</strong></p></div>';
				} else {
					echo '<div id="setting-error-settings_updated" class="error settings-error"><p><strong>Please check form input</strong></p></div>';
				}
			}
			?>
			<?php $posts = get_posts( array( 'posts_per_page' => -1 ) ); ?>
			<form action="" method="post">
				<p>
					<label><h3>Select posts of which commentators you want to email</h3>
						<select id="selected-posts" name="selected-posts" multiple data-placeholder="Select posts">
							<?php foreach ( $posts as $post ) { ?>
								<option value="<?php echo $post->ID; ?>"><?php echo $post->post_title; ?></option>
							<?php } ?>
						</select>
					</label>
				</p>
				<p>
					<label>
						<h3>Bcc all emails to</h3>
						<input type="text" name="bcc-email" value="<?php echo get_option( 'admin_email' ); ?>" placeholder="<?php echo get_option( 'admin_email' ); ?>" />
					</label>
				</p>
				<p>
					<label>
						<h3>Exclude Emails</h3>
						<input type="text" name="exclude-emails" value="" placeholder="mail@ashfame.com, mark@facebook.com" />
					</label>
				</p>
				<p>
					<label><h3>Email Subject</h3>
						<p>(Use %name% to fill comment author's name)</p>
						<input type="text" name="email-subject" value="Hey %name%, Got an update for you" />
					</label>
				</p>
				<h3>Email message</h3>
				<p>
					<label>(Greeting with commentator's name and their comment history with link to the post is added to your message automatically, just write your message below)<br />
						<?php wp_editor( '', 'email-message', array( 'wpautop' => false, 'media_buttons' => false ) ); ?>
					</label>
				</p>
				<p>
					<input type="submit" name="submit" class="button-primary" value="<?php esc_attr_e( 'Send email' ); ?>" />
				</p>
			</form>
		</div>
		<?php
	}

	public function html_mail_type() {
		return 'text/html';
	}

	public function process() {
		$bail = false;
		$message = stripslashes( $_POST['email-message'] );
		$subject = $_POST['email-subject'];
		$counter = 0;
		
		if ( isset( $_POST['selected-posts'] ) ) {
			$concerned_posts_ids = array_map( 'trim', explode( ',', $_POST['selected-posts'] ) );
		} else {
			$bail = true;
		}

		if ( isset( $_POST['exclude-emails'] ) ) {
			$excluded_emails = array_map( 'trim', explode( ',', $_POST['exclude-emails'] ) ); // admin, blog authors emails should be added to excluded emails but its upto the user
		} else {
			$excluded_emails = array();
		}

		if ( $bail ) {
			return false;
		}

		$posts = array(); // store concerned posts info
		$comments = array(); // store comments of concerned posts

		foreach ( $concerned_posts_ids as $concerned_posts_id ) {
			// collect comments
			$comments = array_merge(
				$comments,
				get_comments(
					array(
						'status' => 'approve',
						'order' => 'ASC',
						'post_id' => $concerned_posts_id
					)
				)
			);

			// get posts data
			$posts[ $concerned_posts_id ] = get_post( $concerned_posts_id );
			// keep only what we want
			$posts[ $concerned_posts_id ] = array(
				'title' => $posts[ $concerned_posts_id ]->post_title,
				'permalink' => get_permalink( $concerned_posts_id )
			);
		}

		// lets structure the data with comment ID as array key
		// also group multiple comments from each person together
		$comments_data = array();
		$comments_grouping = array();
		foreach ( $comments as $comment ) {
			if ( in_array( $comment->comment_author_email, $excluded_emails ) || trim( $comment->comment_type ) != '' ) {
				continue;
			}

			$comments_data[ $comment->comment_ID ] = (array) $comment;
			if ( ! isset( $comments_grouping[ $comment->comment_author_email ] ) ) {
				$comments_grouping[ $comment->comment_author_email ] = array();
			}
			$comments_grouping[ $comment->comment_author_email ][] = $comment->comment_ID;
		}

		$comments = $comments_data;
		unset( $comments_data );

		// temporarily set HTML as mail type
		add_filter( 'wp_mail_content_type', array( $this, 'html_mail_type' ) );

		foreach ( $comments_grouping as $email => $comment_group ) {

			$email_body = '';
			$current_post = 0;
			
			foreach ( $comment_group as $comment_id ) {

				if ( $current_post == 0 || $current_post != $comments[ $comment_id ]['comment_post_ID'] ) {
					$email_body .= '<p><a href="' . $posts[ $comments[ $comment_id ]['comment_post_ID'] ]['permalink'] . '">' . $posts[ $comments[ $comment_id ]['comment_post_ID'] ]['title'] . '</a></p>';
					$current_post = $comments[ $comment_id ]['comment_post_ID'];
				}
				
				$email_body .= '<blockquote>' . $comments[ $comment_id ]['comment_content'] . '</blockquote>';

				$comment_author = $comments[ $comment_id ]['comment_author'];
			}

			$email_body = '<p>Hey ' . $comment_author . ',</p>' . $message . '<p>Thought you would like to know since you previously commented on the following blog post:' . $email_body;
			
			wp_mail( $email, str_replace( '%name%', $comment_author, $subject ), $email_body );

			$counter++;
		}

		// remove our filter to let the mail type be whatever it was before we changed it
		remove_filter( 'wp_mail_content_type', array( $this, 'html_mail_type' ) );

		return $counter;
	}

	public function custom_plugin_action_link( $links, $file ) {
		// Also check using strpos because when plugin is actually a symlink inside plugins folder, its plugin_basename will be based off its actual path
		if ( $file == plugin_basename( __FILE__ ) || strpos( plugin_basename( __FILE__ ), $file ) !== false ) {
			$settings_link = '<a href="' . admin_url( 'tools.php?page=email-posts-commentators-page' ) . '">Send Emails</a>';
			$support_link = '<a href="mailto:mail@ashfame.com?subject=' . rawurlencode('Premium Support') . '">Premium Support</a>';
			$report_issue_link = '<a href="https://github.com/ashfame/Email-Posts-Commentators/issues">Report Issue</a>';
			$links = array_merge( array( $settings_link, $support_link, $report_issue_link ), $links );
		}

		return $links;
	}
}

new Ashfame_Email_Posts_Commentators();