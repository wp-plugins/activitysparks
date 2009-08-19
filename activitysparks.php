<?php
/*

Plugin name: Activity Sparks
Plugin URI: http://www.pantsonhead.com/wordpress/activitysparks/
Description: A widget to display a customizable sparkline graph of post and/or comment activity.
Version: 0.2
Author: Greg Jackson
Author URI: http://www.pantsonhead.com

Copyright 2009  Greg Jackson  (email : greg@pantsonhead.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/


class activitysparks extends WP_Widget {

	function activitysparks() {
	  $widget_ops = array('classname' => 'activitysparks',
                      'description' => 'A sparkline chart for posts/comments');
		$this->WP_Widget('activitysparks', 'Activity Sparks', $widget_ops);
	}
	
	function widget($args, $instance) {
		extract($args);
		$title = apply_filters('widget_title', empty($instance['title']) ? '' : $instance['title']);
		$cachetime = intval($instance['cachetime']);

		if($cachetime){
			$url = $instance['url'];
			if($url=='' OR (time()-intval($instance['url_time']))>$cachetime) {
				$settings = get_option($this->option_name);
				$url = $settings[$this->number]['url'] = $this->build_url($instance);
				$settings[$this->number]['url_time']=time();
				update_option( $this->option_name, $settings );
			}
		} else {
			// no caching - just build it
			$url = $this->build_url($instance);
		}
		// output
		echo $before_widget;
		if($title)
			echo $before_title.$title.$after_title;
		echo '<img src="'.$url.'">';
		echo $after_widget;
	
	}
	
	function build_url($instance) {
		$dataset = empty($instance['dataset']) ? 'posts' : $instance['dataset'];
		$width_px = empty($instance['width_px']) ? 250 : $instance['width_px'];
		$height_px = empty($instance['height_px']) ? 30 : $instance['height_px'];
		$period = empty($instance['period']) ? 30 : $instance['period'];
		$ticks = empty($instance['ticks']) ? 100 : $instance['ticks'];
		$chma = empty($instance['chma']) ? 0 : $instance['chma'];
		$bkgrnd = empty($instance['bkgrnd']) ? 'FFFFFF' : $instance['bkgrnd'];
		$posts_color = empty($instance['posts_color']) ? '4D89F9' : $instance['posts_color'];
		$comments_color = empty($instance['comments_color']) ? 'FF9900' : $instance['comments_color'];
		
		// load the data
		if($dataset != 'comments')
			$posts_data = $this->get_datapoints('posts',$period);
		if($dataset != 'posts')
			$comments_data = $this->get_datapoints('comments',$period);
			
		// build the URL for Google Chart API
		$url = 'http://chart.apis.google.com/chart?chs='.$width_px.'x'.$height_px.'&cht=ls';
		$chd = ''; 
		if($dataset != 'comments') {
			$chd = $posts_data;
			$chco = $posts_color;
			if($dataset != 'posts'){
				$chd .= '|';
				$chco .= ',';
			}
		}
		if($dataset != 'posts') {
			$chd .= $comments_data;
			$chco .= $comments_color;
		}
		$url .= '&chd=t:'.$chd;
		// line color(s)
		$url .= '&chco='.$chco;
		
		// display legend?
		if($dataset == 'legend')
			$url .= '&chdl=Posts|Comments';
		//background color ?
		if($bkgrnd=='NONE')
			$bkgrnd = 'FFFFFF00'; // transparent background
		if($bkgrnd!='FFFFFF')
			$url .= '&chf=bg,s,'.$bkgrnd; 
	
		// margin padding ?
		if($chma) {
			$url .= "&chma=$chma,$chma,$chma,$chma";
			if($dataset=='legend')
				$url .= '|90,20';
		}
		return $url;
	}
	
	
	
	function get_datapoints($type='posts', $period=30, $ticks=100) {
		global $wpdb;
		$wpdb->show_errors();
		$now_tick = $wpdb->get_row("SELECT ROUND((TO_DAYS(now()))/$period) tick")->tick;

		if($type=='posts') {
			$sql = "SELECT ROUND((TO_DAYS(post_date))/$period) ticker, count(*) value 
				FROM {$wpdb->prefix}posts
				WHERE post_status='publish'
					AND post_date > date_sub(now(), interval ($ticks*$period) day)
					GROUP BY ticker 
					ORDER BY ticker";
		}
		if($type=='comments') {
			$sql = "SELECT ROUND((TO_DAYS(comment_date))/$period) ticker, count(*) value 
				FROM {$wpdb->prefix}comments
				WHERE comment_approved='1'
					AND comment_date > date_sub(now(), interval ($ticks*$period) day)
					GROUP BY ticker 
					ORDER BY ticker";
		}
	
		$rows = $wpdb->get_results($sql);

		$data = array();
		$maxval=0;
		foreach($rows as $row){
			$end_ticker=$row->ticker;
			$data[$row->ticker] = $row->value;
			if($maxval< $row->value){
				$maxval=$row->value;
			}
		}

		// "normalize" data and build CSV 
		if($maxval<4) $maxval=4;
		for($i=$now_tick-$ticks;$i<=$now_tick;$i++){
			$value = $maxval ? intval(($data[$i]/$maxval)*100) : 0;
			$data_points .= (isset($data_points)) ?  ','.$value : $value;
		}
		return $data_points;
	}
	
	function update($new_instance, $old_instance) {
	  $instance = $old_instance;
	  $instance['title'] = strip_tags(stripslashes($new_instance['title']));
		$instance['dataset'] = $new_instance['dataset'];
		$instance['width_px'] = intval($new_instance['width_px']);
		$instance['height_px'] = intval($new_instance['height_px']);
		$instance['period'] = intval($new_instance['period']);
		$instance['ticks'] = intval($new_instance['ticks']);
		$instance['chma'] = intval($new_instance['chma']);
		$instance['bkgrnd'] = strtoupper($new_instance['bkgrnd']);
		$instance['posts_color'] = strtoupper($new_instance['posts_color']);
		$instance['comments_color'] = strtoupper($new_instance['comments_color']);
		$instance['cachetime'] = intval($new_instance['cachetime']);
		$instance['url'] = ''; // flush the cache
	  return $instance;
	}
	
	function form($instance) {
		
	  $instance = wp_parse_args((array)$instance, array(
			'title' => 'Recent Activity', 
			'width_px' => 250, 
			'height_px' => 50,
			'period' => 7,
			'ticks' => 90,
			'chma' => 5,
			'bkgrnd' => 'FFFFFF',
			'posts_color' => '4D89F9',
			'comments_color' => 'FF9900'
			));
		
	  $title = htmlspecialchars($instance['title']);
		$dataset = $instance['dataset'];
	  $width_px = intval($instance['width_px']);
		$height_px = intval($instance['height_px']);
		$period = intval($instance['period']);
		$ticks = intval($instance['ticks']);
		$chma  = intval($instance['chma']); // Graph Margin
		$bkgrnd = htmlspecialchars($instance['bkgrnd']);
		$posts_color = htmlspecialchars($instance['posts_color']);
		$comments_color = htmlspecialchars($instance['comments_color']);
		$cachetime = intval($instance['cachetime']);

		${'dataset_'.$dataset} = 'SELECTED';
		${'cachetime_'.$cachetime} = 'SELECTED';
		${'period_'.$period} = 'SELECTED';
		
  
		echo '<p>
			<label for="'.$this->get_field_name('title').'">Title: </label> 
			<input type="text" id="'.$this->get_field_id('title').'" name="'.$this->get_field_name('title').'" value="'.$title.'"/>
			</p>
			<p>
				<label for="'.$this->get_field_name('dataset').'">Display Style: </label><br />
				<select id="'.$this->get_field_id('dataset').'" name="'.$this->get_field_name('dataset').'">
					<option value="posts" '.$dataset_posts.'>Posts</option>
					<option value="comments" '.$dataset_comments.'>Comments</option>
					<option value="both" '.$dataset_both.'>Posts + Comments</option>
					<option value="legend" '.$dataset_legend.'>Posts + Comments with legend</option>
				</select>
			</p>
			<p>
				<label for="'.$this->get_field_name('cachetime').'">Caching: </label> 
				<select id="'.$this->get_field_id('cachetime').'" name="'.$this->get_field_name('cachetime').'">
					<option value="0" '.$cachetime_0.'>None</option>
					<option value="300" '.$cachetime_300.'>Short (5 mins) </option>
					<option value="3600" '.$cachetime_3600.'>Long (1 hour) </option>
				</select>
			</p>
			<table cellpadding="0" cellspacing="0" width="100%">
			<tr><td width="50%">
				<p>
				<label for="'.$this->get_field_name('period').'">Period: </label><br />
				<select id="'.$this->get_field_id('period').'" name="'.$this->get_field_name('period').'">
				<option value="1" '.$period_1.'>Day</option>
				<option value="7" '.$period_7.'>Week</option>
				<option value="14" '.$period_14.'>Fortnight</option>
				<option  value="30" '.$period_30.'>Month</option>
				</select>
				</p>
			</td><td>
				<p>
				<label for="'.$this->get_field_name('ticks').'">Ticks: </label><br />
				<input type="text" id="'.$this->get_field_id('ticks').'" name="'.$this->get_field_name('ticks').'" value="'.$ticks.'" style="width:80px" />
				</p>
			</td></tr>
			<tr><td colspan="2">
			<span class="description">Change the Period to suit the frequency of your posts, and Ticks to limit how many periods to graph.<br>&nbsp;</span>
			</td></tr>
			<tr><td>
				<p>
				<label for="'.$this->get_field_name('width_px').'">Width (px): </label><br />
				<input type="text" id="'.$this->get_field_id('width_px').'" name="'.$this->get_field_name('width_px').'" value="'.$width_px.'"/ style="width:80px" />
				</p>
			</td><td>
				<p>
				<label for="'.$this->get_field_name('height_px').'">Height (px): </label><br />
				<input type="text" id="'.$this->get_field_id('height_px').'" name="'.$this->get_field_name('height_px').'" value="'.$height_px.'" style="width:80px" />
				</p>
			</td></tr>
			<tr><td>
				<p>
				<label for="'.$this->get_field_name('posts_color').'">Posts: </label><br />
				<input type="text" id="'.$this->get_field_id('posts_color').'" name="'.$this->get_field_name('posts_color').'" value="'.$posts_color.'"/ style="width:80px" />
				</p>
			</td><td>
				<p>
				<label for="'.$this->get_field_name('comments_color').'">Comments: </label><br />
				<input type="text" id="'.$this->get_field_id('comments_color').'" name="'.$this->get_field_name('comments_color').'" value="'.$comments_color.'" style="width:80px" />
				</p>
			</td></tr>
			</table>
			<p>
			<label for="'.$this->get_field_name('bkgrnd').'">Background: </label><br />
			<input type="text" id="'.$this->get_field_id('bkgrnd').'" name="'.$this->get_field_name('bkgrnd').'" value="'.$bkgrnd.'" style="width:80px" /> e.g. FFFFAA or NONE
			</p>
			<p>
			<label for="'.$this->get_field_name('chma').'">Graph Margin (px): </label> 
			<input type="text" id="'.$this->get_field_id('chma').'" name="'.$this->get_field_name('chma').'" value="'.$chma.'" style="width:80px" />
			</p>
			';
	}
	
}

function activitysparks_init() {
  register_widget('activitysparks');
}

add_action('widgets_init', 'activitysparks_init');

?>