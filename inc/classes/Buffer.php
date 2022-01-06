<?php
namespace Perfmatters;

class Buffer
{
    private static $valid_buffer = true;

    //initialize buffer
    public static function init()
    {
        //inital checks
        if(is_admin() || perfmatters_is_dynamic_request() || perfmatters_is_page_builder() || is_customize_preview() || isset($_GET['perfmatters'])) {
            return;
        }

        //initialize classes that filter the buffer
        Fonts::init();
        CDN::init();
        Images::init();
        Preload::init();

        //add buffer actions
        add_action('init', array('Perfmatters\Buffer', 'start'), 0);
        add_action('template_redirect', array('Perfmatters\Buffer', 'start'));
    }

    //start buffer
    public static function start()
    {
        $current_filter = current_filter();

        if(self::$valid_buffer && !empty($current_filter) && has_filter('perfmatters_output_buffer_' . $current_filter)) {

            //exclude certain requests
            if(is_embed() || is_feed() || is_preview()) {
                self::$valid_buffer = false;
                return;
            }

            ob_start(function($html) use ($current_filter) {

                if($current_filter == 'init' && !self::is_valid_buffer($html)) {
                    self::$valid_buffer = false;
                    return $html;
                }

                //run buffer filters
                $html = (string) apply_filters('perfmatters_output_buffer_' . $current_filter, $html);

                //return processed html
                return $html;
            });
        }
    }

    //make sure buffer content is valid
    private static function is_valid_buffer($html)
    {
        //check for valid/invalid tags
        if(stripos($html, '<html') === false || stripos($html, '</body>') === false || stripos($html, '<xsl:stylesheet') !== false) {
            return false;
        }

        //check for doctype
        if(!preg_match('/^<!DOCTYPE.+html/i', ltrim($html))) {
          return false;
        }

        //check for invalid urls
        $current_url = home_url($_SERVER['REQUEST_URI']);
        $matches = array('.xml', '.txt', '.php');
        foreach($matches as $match) {
            if(stripos($current_url, $match) !== false) {
                return false;
            }
        }

        return true;
    }
}