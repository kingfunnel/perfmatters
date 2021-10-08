<?php
//initialize lazy loading
function perfmatters_lazy_load_init() {

	$perfmatters_options = get_option('perfmatters_options');

	if(!empty($perfmatters_options['lazyload']['lazy_loading']) || !empty($perfmatters_options['lazyload']['lazy_loading_iframes'])) {

		$exclude_lazy_loading = perfmatters_get_post_meta('perfmatters_exclude_lazy_loading');

		//check if its ok to lazy load
		if(!$exclude_lazy_loading && !is_admin() && !perfmatters_is_dynamic_request() && !isset($_GET['perfmatters']) && !perfmatters_is_page_builder() && !is_embed() && !is_feed() && !is_customize_preview()) {

			//don't lazy load on amp
			if(function_exists('is_amp_endpoint') && is_amp_endpoint()) {
				return;
			}

			//actions + filters
			add_filter('template_redirect', 'perfmatters_lazy_load', 2);
			add_action('wp_enqueue_scripts', 'perfmatters_enqueue_lazy_load');
			add_action('wp_footer', 'perfmatters_print_lazy_load_js', PHP_INT_MAX);
			add_action('wp_head', 'perfmatters_print_lazy_load_css', PHP_INT_MAX);
			add_filter('wp_lazy_loading_enabled', '__return_false');
		}
	}
}
add_action('wp', 'perfmatters_lazy_load_init');

//enqueue lazy load scripts
function perfmatters_enqueue_lazy_load() {

	$perfmatters_options = get_option('perfmatters_options');

	wp_register_script('perfmatters-lazy-load-js', plugins_url('perfmatters/js/lazyload.min.js'), array(), PERFMATTERS_VERSION, true);
	wp_enqueue_script('perfmatters-lazy-load-js');

	if(!empty($perfmatters_options['lazyload']['youtube_preview_thumbnails'])) {
		wp_register_style('perfmatters-lazy-load-css', false);
		wp_enqueue_style('perfmatters-lazy-load-css');
		wp_add_inline_style('perfmatters-lazy-load-css', perfmatters_lazy_load_css());
	}
}

//initialize lazy loading
function perfmatters_lazy_load() {
	ob_start('perfmatters_lazy_load_buffer');
}

//lazy load buffer
function perfmatters_lazy_load_buffer($html) {

	$perfmatters_options = get_option('perfmatters_options');

	//get clean html to use for buffer search
	$buffer = perfmatters_lazy_load_clean_html($html);

	//replace image tags
	if(!empty($perfmatters_options['lazyload']['lazy_loading'])) {

		$html = perfmatters_lazy_load_images($html, $buffer);
		$html = perfmatters_lazy_load_pictures($html, $buffer);
		$html = perfmatters_lazy_load_background_images($html, $buffer);
	}

	//replace iframes + videos
	if(!empty($perfmatters_options['lazyload']['lazy_loading_iframes'])) {
		$html = perfmatters_lazy_load_iframes($html, $buffer);
		$html = perfmatters_lazy_load_videos($html, $buffer);
	}
	
	return $html;
}

//remove unecessary bits from html for buffer searh
function perfmatters_lazy_load_clean_html($html) {

	//remove existing script tags
	$html = preg_replace('/<script\b(?:[^>]*)>(?:.+)?<\/script>/Umsi', '', $html);

	//remove existing noscript tags
    $html = preg_replace('#<noscript>(?:.+)</noscript>#Umsi', '', $html);

    return $html;
}

//lazy load img tags
function perfmatters_lazy_load_images($html, $buffer) {

	//match all img tags
	preg_match_all('#<img([^>]+?)\/?>#is', $buffer, $images, PREG_SET_ORDER);

	if(!empty($images)) {

		//remove any duplicate images
		$images = array_unique($images, SORT_REGULAR);

		//loop through images
        foreach($images as $image) {

        	//prepare lazy load image
            $lazy_image = perfmatters_lazy_load_image($image);

            //replace image in html
            $html = str_replace($image[0], $lazy_image, $html);
        }

        return $html;
	}
		
	return $html;
}

//lazy load picture tags for webp
function perfmatters_lazy_load_pictures($html, $buffer) {

	//match all picture tags
	preg_match_all('#<picture(.*)?>(.*)<\/picture>#isU', $buffer, $pictures, PREG_SET_ORDER);

	if(!empty($pictures)) {

		foreach($pictures as $picture) {

			//get picture tag attributes
			$picture_atts = perfmatters_lazyload_get_atts_array($picture[1]);

			//dont check excluded if forced attribute was found
			if(!perfmatters_lazyload_excluded($picture[1], perfmatters_lazyload_forced_atts())) {

				//skip if no-lazy class is found
				if(!empty($picture_atts['class']) && strpos($picture_atts['class'], 'no-lazy') !== false) {
					continue;
				}

				//skip if exluded attribute was found
				if(perfmatters_lazyload_excluded($picture[1], perfmatters_lazyload_excluded_atts())) {
					continue;
				}
			}

			//match all source tags inside the picture
			preg_match_all('#<source(\s.+)>#isU', $picture[2], $sources, PREG_SET_ORDER);

			if(!empty($sources)) {

				//remove any duplicate sources
				$sources = array_unique($sources, SORT_REGULAR);

	            foreach($sources as $source) {

	            	//skip if exluded attribute was found
					if(perfmatters_lazyload_excluded($source[1], perfmatters_lazyload_excluded_atts())) {
						continue;
					}

	            	//create placeholder src
					$width = !empty($picture_atts['width']) ? $picture_atts['width'] : 0;
					$height = !empty($picture_atts['height']) ? $picture_atts['height'] : 0;
					$placeholder = "data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20" . $width . "%20" . $height . "'%3E%3C/svg%3E";

					//migrate srcet
	                $new_source = preg_replace('/([\s"\'])srcset/i', '${1}srcset="' . $placeholder . '" data-srcset', $source[0]);

	                //migrate sizes
	                $new_source = preg_replace('/([\s"\'])sizes/i', '${1}data-sizes', $new_source);

	                //replace source in html	                
	                $html = str_replace($source[0], $new_source, $html);
	            }
			}
			else {
				continue;
			}

			//match img tag inside the picture
			preg_match('#<img([^>]+?)\/?>#isU', $picture[0], $image);

			if(!empty($image)) {

				//get lazy load image
				$lazy_image = perfmatters_lazy_load_image($image);

				//replace image in html
       	 		$html = str_replace($image[0], $lazy_image, $html);

			}
			else {
				continue;
			}
		}
	}

	return $html;
}

//lazy load background images
function perfmatters_lazy_load_background_images($html, $buffer) {

	//match all elements with inline styles
	//preg_match_all('#<(?<tag>div|figure|section|span|li)(\s+[^>]+[\'"\s]?style\s*=\s*[\'"].*?background-image.*?[\'"][^>]*)>#is', $buffer, $elements, PREG_SET_ORDER); //alternate to possibly filter some out that don't have background images???
	preg_match_all('#<(?<tag>div|figure|section|span|li)(\s+[^>]*[\'"\s]?style\s*=\s*[\'"].*?[\'"][^>]*)>#is', $buffer, $elements, PREG_SET_ORDER);

	if(!empty($elements)) {

		foreach($elements as $element) {

			//get element tag attributes
			$element_atts = perfmatters_lazyload_get_atts_array($element[2]);

			//dont check excluded if forced attribute was found
			if(!perfmatters_lazyload_excluded($element[2], perfmatters_lazyload_forced_atts())) {

				//skip if no-lazy class is found
				if(!empty($element_atts['class']) && strpos($element_atts['class'], 'no-lazy') !== false) {
					continue;
				}

				//skip if exluded attribute was found
				if(perfmatters_lazyload_excluded($element[2], perfmatters_lazyload_excluded_atts())) {
					continue;
				}
			}

			//skip if no style attribute
			if(!isset($element_atts['style'])) {
				continue;
			}

			//match background-image in style string
			preg_match('#background(-image)?\s*:\s*(\s*url\s*\((?<url>[^)]+)\))\s*;?#is', $element_atts['style'], $url);

			if(!empty($url)) {

				$url['url'] = trim($url['url'], '\'" ');

				//add lazyload class
				$element_atts['class'] = !empty($element_atts['class']) ? $element_atts['class'] . ' ' . 'perfmatters-lazy' : 'perfmatters-lazy';

				//remove background image url from inline style attribute
				$element_atts['style'] = str_replace($url[0], '', $element_atts['style']);

				//migrate src
				$element_atts['data-bg'] = 'url(' . esc_attr($url['url']) . ')';

				//build lazy element attributes string
				$lazy_element_atts_string = perfmatters_lazyload_get_atts_string($element_atts);

				//build lazy element
				$lazy_element = sprintf('<' . $element['tag'] . ' %1$s >', $lazy_element_atts_string);

				//replace element with placeholder
				$html = str_replace($element[0], $lazy_element, $html);

				unset($lazy_element);
			}
			else {
				continue;
			}
		}
	}

	return $html;
}

//prep img tag for lazy loading
function perfmatters_lazy_load_image($image) {

	//if there are no attributes, return original match
	if(empty($image[1])) {
		return $image[0];
	}

	//get image attributes array
	$image_atts = perfmatters_lazyload_get_atts_array($image[1]);

	//get new attributes
	if(empty($image_atts['src']) || (!perfmatters_lazyload_excluded($image[1], perfmatters_lazyload_forced_atts()) && ((!empty($image_atts['class']) && strpos($image_atts['class'], 'no-lazy') !== false) || perfmatters_lazyload_excluded($image[1], perfmatters_lazyload_excluded_atts())))) {
		return $image[0];
	}
	else {

		//add lazyload class
		$image_atts['class'] = !empty($image_atts['class']) ? $image_atts['class'] . ' ' . 'perfmatters-lazy' : 'perfmatters-lazy';

		//migrate src
		$image_atts['data-src'] = $image_atts['src'];

		//add placeholder src
		$width = !empty($image_atts['width']) ? $image_atts['width'] : 0;
		$height = !empty($image_atts['height']) ? $image_atts['height'] : 0;
		$image_atts['src'] = "data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20" . $width . "%20" . $height . "'%3E%3C/svg%3E";

		//migrate srcset
		if(!empty($image_atts['srcset'])) {
			$image_atts['data-srcset'] = $image_atts['srcset'];
			unset($image_atts['srcset']);
		}
		
		//migrate sizes
		if(!empty($image_atts['sizes'])) {
			$image_atts['data-sizes'] = $image_atts['sizes'];
			unset($image_atts['sizes']);
		}
	}

	//build lazy image attributes string
	$lazy_image_atts_string = perfmatters_lazyload_get_atts_string($image_atts);

	//replace attributes
	$output = sprintf('<img %1$s />', $lazy_image_atts_string);

	//original noscript image
	$output.= "<noscript>" . $image[0] . "</noscript>";

	return $output;
}

//lazy load iframes
function perfmatters_lazy_load_iframes($html, $buffer) {

	//match all iframes
	preg_match_all('#<iframe(\s.+)>.*</iframe>#iUs', $buffer, $iframes, PREG_SET_ORDER);

	if(!empty($iframes)) {

		//get plugin options
		$perfmatters_options = get_option('perfmatters_options');

		//remove any duplicates
		$iframes = array_unique($iframes, SORT_REGULAR);

		foreach($iframes as $iframe) {

			//get iframe attributes array
			$iframe_atts = perfmatters_lazyload_get_atts_array($iframe[1]);

			//dont check excluded if forced attribute was found
			if(!perfmatters_lazyload_excluded($iframe[1], perfmatters_lazyload_forced_atts())) {

				//skip if exluded attribute was found
				if(perfmatters_lazyload_excluded($iframe[1], perfmatters_lazyload_excluded_atts())) {
					continue;
				}

				//skip if no-lazy class is found
				if(!empty($iframe_atts['class']) && strpos($iframe_atts['class'], 'no-lazy') !== false) {
					continue;
				}
			}

			//skip if no src is found
			if(empty($iframe_atts['src'])) {
				continue;
			}

			$iframe['src'] = trim($iframe_atts['src']);

			//try rendering youtube preview placeholder if we need to
			if(!empty($perfmatters_options['lazyload']['youtube_preview_thumbnails'])) {
				$iframe_lazyload = perfmatters_lazy_load_youtube_iframe($iframe);
			}
					
			//default iframe placeholder
			if(empty($iframe_lazyload)) {

				$iframe_atts['class'] = !empty($iframe_atts['class']) ? $iframe_atts['class'] . ' ' . 'perfmatters-lazy' : 'perfmatters-lazy';

				//migrate src
				$iframe_atts['data-src'] = $iframe_atts['src'];
				unset($iframe_atts['src']);

				//build lazy iframe attributes string
				$lazy_iframe_atts_string = perfmatters_lazyload_get_atts_string($iframe_atts);

				//replace iframe attributes string
				$iframe_lazyload = str_replace($iframe[1], ' ' . $lazy_iframe_atts_string, $iframe[0]);
				
				//add noscript original iframe
				$iframe_lazyload.= '<noscript>' . $iframe[0] . '</noscript>';
			}

			//replace iframe with placeholder
			$html = str_replace($iframe[0], $iframe_lazyload, $html);

			unset($iframe_lazyload);
		}
	}

	return $html;
}

//prep youtube iframe for lazy loading
function perfmatters_lazy_load_youtube_iframe($iframe) {

	if(!$iframe) {
		return false;
	}

	//attempt to get the id based on url
	$result = preg_match('#^(?:https?:)?(?://)?(?:www\.)?(?:youtu\.be|youtube\.com|youtube-nocookie\.com)/(?:embed/|v/|watch/?\?v=)?([\w-]{11})#iU', $iframe['src'], $matches);

	//return false if there is no usable id
	if(!$result || $matches[1] === 'videoseries') {
		return false;
	}

	$youtube_id = $matches[1];

	//parse iframe src url
	$query = wp_parse_url(htmlspecialchars_decode($iframe['src']), PHP_URL_QUERY);

	//clean up the url
	$parsed_url = wp_parse_url($iframe['src'], -1);
	$scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '//';
	$host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
	$path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
	$youtube_url = $scheme . $host . $path;

	//thumbnail resolutions
	$resolutions = array(
		'default'       => array(
			'width'  => 120,
			'height' => 90,
		),
		'mqdefault'     => array(
			'width'  => 320,
			'height' => 180,
		),
		'hqdefault'     => array(
			'width'  => 480,
			'height' => 360,
		),
		'sddefault'     => array(
			'width'  => 640,
			'height' => 480,
		),
		'maxresdefault' => array(
			'width'  => 1280,
			'height' => 720,
		)
	);

	//filter set resolution
	$resolution = apply_filters('perfmatters_lazyload_youtube_thumbnail_resolution', 'hqdefault');

	//finished youtube lazy output
	$youtube_lazyload = '<div class="perfmatters-lazy-youtube" data-src="' . esc_attr($youtube_url) . '" data-id="' . esc_attr($youtube_id) . '" data-query="' . esc_attr($query) . '" onclick="perfmattersLazyLoadYouTube(this);">';
		$youtube_lazyload.= '<div>';
			$youtube_lazyload.= '<img class="perfmatters-lazy" src="data:image/svg+xml,%3Csvg%20xmlns=\'http://www.w3.org/2000/svg\'%20viewBox=\'0%200%20' . $resolutions[$resolution]['width'] . '%20' . $resolutions[$resolution]['height'] . '%3E%3C/svg%3E" data-src="https://i.ytimg.com/vi/' . esc_attr($youtube_id) .'/' . $resolution . '.jpg" alt="YouTube ' . __('video', 'perfmatters') . '" width="' . $resolutions[$resolution]['width'] . '" height="' . $resolutions[$resolution]['height'] . '" data-pin-nopin="true">';
			$youtube_lazyload.= '<div class="play"></div>';
		$youtube_lazyload.= '</div>';
	$youtube_lazyload.= '</div>';
	$youtube_lazyload.= '<noscript>' . $iframe[0] . '</noscript>';

	return $youtube_lazyload;
}

function perfmatters_lazy_load_css() {
	$output = '.perfmatters-lazy-youtube{position:relative;width:100%;max-width:100%;height:0;padding-bottom:56.23%;overflow:hidden}.perfmatters-lazy-youtube img{position:absolute;top:0;right:0;bottom:0;left:0;display:block;width:100%;max-width:100%;height:auto;margin:auto;border:none;cursor:pointer;transition:.5s all;-webkit-transition:.5s all;-moz-transition:.5s all}.perfmatters-lazy-youtube img:hover{-webkit-filter:brightness(75%)}.perfmatters-lazy-youtube .play{position:absolute;top:50%;left:50%;width:68px;height:48px;margin-left:-34px;margin-top:-24px;background:url('.plugins_url('perfmatters/img/youtube.svg').') no-repeat;background-position:center;background-size:cover;pointer-events:none}.perfmatters-lazy-youtube iframe{position:absolute;top:0;left:0;width:100%;height:100%;z-index:99}';
	if(current_theme_supports('responsive-embeds')) {
		$output.= '.wp-has-aspect-ratio .wp-block-embed__wrapper{position:relative;}.wp-has-aspect-ratio .perfmatters-lazy-youtube{position:absolute;top:0;right:0;bottom:0;left:0;width:100%;height:100%;padding-bottom:0}';
	}

	return $output;
}

//lazy load videos
function perfmatters_lazy_load_videos($html, $buffer) {

	//match all videos
	preg_match_all('#<video(\s.+)>.*</video>#iUs', $buffer, $videos, PREG_SET_ORDER);

	if(!empty($videos)) {

		//get plugin options
		$perfmatters_options = get_option('perfmatters_options');

		//remove any duplicates
		$videos = array_unique($videos, SORT_REGULAR);

		foreach($videos as $video) {

			//get video attributes array
			$video_atts = perfmatters_lazyload_get_atts_array($video[1]);

			//dont check excluded if forced attribute was found
			if(!perfmatters_lazyload_excluded($video[1], perfmatters_lazyload_forced_atts())) {

				//skip if exluded attribute was found
				if(perfmatters_lazyload_excluded($video[1], perfmatters_lazyload_excluded_atts())) {
					continue;
				}

				//skip if no-lazy class is found
				if(!empty($video_atts['class']) && strpos($video_atts['class'], 'no-lazy') !== false) {
					continue;
				}
			}

			//skip if no src is found
			if(empty($video_atts['src'])) {
				continue;
			}

			//add lazyload class
			$video_atts['class'] = !empty($video_atts['class']) ? $video_atts['class'] . ' ' . 'perfmatters-lazy' : 'perfmatters-lazy';

			//migrate src
			$video_atts['data-src'] = $video_atts['src'];
			unset($video_atts['src']);

			//build lazy video attributes string
			$lazy_video_atts_string = perfmatters_lazyload_get_atts_string($video_atts);

			//replace video attributes string
			$video_lazyload  = str_replace($video[1], ' ' . $lazy_video_atts_string, $video[0]);

			//add noscript original video
			$video_lazyload .= '<noscript>' . $video[0] . '</noscript>';

			//replace video with placeholder
			$html = str_replace($video[0], $video_lazyload, $html);

			unset($video_lazyload);
		}
	}

	return $html;
}

function perfmatters_lazyload_get_atts_array($atts_string) {
	
	if(!empty($atts_string)) {
		$atts_array = array_map(
			function(array $attribute) {
				return $attribute['value'];
			},
			wp_kses_hair($atts_string, wp_allowed_protocols())
		);

		return $atts_array;
	}

	return false;
}

function perfmatters_lazyload_get_atts_string($atts_array) {

	if(!empty($atts_array)) {
		$assigned_atts_array = array_map(
		function($name, $value) {
				if($value === '') {
					return $name;
				}
				return sprintf('%s="%s"', $name, esc_attr($value));
			},
			array_keys($atts_array),
			$atts_array
		);
		$atts_string = implode(' ', $assigned_atts_array);

		return $atts_string;
	}

	return false;
}

//get forced attributes
function perfmatters_lazyload_forced_atts() {
	return apply_filters('perfmatters_lazyload_forced_attributes', array());
}

//get excluded attributes
function perfmatters_lazyload_excluded_atts() {

	//base exclusions
	$attributes = array(
		'gform_ajax_frame'
	); 

	//get exclusions added from settings
	$options = get_option('perfmatters_options');
	if(!empty($options['lazyload']['lazy_loading_exclusions']) && is_array($options['lazyload']['lazy_loading_exclusions'])) {
		$attributes = array_unique(array_merge($attributes, $options['lazyload']['lazy_loading_exclusions']));
	}

    return apply_filters('perfmatters_lazyload_excluded_attributes', $attributes);
}

//check for excluded attributes in attributes string
function perfmatters_lazyload_excluded($string, $excluded) {
    if(!is_array($excluded)) {
        (array) $excluded;
    }

    if(empty($excluded)) {
        return false;
    }

    foreach($excluded as $exclude) {
        if(strpos($string, $exclude) !== false) {
            return true;
        }
    }

    return false;
}

//initialize lazy load instance
function perfmatters_print_lazy_load_js() {
	global $perfmatters_options;

	$threshold = apply_filters('perfmatters_lazyload_threshold', (!empty($perfmatters_options['lazyload']['threshold']) ? $perfmatters_options['lazyload']['threshold'] : '0px'));

	$output = '<script>';

		$output.= 'document.addEventListener("DOMContentLoaded",function(){';

			//initialize lazy loader
			$output.= 'var lazyLoadInstance=new LazyLoad({elements_selector:"img[data-src],.perfmatters-lazy",thresholds:"' . $threshold . ' 0px",callback_loaded:function(element){if(element.tagName==="IFRAME"){if(element.classList.contains("loaded")){if(typeof window.jQuery!="undefined"){if(jQuery.fn.fitVids){jQuery(element).parent().fitVids()}}}}}});';

			//dom monitoring
			if(!empty($perfmatters_options['lazyload']['lazy_loading_dom_monitoring'])) { 
				$output.= 'var target=document.querySelector("body");var observer=new MutationObserver(function(mutations){lazyLoadInstance.update()});var config={childList:!0,subtree:!0};observer.observe(target,config);';
			}

		$output.= '});';

		//youtube thumbnails
		if(!empty($perfmatters_options['lazyload']['lazy_loading_iframes']) && !empty($perfmatters_options['lazyload']['youtube_preview_thumbnails'])) {
			$output.= 'function perfmattersLazyLoadYouTube(e){var iframe=document.createElement("iframe");var params="ID?autoplay=1";params+=0===e.dataset.query.length?"":"&"+e.dataset.query;iframe.setAttribute("src",params.replace("ID",e.dataset.src));iframe.setAttribute("frameborder","0");iframe.setAttribute("allowfullscreen","1");iframe.setAttribute("allow","accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture");e.replaceChild(iframe,e.firstChild)}';
		}
	$output.= '</script>';

	echo $output;
}

//print lazy load styles
function perfmatters_print_lazy_load_css() {
?>
	<noscript><style>.perfmatters-lazy[data-src]{display:none !important;}</style></noscript>
<?php
}