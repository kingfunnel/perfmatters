<?php
namespace Perfmatters;

class Buffer
{
    //initialize buffer
    public static function init()
    {
        //initialize classes that filter the buffer
        Fonts::init();
        CDN::init();
        Images::init();

        //add main buffer action
        add_action('template_redirect', array('Perfmatters\Buffer', 'start'));
    }

    //start buffer
    public static function start()
    {
        //only run if filters have been added
        if(has_filter('perfmatters_output_buffer')) {
            ob_start(array('Perfmatters\Buffer', 'process'));
        }
    }

    //process buffer
    private static function process($html)
    {
        //exclude certain requests
        if(is_admin() || perfmatters_is_dynamic_request() || perfmatters_is_page_builder() || is_embed() || is_feed() || is_preview() || is_customize_preview() || isset($_GET['perfmatters'])) {
            return $html;
        }

        //run buffer filters
        $html = (string) apply_filters('perfmatters_output_buffer', $html);

        //return processed html
        return $html;
    }
}