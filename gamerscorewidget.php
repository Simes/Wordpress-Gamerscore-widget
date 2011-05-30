<?php
/*
Plugin Name: Gamerscore Widget
Description: Adds a sidebar widget to show a list of gamerscores.
Author: Simon Brown
Version: 2.0
Author URI: http://ready-up.net/

1.0 brings an option to restrict the shown list to only the N top scorers, and staggers refresh times for each gamertag to avoid long waits for the widget to load.

2.0 brings the awesome background image
*/


require_once("parser/gamercard.php");

if( !function_exists('get_option') )
{
	require_once('../../../wp-config.php');
}

class Gamerscore
{
	var $name, $gamertag, $score, $wascached;
}

function rugs_compare_gamerscores($score1, $score2)
{
	// Sort in descending order of gamerscore
	if ($score1->score == $score2->score)
	{
		return 0;
	}

	return ($score1->score < $score2->score) ? 1 : -1;
}

class GamerscoreWidget
{
	var $pluginPath = "";

	function setWidgetOptions()
	{
		$options = get_option('readyup_gamerscore_widget');
		if (!is_array($options)) {
			$options = array();
		}

		if ($_POST["readyup_gamerscore_widget"]) {
			$options['results'] = stripslashes($_POST["readyup_gamerscore_widget_results"]);
			$options['preamble'] = stripslashes($_POST["readyup_gamerscore_widget_preamble"]);
			$options['postamble'] = stripslashes($_POST["readyup_gamerscore_widget_postamble"]);

			if (!is_numeric($options['results']))
			{
				$options['results'] = -1;
			}
		}

		update_option('readyup_gamerscore_widget', $options);

		$results = htmlspecialchars($options['results'], ENT_QUOTES);
		$preamble = htmlspecialchars($options['preamble'], ENT_QUOTES);
		$postamble = htmlspecialchars($options['postamble'], ENT_QUOTES);
?>          
		<dl>
			<dt><strong>Number of scores to display<br/>(-1 shows all)</strong></dt>
			<dd>
				<input name="readyup_gamerscore_widget_results" type="text" value="<?php echo $results; ?>" />
			</dd>
		</dl> 
		<dl>
			<dt><strong>HTML to insert before widget</strong></dt>
			<dd>
				<input name="readyup_gamerscore_widget_preamble" type="text" value="<?php echo $preamble; ?>" />
			</dd>
		</dl> 
		<dl>
			<dt><strong>HTML to insert after widget</strong></dt>
			<dd>
				<input name="readyup_gamerscore_widget_postamble" type="text" value="<?php echo $postamble; ?>" />
			</dd>
		</dl> 
		<input type="hidden" name="readyup_gamerscore_widget" value="1" />
<?php

	}

	function showGamertagField()
	{
		global $user_ID;
		// if editing a different user (only admin)
		if (isset($_GET['user_id'])) 
		{
			$get_user_id = $_GET['user_id'];
			if (!current_user_can('edit_user', $get_user_id))
				return;
		}
		//editing own profile
		else 
		{
			if (!isset($user_ID))
				return;

			$get_user_id = $user_ID;
		}

		$user_info = get_userdata($get_user_id);
		if ($user_info->user_level <= 0)
		{
			return;
		}

		if($_POST["gsw_Gamertag"])
			$gamertag = $_POST["gsw_Gamertag"];
		else
			$gamertag = get_usermeta($get_user_id, "gsw_Gamertag");

		echo "<fieldset> <legend> Xbox Live Gamertag </legend>";
		echo "\t<p><label>Gamertag:";
		echo '<input  type="text" class="input" name="gsw_Gamertag" value="'.$gamertag.'"  size="25" tabindex="20" />';
		echo "</label></p>";
		echo "</fieldset>";

	}

	function saveGamertag()
	{
		global $user_ID;

		// if editing a different user (only admin)
		if (isset($_POST['user_id'])) {
			$get_user_id = $_POST['user_id'];
			if (!current_user_can('edit_user', $get_user_id))
				return;
		}
		//editing own profile
		else {
			if (!isset($user_ID))
				return;

			$get_user_id = $user_ID;
		}
		
		$user_info = get_userdata($get_user_id);
		if ($user_info->user_level <= 0)
		{
			return;
		}

		update_usermeta($get_user_id, "gsw_Gamertag", $_POST['gsw_Gamertag']);

	}

	function showGamerScores($args)
	{
		extract($args);
	
		$this->pluginPath = WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename(__FILE__));

		
		$scores = $this->getGamerScores();


		$options = get_option('readyup_gamerscore_widget');
		if (!is_array($options)) {
			$options = array();
		}


		$maxscores = $options['results'];
		if (!is_numeric($maxscores) || $maxscores < 1)
		{
			$maxscores = -1;
		}

		if ($maxscores > sizeof($scores))
		{
			$maxscores = sizeof($scores);
		}

		if (sizeof($scores) > 0)
		{

			echo $before_widget;
			echo $options['preamble'];
			echo $before_title;
			if ($maxscores > 0 && sizeof($scores) > $maxscores)
			{
				echo "Top ";
			}
			echo "Gamerscore";
			if (sizeof($scores) > 1)
				echo "s";
			echo $after_title;
			echo '<style type="text/css">ul.gsw li {text-align: right;} span.gswt { float: left;} div.gamerscorewidget { background: url(' . $this->pluginPath . "gamerscore-bg.jpg" . ')</style>';
			echo "<div class=\"gamerscorewidget\">";
			echo "<ul class=\"gsw\">";
			$i = 0;
			foreach($scores as $key => $score)
			{
				if ($i == $maxscores)
				{
					break;
				}
				echo "<li><span class=\"gswt\">" . $score->name;
				if ($score->name != $score->gamertag)
				{
					echo " (" . $score->gamertag . ")";
				}
				echo "</span>" . $score->score . "</li>";
				$i ++;
			}
			
			echo "</ul>";
			echo "</div>";
			echo $options['postamble'];
			echo $after_widget;
		}
	}
	
	function getGamerscores()
	{

		// Some setup for the caching
		// First we need to know what time it is right now, or at least the hours and minutes values
		$date_time = getdate();
		
		// Then we initialise the values so that the first expiry time is one hour from now
		$expire_hour = $date_time['hours'] + 1;
		$expire_min = $date_time['minutes'];

		// Next we get a timestamp value for now to compare things against
		$now = time();

		global $wpdb;
		$ids = $wpdb->get_col("SELECT ID from $wpdb->users;");
		if (sizeof($ids) == 1)
		{
			$ids = array($ids);
		}

		$result = array();
		foreach ($ids as $id)
		{
			$userinfo = get_userdata($id);
			if ($userinfo->user_level > 0)
			{


				$score = $this->getUserGamerscore($id, $userinfo, $now, $expire_hour, $expire_min);
				if ($score != '')
				{
					// This means the user has a gamertag and we now have a score value
					$result[] = $score;
					if ($score->wascached != true)
					{
						// Not a cached score, so we need to update the expiry for the next one
						$expire_min = $expire_min + 5;
					}
				}
			}

		}
		usort($result, "rugs_compare_gamerscores");

		return $result;
	}

	function getUserGamerscore($id, $userinfo, $now, $expire_hour, $expire_min)
	{

		$gamertag = get_usermeta($id, "gsw_Gamertag");

		if ($gamertag == '')
		{
			return('');
		}

		$score = new Gamerscore();

		$expiry = get_usermeta($id, "gsw_Expiry");
		$gamerscore = get_usermeta($id, "gsw_Gamerscore");

		if (is_numeric($gamerscore))
		{
			$score->score = $gamerscore;
			$score->was_cached = true;
		}
		else
		{
			$score->score = '--';
			$score->was_cached = false;
		}

		$score->name = $userinfo->display_name;
		$score->gamertag = $gamertag;

		if (!is_numeric($gamerscore) || !is_numeric($expiry) || $now > $expiry)
		{
			$card = getGamerCard($gamertag);
			if ($card != "")
			{
				$score->score = $card->score;
				$score->was_cached = false;
				update_usermeta($id, "gsw_Gamerscore", $score->score);
			}
			$new_expiry = mktime($expire_hour, $expire_min);
			update_usermeta($id, "gsw_Expiry", $new_expiry);

		}
		return $score;
	}
	
}

	function rugsWidgetInit()
	{
		$gamerscoreWidget = new GamerscoreWidget();

	    // add extra fields to user's profile
	    add_action('show_user_profile', array(&$gamerscoreWidget,'showGamertagField'));

	    // add extra fields in users edit profiles (for ADMIN)
	    add_action('edit_user_profile', array(&$gamerscoreWidget,'showGamertagField'));

		// add update engine for extra fields to users edit profiles
		add_action('profile_update', array(&$gamerscoreWidget,'saveGamertag'));

		register_sidebar_widget("Gamerscores", array(&$gamerscoreWidget, "showGamerscores"));

		register_widget_control("Gamerscores", array(&$gamerscoreWidget, "setWidgetOptions"));
	}

	add_action('plugins_loaded', 'rugsWidgetInit');
?>