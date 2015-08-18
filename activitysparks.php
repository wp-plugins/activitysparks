<?php
/*

Plugin name: Activity Sparks
Plugin URI: http://www.pantsonhead.com/wordpress/activitysparks/
Description: A widget to display a customizable sparkline graph of post and/or comment activity.
Version: 0.6
Author: Greg Jackson


Copyright 2015  Greg Jackson  (email : greg@gregjxn.com)

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
		
		$category_id=0;
		
		if(is_category() and $instance['chart_category']){
			$category_ids = get_all_category_ids(); 
			foreach($category_ids as $cat_id) {
				if($category_id==0) 
					if(is_category($cat_id))
						$category_id = $cat_id;
			}
		}

		$title = apply_filters('widget_title', empty($instance['title']) ? '' : $instance['title']);
		$cachetime = intval($instance['cachetime']);

		if($cachetime){
			$cachekey = md5($args['widget_id'].$category_id);
			$url = get_transient($cachekey);
			if($url===FALSE){
				$url = $this->build_url($instance,$category_id);
				set_transient($cachekey,$url,$cachetime);
			}
		} else {
			// no caching - just build it
			$url = $this->build_url($instance,$category_id);
		}
		// output
		echo $before_widget;
		if($title)
			echo $before_title.$title.$after_title;
		echo '<img src="'.$url.'">';
		echo $after_widget;
	}
	
	function build_url($instance,$category_id=0) {
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
			$posts_data = $this->get_datapoints('posts',$period,$ticks,$category_id);
		if($dataset != 'posts')
			$comments_data = $this->get_datapoints('comments',$period,$ticks,$category_id);
			
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
	
	
	
	function get_datapoints($type='posts', $period=30, $ticks=100,$category_id=0) {
		global $wpdb;
		$wpdb->show_errors();
		$now_tick = $wpdb->get_row("SELECT ROUND((TO_DAYS(now()))/$period) tick")->tick;

		if($category_id) {
			$category_posts_join = " INNER JOIN {$wpdb->prefix}term_relationships ON {$wpdb->prefix}posts.ID = {$wpdb->prefix}term_relationships.object_id
				INNER JOIN {$wpdb->prefix}term_taxonomy ON {$wpdb->prefix}term_relationships.term_taxonomy_id = {$wpdb->prefix}term_taxonomy.term_taxonomy_id ";
			$category_comments_join = " INNER JOIN {$wpdb->prefix}term_relationships ON {$wpdb->prefix}comments.comment_post_ID = {$wpdb->prefix}term_relationships.object_id
				INNER JOIN {$wpdb->prefix}term_taxonomy ON {$wpdb->prefix}term_relationships.term_taxonomy_id = {$wpdb->prefix}term_taxonomy.term_taxonomy_id ";
			$category_where = " AND {$wpdb->prefix}term_taxonomy.taxonomy = 'category' AND {$wpdb->prefix}term_taxonomy.term_id = $category_id ";	
		} else {
			$category_posts_join = $category_comments_join = $category_where = '';
		}
		
		if($type=='posts') {
			$sql = "SELECT ROUND((TO_DAYS(post_date))/$period) ticker, count(*) value 
				FROM {$wpdb->prefix}posts $category_posts_join
				WHERE post_status='publish' $category_where
					AND post_date > date_sub(now(), interval ($ticks*$period) day)
					GROUP BY ticker 
					ORDER BY ticker";
		}
		if($type=='comments') {
			$sql = "SELECT ROUND((TO_DAYS(comment_date))/$period) ticker, count(*) value 
				FROM {$wpdb->prefix}comments $category_comments_join
				WHERE comment_approved='1' $category_where
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
		$data_points = '';
		for($i=$now_tick-$ticks;$i<=$now_tick;$i++){
			if(!isset($data[$i])) { $data[$i] = 0; };
			$value = $maxval ? intval(($data[$i]/$maxval)*100) : 0;
			$data_points .= (!empty($data_points)) ?  ','.$value : $value;
		}
		return $data_points;
	}
	
	function update($new_instance, $old_instance) {
		$instance = $old_instance;

		// prevent PHP Notice: Undefined index: chart_category
		if( !isset( $new_instance['chart_category'] ) ) {
			$new_instance['chart_category'] = 0;
		}

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
		$instance['chart_category'] = intval($new_instance['chart_category']);

	  return $instance;
	}
	
	function form($instance) {
		
	  $instance = wp_parse_args((array)$instance, array(
			'title' => __('Recent Activity','activitysparks'), 
			'width_px' => 250, 
			'height_px' => 50,
			'period' => 7,
			'ticks' => 90,
			'chma' => 5,
			'bkgrnd' => 'FFFFFF',
			'posts_color' => '4D89F9',
			'comments_color' => 'FF9900',
			'dataset' => 'posts',
			'cachetime' => 0,
			'chart_category' => 0
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
		$chart_category = intval($instance['chart_category']);

		${'period_'.$period} = 'SELECTED';
		$chart_category_checked = $chart_category ? 'checked': '';
  
		echo '
		<style type="text/css">.color_swatch {width:12px;height:12px;}</style>
		<p>
			<label for="'.$this->get_field_name('title').'">'.__('Title:','activitysparks').' </label> 
			<input type="text" id="'.$this->get_field_id('title').'" name="'.$this->get_field_name('title').'" value="'.$title.'"/>
			</p>
			<p>
				<label for="'.$this->get_field_name('dataset').'">'.__('Display Style:','activitysparks').' </label><br />
				<select id="'.$this->get_field_id('dataset').'" name="'.$this->get_field_name('dataset').'">
					<option value="posts" '.selected( $dataset, 'posts', FALSE).'>'.__('Posts','activitysparks').'</option>
					<option value="comments" '.selected( $dataset, 'comments', FALSE).'>'.__('Comments','activitysparks').'</option>
					<option value="both" '.selected( $dataset, 'both', FALSE).'>'.__('Posts + Comments','activitysparks').'</option>
					<option value="legend" '.selected( $dataset, 'legend', FALSE).'>'.__('Posts + Comments with legend','activitysparks').'</option>
				</select>
			</p>
			<p>
				<input id="'.$this->get_field_id('chart_category').'" name="'.$this->get_field_name('chart_category').'" type="checkbox" value="1" '.$chart_category_checked.'> 
				<label for="'.$this->get_field_name('chart_category').'">'.__('Show activity per category page.','activitysparks').'</label>
			</p>
			<p>
				<label for="'.$this->get_field_name('cachetime').'">'.__('Caching:','activitysparks').' </label> 
				<select id="'.$this->get_field_id('cachetime').'" name="'.$this->get_field_name('cachetime').'">
					<option value="0" '.selected( $cachetime, 0, FALSE).'>'.__('None','activitysparks').'</option>
					<option value="300" '.selected( $cachetime, 300, FALSE).'>'.__('Short (5 mins)','activitysparks').' </option>
					<option value="3600" '.selected( $cachetime, 3600, FALSE).'>'.__('Long (1 hour)','activitysparks').' </option>
				</select>
			</p>
			<table cellpadding="0" cellspacing="0" width="100%">
			<tr><td width="50%">
				<p>
				<label for="'.$this->get_field_name('period').'">'.__('Period:','activitysparks').' </label><br />
				<select id="'.$this->get_field_id('period').'" name="'.$this->get_field_name('period').'">
				<option value="1" '.selected( $period, 1, FALSE).'>'.__('Day','activitysparks').'</option>
				<option value="7" '.selected( $period, 7, FALSE).'>'.__('Week','activitysparks').'</option>
				<option value="14" '.selected( $period, 14, FALSE).'>'.__('Fortnight','activitysparks').'</option>
				<option  value="30" '.selected( $period, 30, FALSE).'>'.__('Month','activitysparks').'</option>
				</select>
				</p>
			</td><td>
				<p>
				<label for="'.$this->get_field_name('ticks').'">'.__('Ticks:','activitysparks').' </label><br />
				<input type="text" id="'.$this->get_field_id('ticks').'" name="'.$this->get_field_name('ticks').'" value="'.$ticks.'" style="width:80px" />
				</p>
			</td></tr>
			<tr><td colspan="2">
			<span class="description">'.__('Change the Period to suit the frequency of your posts, and Ticks to limit how many periods to graph.','activitysparks').'<br>&nbsp;</span>
			</td></tr>
			<tr><td>
				<p>
				<label for="'.$this->get_field_name('width_px').'">'.__('Width (px):','activitysparks').' </label><br />
				<input type="text" id="'.$this->get_field_id('width_px').'" name="'.$this->get_field_name('width_px').'" value="'.$width_px.'"/ style="width:80px" />
				</p>
			</td><td>
				<p>
				<label for="'.$this->get_field_name('height_px').'">'.__('Height (px):','activitysparks').' </label><br />
				<input type="text" id="'.$this->get_field_id('height_px').'" name="'.$this->get_field_name('height_px').'" value="'.$height_px.'" style="width:80px" />
				</p>
			</td></tr>
			<tr><td>
				<p>
				<label for="'.$this->get_field_name('posts_color').'">'.__('Posts:','activitysparks').' </label><br />
				<input type="text" id="'.$this->get_field_id('posts_color').'" name="'.$this->get_field_name('posts_color').'" value="'.$posts_color.'" style="width:60px" />
				<input id="swatch1" class="color_swatch" disabled="disabled" style="background:#'.$posts_color.'">
				</p>
			</td><td>
				<p>
				<label for="'.$this->get_field_name('comments_color').'">'.__('Comments:','activitysparks').' </label><br />
				<input type="text" id="'.$this->get_field_id('comments_color').'" name="'.$this->get_field_name('comments_color').'" value="'.$comments_color.'" style="width:60px" />
				<input id="swatch2" class="color_swatch" disabled="disabled" style="background:#'.$comments_color.'" alt="bob">
				</p>
			</td></tr>
			</table>
			<p>
			<label for="'.$this->get_field_name('bkgrnd').'">'.__('Background:','activitysparks').' </label><br />
			<input type="text" id="'.$this->get_field_id('bkgrnd').'" name="'.$this->get_field_name('bkgrnd').'" value="'.$bkgrnd.'" style="width:60px" />
			<input id="swatch3" class="color_swatch" disabled="disabled" style="background:#'.$bkgrnd.'"> e.g. FFFFAA or NONE
			</p>
			<p>
			<label for="'.$this->get_field_name('chma').'">'.__('Graph Margin (px):','activitysparks').' </label> 
			<input type="text" id="'.$this->get_field_id('chma').'" name="'.$this->get_field_name('chma').'" value="'.$chma.'" style="width:80px" />
			</p>
			';
	}
	
}

function activitysparks_init() {
  register_widget('activitysparks');
}

function activitysparks($settings = array()) {
	$activitysparks = new activitysparks;
	$url = $activitysparks->build_url($settings);
	echo '<img src="'.$url.'">';
}

add_action('widgets_init', 'activitysparks_init');

?>