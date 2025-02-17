<?php

/* Attach native mail function */
add_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10, 5 );

class wpMandrill {
    const DEBUG = false;
    static $settings;
    static $report;
    static $stats;
    static $mandrill;
    static $conflict;
    static $error;

    static function on_load() {

        define('WPMANDRILL_API_VERSION', '1.0');

        add_action('admin_init', array(__CLASS__, 'adminInit'));
        add_action('admin_menu', array(__CLASS__, 'adminMenu'));

        add_action('admin_print_footer_scripts', array(__CLASS__,'openContextualHelp'));
        add_action('wp_ajax_get_mandrill_stats', array(__CLASS__,'getAjaxStats'));
        add_action('wp_ajax_get_dashboard_widget_stats', array(__CLASS__,'showDashboardWidget'));

        add_action('admin_post_sewm_fetch_new', array(__CLASS__, 'fetchNewDashboardData') );

        load_plugin_textdomain('wpmandrill', false, dirname( plugin_basename( __FILE__ ) ).'/lang');

        if( function_exists('wp_mail') ) {
            self::$conflict = true;
            return;
        }

        self::$conflict = false;
        if( self::isConfigured() ) {

            function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
                try {
                    $response = wpMandrill::mail( $to, $subject, $message, $headers, $attachments );
                    return wpMandrill::evaluate_response( $response );
                } catch ( Exception $e ) {
                    error_log( 'Mandrill error: ' . $e->getMessage() );
                    do_action( 'wp_mail_native', $to, $subject, $message, $headers, $attachments );
                }
            }
        }

    }

    /**
     * Evaluate response from Mandrill API.
     *
     * @param WP_Error|array
     */
    static function evaluate_response( $response ) {
        if ( is_wp_error( $response ) )
            throw new Exception( $response->get_error_message() );

        if ( !isset( $response[0]['status'] ) )
            throw new Exception( 'Email status was not provided in response.' );

        if (
            'rejected' === $response[0]['status']
            && isset( $response[0]['reject_reason'] )
            && 'hard-bounce' !== $response[0]['reject_reason'] # Exclude hard bounces (email address doesn't exist).
        )
            throw new Exception( 'Email was rejected due to the following reason: ' . $response[0]['reject_reason'] . '.' );

        if ( !in_array( $response[0]['status'], array( 'sent', 'queued' ) ) )
            throw new Exception( 'Email was not sent or queued. Response: ' . json_encode( $response ) );

        return true;
    }

    /**
     * Sets up options page and sections.
     */
    static function adminInit() {

        add_filter('plugin_action_links',array(__CLASS__,'showPluginActionLinks'), 10,5);
        add_action('admin_enqueue_scripts', array(__CLASS__,'showAdminEnqueueScripts'));

        register_setting('wpmandrill', 'wpmandrill', array(__CLASS__,'formValidate'));

        add_action( 'network_admin_notices', array( __CLASS__, 'network_connect_notice' ) );

        // SMTP Settings
        add_settings_section('wpmandrill-api', __('API Settings', 'wpmandrill'), '__return_false', 'wpmandrill');
        add_settings_field('api-key', __('API Key', 'wpmandrill'), array(__CLASS__, 'askAPIKey'), 'wpmandrill', 'wpmandrill-api');

        if( self::getAPIKey() ) {
            if (current_user_can('manage_options')) add_action('wp_dashboard_setup', array( __CLASS__,'addDashboardWidgets') );

            // Verified Addresses
            add_settings_section('wpmandrill-addresses', __('Sender Settings', 'wpmandrill'), '__return_false', 'wpmandrill');
            add_settings_field('from-name', __('FROM Name', 'wpmandrill'), array(__CLASS__, 'askFromName'), 'wpmandrill', 'wpmandrill-addresses');
            add_settings_field('from-email', __('FROM Email', 'wpmandrill'), array(__CLASS__, 'askFromEmail'), 'wpmandrill', 'wpmandrill-addresses');
            add_settings_field('reply-to', __('Reply-To Email', 'wpmandrill'), array(__CLASS__, 'askReplyTo'), 'wpmandrill', 'wpmandrill-addresses');
            add_settings_field('subaccount', __('Sub Account', 'wpmandrill'), array(__CLASS__, 'askSubAccount'), 'wpmandrill', 'wpmandrill-addresses');

            // Tracking
            add_settings_section('wpmandrill-tracking', __('Tracking', 'wpmandrill'), '__return_false', 'wpmandrill');
            add_settings_field('trackopens', __('Track opens', 'wpmandrill'), array(__CLASS__, 'askTrackOpens'), 'wpmandrill', 'wpmandrill-tracking');
            add_settings_field('trackclicks', __('Track clicks', 'wpmandrill'), array(__CLASS__, 'askTrackClicks'), 'wpmandrill', 'wpmandrill-tracking');

            // General Design
            add_settings_section('wpmandrill-templates', __('General Design', 'wpmandrill'), '__return_false', 'wpmandrill');
            add_settings_field('template', __('Template', 'wpmandrill'), array(__CLASS__, 'askTemplate'), 'wpmandrill', 'wpmandrill-templates');
            add_settings_field('nl2br', __('Content', 'wpmandrill'), array(__CLASS__, 'asknl2br'), 'wpmandrill', 'wpmandrill-templates');

            if( self::isWooCommerceActive() )
                add_settings_field('nl2br-woocommerce', __('WooCommerce Fix', 'wpmandrill'), array(__CLASS__, 'asknl2brWooCommerce'), 'wpmandrill', 'wpmandrill-templates');

            // Tags
            add_settings_section('wpmandrill-tags', __('General Tags', 'wpmandrill'), '__return_false', 'wpmandrill');
            add_settings_field('tags', __('Tags', 'wpmandrill'), array(__CLASS__, 'askTags'), 'wpmandrill', 'wpmandrill-tags');

            if ( self::isConfigured() ) {
                // Email Test
                register_setting('wpmandrill-test', 'wpmandrill-test', array(__CLASS__, 'sendTestEmail'));

                add_settings_section('mandrill-email-test', __('Send a test email using these settings', 'wpmandrill'), '__return_false', 'wpmandrill-test');
                add_settings_field('email-to', __('Send to', 'wpmandrill'), array(__CLASS__, 'askTestEmailTo'), 'wpmandrill-test', 'mandrill-email-test');
                add_settings_field('email-subject', __('Subject', 'wpmandrill'), array(__CLASS__, 'askTestEmailSubject'), 'wpmandrill-test', 'mandrill-email-test');
                add_settings_field('email-message', __('Message', 'wpmandrill'), array(__CLASS__, 'askTestEmailMessage'), 'wpmandrill-test', 'mandrill-email-test');
            }

            // Misc. Plugin Settings
            add_settings_section('wpmandrill-misc', __('Miscellaneous', 'wpmandrill'), function(){ echo "<span class='settings_sub_header'>Settings for WordPress plugin. Does not affect email delivery functionality or design.</span>"; }, 'wpmandrill');
            add_settings_field('hide_dashboard_widget', __('Hide WP Dashboard Widget', 'wpmandrill'), array(__CLASS__, 'hideDashboardWidget'), 'wpmandrill', 'wpmandrill-misc');
        }

        // Fix for WooCommerce
        if( self::getnl2brWooCommerce() ) {
            add_action( 'woocommerce_email', function() {
                add_filter( 'mandrill_nl2br', '__return_false' );
            }, 10, 1 );
        }

        // Activate the cron job that will update the stats
        add_action('wpm_update_stats', array(__CLASS__,'saveProcessedStats'));

        if ( !wp_next_scheduled( 'wpm_update_stats' ) ) {
            wp_schedule_event( current_time( 'timestamp', 1 ), 'hourly', 'wpm_update_stats');
        }

        register_deactivation_hook( __FILE__, array(__CLASS__,'deactivate') );
    }

    static public function deactivate() {
        wp_clear_scheduled_hook('wpm_update_stats');
    }

    /**
     * Creates option page's entry in Settings section of menu.
     */
    static function adminMenu() {

        self::$settings = add_options_page(
            __('Mandrill Settings', 'wpmandrill'),
            __('Mandrill', 'wpmandrill'),
            'manage_options',
            'wpmandrill',
            array(__CLASS__,'showOptionsPage')
        );
        add_action( 'load-'.self::$settings, array(__CLASS__,'showContextualHelp'));
	    
        if( self::isConfigured() && apply_filters( 'wpmandrill_enable_reports', true ) ) {
            if (current_user_can('manage_options')) self::$report = add_dashboard_page(
                __('Mandrill Reports', 'wpmandrill'),
                __('Mandrill Reports', 'wpmandrill'),
                'manage_options',
                'wpmandrill'.'-reports',
                array(__CLASS__,'showReportPage')
            );
            if ( self::isPluginPage('-reports') ) {
                wp_register_script('flot', SEWM_URL . 'js/flot/jquery.flot.js', array('jquery'), SEWM_VERSION, true);
                wp_register_script('flotstack', SEWM_URL . 'js/flot/jquery.flot.stack.js', array('flot'), SEWM_VERSION, true);
                wp_register_script('flotresize', SEWM_URL . 'js/flot/jquery.flot.resize.js', array('flot'), SEWM_VERSION, true);
                wp_enqueue_script('flot');
                wp_enqueue_script('flotstack');
                wp_enqueue_script('flotresize');
                wp_enqueue_script('mandrill-report-export');
            }
        }

        wp_register_style( 'mandrill_stylesheet', SEWM_URL . 'css/mandrill.css', array(), SEWM_VERSION );
        wp_enqueue_style( 'mandrill_stylesheet' );
        wp_register_script('mandrill', SEWM_URL . 'js/mandrill.js', array(), SEWM_VERSION, true);
        wp_enqueue_script('mandrill');
    }

    static function network_connect_notice() {
        $shown = get_site_option('wpmandrill_notice_shown');
        if ( empty($shown) ) {
            ?>
            <div id="message" class="updated wpmandrill-message">
                <div class="squeezer">
                    <h4><?php _e( '<strong>wpMandrill is activated!</strong> Each site on your network must be connected individually by an admin on that site.', 'wpmandrill' ) ?></h4>
                </div>
            </div>
            <?php
            update_site_option('wpmandrill_notice_shown',true);
        }
    }

    static function adminNotices() {
        if ( self::$conflict ) {
            echo '<div class="error"><p>'.__('Mandrill: wp_mail has been declared by another process or plugin, so you won\'t be able to use Mandrill until the problem is solved.', 'wpmandrill') . '</p></div>';
        }
    }

    static function showAdminEnqueueScripts($hook_suffix) {
        if( $hook_suffix == self::$report && self::isConnected() ) {
            wp_register_script('mandrill-report-script', SEWM_URL . "js/mandrill.js", array('flot'), null, true);
            wp_enqueue_script('mandrill-report-script');
        }
    }

    /**
     * Generates source of contextual help panel.
     */
    static function showContextualHelp() {
        $screen = get_current_screen();
        self::getConnected();

        $ok = array();
        $ok['account'] = ( !self::isConnected() )   ? ' class="missing"' : '';
        $ok['email']   = ( $ok['account'] != '' || !self::getFromEmail() )        ? ' class="missing"' : '';

        $requirements  = '';
        if ($ok['account'] . $ok['email'] != '' ) {
            $requirements = '<p>' . __('To use this plugin you will need:', 'wpmandrill') . '</p>'
                . '<ol>'
                . '<li'.$ok['account'].'>'. __('Your Mandrill account.', 'wpmandrill') . '</li>'
                . '<li'.$ok['email'].'>' . __('A valid sender email address.', 'wpmandrill') . '</li>'
                . '</ol>';
        }

        $requirements = $requirements
        . '<p>' . __('Once you have properly configured the settings, the plugin will take care of all the emails sent through your WordPress installation.', 'wpmandrill').'</p>'
        . '<p>' . __('However, if you need to customize any part of the email before sending, you can do so by using the WordPress filter <strong>mandrill_payload</strong>.', 'wpmandrill').'</p>'
        . '<p>' . __('This filter has the same structure as Mandrill\'s API call <a href="http://mandrillapp.com/api/docs/messages.html#method=send" target="_blank">/messages/send</a>, except that it can have one additional parameter when the email is based on a template. The parameter is called "<em>template</em>", which is an associative array of two elements (the first element, a string whose key is "<em>template_name</em>", and a second parameter whose key is "<em>template_content</em>". Its value is an array with the same structure of the parameter "<em>template_content</em>" in the call <a href="http://mandrillapp.com/api/docs/messages.html#method=send-template" target="_blank">/messages/send-template</a>.)', 'wpmandrill').'</p>'
        . '<p>' . __('Note that if you\'re sending additional headers in your emails, the only valid headers are <em>From:</em>, <em>Reply-To:</em>, and <em>X-*:</em>. <em>Bcc:</em> is also valid, but Mandrill will send the blind carbon copy to only the first address, and the remaining will be silently discarded.', 'wpmandrill').'</p>'
        . '<p>' . __('Also note that if any error occurs while sending the email, the plugin will try to send the message again using the native WordPress mailing capabilities.', 'wpmandrill').'</p>'
        . '<p>' . __('Confirm that any change you made to the payload is in line with the <a href="http://mandrillapp.com/api/docs/" target="_blank">Mandrill\'s API\'s documentation</a>. Also, the <em>X-*:</em> headers, must be in line with the <a href="http://help.mandrill.com/forums/20689696-smtp-integration" target="_blank">SMTP API documentation</a>. By using this plugin, you agree that you and your website will adhere to <a href="http://www.mandrill.com/terms/" target="_blank">Mandrill\'s Terms of Use</a> and <a href="http://mandrill.com/privacy/" target="_blank">Privacy Policy</a>.', 'wpmandrill').'</p>'
        . '<p>' . __('if you have any question about Mandrill or this plugin, visit the <a href="http://help.mandrill.com/" target="_blank">Mandrill\'s Support Center</a>.', 'wpmandrill').'</p>'
            ;
        
	    $screen->add_help_tab( array(
            'id'    => 'tab1',
            'title' => __('Setup'),
            'content'   => '<p>' . __( $requirements) . '</p>',
	    ) );
    }

    /**
     * Adds link to settings page in list of plugins
     */
    static function showPluginActionLinks($actions, $plugin_file) {

        // The code below no longer returns the current filename; @todo to update this to non-hard coded values
        //        static $plugin;
        //
        //        if (!isset($plugin))
        //            $plugin = plugin_basename(__FILE__);

        $plugin = 'send-emails-with-mandrill/wpmandrill.php';

        if ($plugin == $plugin_file) {
            $settings = array('settings' => '<a href="'.admin_url("/options-general.php?page=wpmandrill").'">' . __('Settings', 'wpmandrill') . '</a>');

            self::getConnected();
            if ( self::isConnected() ) {
                $report = array('report' => '<a href="'.self::getReportsDashboardURL().'">' . __('Reports', 'wpmandrill') . '</a>');
                $actions = array_merge($settings, $actions, $report);
            } else {
                $actions = array_merge($settings, $actions);
            }

        }

        return $actions;
    }

    /**
     * Generates source of options page.
     */
    static function showOptionsPage() {
        if (!current_user_can('manage_options'))
            wp_die( __('You do not have sufficient permissions to access this page.') );

        if ( isset($_GET['show']) && $_GET['show'] == 'how-tos' ) {
            self::showHowTos();
            return;
        }

        self::getConnected();

        ?>
        <div class="wrap">
            <div class="icon32" style="background: url('<?php echo SEWM_URL . 'images/mandrill-head-icon.png'; ?>');"><br /></div>
            <h2><?php _e('Mandrill Settings', 'wpmandrill'); ?> <small><a href="options-general.php?page=<?php echo 'wpmandrill'; ?>&show=how-tos">view how-tos</a></small></h2>

            <div style="float: left;width: 70%;">
                <form method="post" action="options.php">

                    <div class="stuffbox">
                        <?php settings_fields('wpmandrill'); ?>
                        <?php do_settings_sections('wpmandrill'); ?>
                    </div>

                    <p class="submit"><input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" /></p>
                </form>


                <?php if( self::isConnected() ) { ?>
                    <form method="post" action="options.php">
                        <div class="stuffbox" style="max-width: 90% !important;">
                            <?php settings_fields('wpmandrill-test'); ?>
                            <?php do_settings_sections('wpmandrill-test'); ?>
                        </div>

                        <p class="submit"><input type="submit" name="Submit" class="button-primary" value="<?php _e('Send Test', 'wpmandrill') ?>" /></p>
                    </form>
                <?php } ?>

            </div>
        </div>
        <?php
    }

    static function showHowTos() {
        self::getConnected();

        ?>
        <div class="wrap">
            <div class="icon32" style="background: url('<?php echo SEWM_URL . 'images/mandrill-head-icon.png'; ?>');"><br /></div>
            <h2><?php _e('Mandrill How-Tos', 'wpmandrill'); ?> <small><a href="options-general.php?page=<?php echo 'wpmandrill'; ?>">back to settings</a></small></h2>
            <?php
            require SEWM_PATH . '/how-tos.php';

            echo wpMandrill_HowTos::show('intro');
            echo wpMandrill_HowTos::show('auto');
            echo wpMandrill_HowTos::show('regular');
            echo wpMandrill_HowTos::show('filter');
            echo wpMandrill_HowTos::show('nl2br');
            echo wpMandrill_HowTos::show('direct');

            ?>
        </div>
        <?php
    }

    static function showReportPage() {
        require SEWM_PATH . '/stats.php';
    }

    static function getReportsDashboardURL($args=[]){
        $get_params = !empty($args) ? '&'.http_build_query( $args )  : '';
        return admin_url('/index.php?page=wpmandrill-reports'.$get_params);
    }

    static function fetchNewDashboardData() {
        wp_redirect(
                self::getReportsDashboardURL(
                        array(
                                'fetch_new' => 'asap'
                                )
                )
        );
    }

    /**
     * Processes submitted settings from.
     */
    static function formValidate($input) {
        self::getConnected();

        if ( empty($input['from_username']) ) {
            add_settings_error(
                'wpmandrill',
                'from-email',
                __('You must define a valid sender email.', 'wpmandrill'),
                'error'
            );


            $input['from_username'] = '';
        }

        // Preserving the Reply-To address
        $reply_to = $input['reply_to'];
        $response = array_map('wp_strip_all_tags', $input);
        $response['reply_to']   = $reply_to;

        return $response;
    }

    /**
     * Opens contextual help section.
     */
    static function openContextualHelp() {
        if ( !self::isPluginPage() || ( self::isConnected() && self::getFromEmail() )  )
            return;

        ?>
        <script type="text/javascript">
            jQuery(document).on( 'ready', function() {
                jQuery('a#contextual-help-link').trigger('click');
            });
        </script>
        <?php
    }

    /******************************************************************
     **  Helper functions
     *******************************************************************/

    /**
     * @return mixed
     */
    static function getOption( $name, $default = false ) {

        $options = get_option('wpmandrill');

        if( isset( $options[$name] ) )
            return $options[$name];

        return $default;
    }

    /**
     * @return boolean
     */
    static function isConnected() {
        return isset(self::$mandrill);
    }

    static function getConnected() {
        if ( !isset(self::$mandrill) ) {
            try {
                require_once( SEWM_PATH . '/lib/mandrill.class.php');
                self::$mandrill = new Mandrill( self::getAPIKey() );
            } catch ( Exception $e ) {}
        }
    }

    /**
     * @return boolean
     */
    static function isConfigured() {
        return self::getAPIKey() && self::getFromEmail();
    }

    /**
     * @return boolean
     */
    static function setOption( $name, $value ) {

        $options = get_option('wpmandrill');

        $options[$name] = $value;

        $result = update_option('wpmandrill', $options);

        return $result;
    }

    /**
     * @return string|boolean
     */
    static function getAPIKey() {
        if( defined('SEWM_API_KEY') ){
            return SEWM_API_KEY;
        }

        return self::getOption('api_key');
    }

    /**
     * @return string|boolean
     */
    static function getFromUsername() {

        return self::getOption('from_username');
    }

    /**
     * @return string|boolean
     */
    static function getFromEmail() {
        $from_email = self::getOption('from_username');
        if ( !empty($from_email) && !strpos($from_email, '@') ) {
            $from_email = self::getOption('from_username') . '@' . self::getOption('from_domain');
            self::setOption('from_username', $from_email);
            self::setOption('from_domain', null);
        }
        return $from_email;
    }

    /**
     * @return string|boolean
     */
    static function getFromName() {

        return self::getOption('from_name');
    }

    /**
     * @return string|boolean
     */
    static function getReplyTo() {

        return self::getOption('reply_to');
    }

    /**
     * @return string|boolean
     */
    static function getSubAccount() {

        return self::getOption('subaccount');
    }

    /**
     * @return string|boolean
     */
    static function getTemplate() {

        return self::getOption('template');
    }

    /**
     * @return string|boolean
     */
    static function getnl2br() {

        return self::getOption('nl2br');
    }

    /**
     * @return string|boolean
     */
    static function isWooCommerceActive() {
        // If WooCommerce is not active, we can ignore
        if ( !class_exists( 'WooCommerce' ) ) {
            return false;
        }

        return true;
    }

    /**
     * @return string|boolean
     */
    static function getnl2brWooCommerce() {

        if( !self::isWooCommerceActive() )
            return false;

        return self::getOption('nl2br_woocommerce');
    }

    /**
     * @return string|boolean
     */
    static function gethideDashboardWidget() {
        return self::getOption('hide_dashboard_widget');
    }

    /**
     * @return string|boolean
     */
    static function getTrackOpens() {

        return self::getOption('trackopens');
    }

    /**
     * @return string|boolean
     */
    static function getTrackClicks() {

        return self::getOption('trackclicks');
    }

    /**
     * @return string|boolean
     */
    static function getTags() {

        return self::getOption('tags');
    }

    /**
     * @param string $subject
     * @return array
     */
    static function findTags($tags) {

        // Getting general tags
        $gtags   = array();

        $general_tags = self::getTags();
        if ( !empty( $general_tags ) ) {
            $gtags   = explode("\n",$general_tags);
            foreach ( $gtags as $index => $gtag ) {
                if ( empty($gtag) ) unset($gtags[$index]);
            }
            $gtags = array_values($gtags);
        }

        // Finding tags based on WP Backtrace
        $trace  = debug_backtrace();
        $level  = 4;
        $function = $trace[$level]['function'];

        $wtags = array();
        if( 'include' == $function || 'require' == $function ) {

            $file = basename($trace[$level]['args'][0]);
            $wtags[] = "wp_{$file}";
        }
        else {
            if( isset( $trace[$level]['class'] ) )
                $function = $trace[$level]['class'].$trace[$level]['type'].$function;
            $wtags[] = "wp_{$function}";
        }

        return array('user' => $tags, 'general' => $gtags, 'automatic' => $wtags);
    }

    /**
     * @return boolean
     */
    static function isPluginPage($sufix = '') {

        return ( isset( $_GET['page'] ) && $_GET['page'] == 'wpmandrill' . $sufix);
    }

    /**
     * @return boolean
     */
    static function isTemplateValid($template) {
        self::getConnected();

        if ( empty($template) || !self::isConnected() ) return false;

        $templates = self::$mandrill->templates_list();
        foreach ( $templates as $curtemplate )  {
			if ( $curtemplate['name'] == $template || $curtemplate['slug'] == $template) {
                return true;
            }
        }

        return false;
    }

    /**
     * Processes submitted email test form.
     */
    static function sendTestEmail($input) {

        if (isset($input['email_to']) && !empty($input['email_to'])) {

            $to = $input['email_to'];
            $subject = isset($input['email_subject']) ? $input['email_subject'] : '';
            $message = isset($input['email_message']) ? $input['email_message'] : '';

            $test = self::mail($to, $subject, $message);

            if (is_wp_error($test)) {

                add_settings_error('email-to', 'email-to', __('Test failed. Please verify the following:
<ol>
<li>That your web server has either cURL installed or is able to use fsock*() functions (if you don\'t know what this means, you may want to check with your hosting provider for more details);</li>
<li>That your API key is active (this can be viewed on the <a href="https://mandrillapp.com/settings/index" target="_blank">SMTP & API Credentials</a> page in your Mandrill account);</li>
</ol>', 'wpmandrill') . $test->get_error_message());

                return array_map('wp_strip_all_tags', $input);

            } else {
                $result = array();

                $result['sent']         = 0;
                $result['queue']        = 0;
                $result['rejected']     = 0;

                foreach ( $test as $email ) {
                    if ( !isset($result[$email['status']]) ) $result[$email['status']] = 0;
                    $result[$email['status']]++;
                }


                add_settings_error('email-to', 'email-to', sprintf(__('Test executed: %d emails sent, %d emails queued and %d emails rejected', 'wpmandrill'), $result['sent'],$result['queue'],$result['rejected']), $result['sent'] ? 'updated' : 'error' );
            }
        }

        return array();
    }

    // Following methods generate parts of settings and test forms.
    static function askAPIKey() {
        echo '<div class="inside">';

        $api_key = self::getAPIKey();

        if( defined('SEWM_API_KEY') ) {
        ?>API Key globally defined.<?php
        } else {
        ?><input id='api_key' name='wpmandrill[api_key]' size='45' type='text' value="<?php esc_attr_e( $api_key ); ?>" /><?php
        }

        if ( empty($api_key) ) {
            ?><br/><span class="setting-description"><small><em><?php _e('To get your API key, please visit your <a href="http://mandrillapp.com/settings/index" target="_blank">Mandrill Settings</a>', 'wpmandrill'); ?></em></small></span><?php
        } else {
            $api_is_valid = false;

            self::getConnected();
            if ( self::isConnected() ) $api_is_valid = ( self::$mandrill->users_ping() == 'PONG!' );

            if ( !$api_is_valid ) {
                ?><br/><span class="setting-description"><small><em><?php _e('Sorry. Invalid API key.', 'wpmandrill'); ?></em></small></span><?php
            }
        }

        echo '</div>';
    }

    static function askFromEmail() {
        echo '<div class="inside">';

        $from_username  = self::getFromUsername();
        $from_email     = self::getFromEmail();

        ?><?php _e('This address will be used as the sender of the outgoing emails:', 'wpmandrill'); ?><br />
        <input id="from_username" name="wpmandrill[from_username]" type="text" value="<?php esc_attr_e($from_username);?>">
        <br/><?php

        echo '</div>';
    }

    static function askFromName() {
        echo '<div class="inside">';

        $from_name  = self::getFromName();

        ?><?php _e('Name the recipients will see in their email clients:', 'wpmandrill'); ?><br />
        <input id="from_name" name="wpmandrill[from_name]" type="text" value="<?php esc_attr_e($from_name); ?>">
        <?php

        echo '</div>';
    }

    static function askReplyTo() {
        echo '<div class="inside">';

        $reply_to     = self::getReplyTo();

        ?><?php _e('This address will be used as the recipient where replies from the users will be sent to:', 'wpmandrill'); ?><br />
        <input id="reply_to" name="wpmandrill[reply_to]" type="text" value="<?php esc_attr_e($reply_to);?>"><br/>
        <span class="setting-description"><br /><small><em><?php _e('Leave blank to use the FROM Email. If you want to override this setting, you must use the <em><a href="#" onclick="jQuery(\'a#contextual-help-link\').trigger(\'click\');return false;">mandrill_payload</a></em> WordPress filter.', 'wpmandrill'); ?></em></small></span><?php

        echo '</div>';
    }

    static function askSubAccount() {
        echo '<div class="inside">';

        $subaccount  = self::getSubAccount();

        ?><?php _e('Name of the sub account you wish to use (optional):', 'wpmandrill'); ?><br />
        <input id="subaccount" name="wpmandrill[subaccount]" type="text" value="<?php esc_attr_e($subaccount); ?>">
        <?php

        echo '</div>';
    }

    static function askTemplate() {
        echo '<div class="inside">';

        self::getConnected();

        if ( !self::isConnected() ) {
            _e('No templates found.', 'wpmandrill');

            echo '</div>';
            return;
        }

        $template  = self::getTemplate();
        $templates = self::$mandrill->templates_list();
        if( is_wp_error($templates) || empty($templates)) {

            _e('No templates found.', 'wpmandrill');

            if( $templates )
                self::setOption('templates', false);

            echo '</div>';
            return;
        }

        ?><?php _e('Select the template to use:', 'wpmandrill'); ?><br />
        <select id="template" name="wpmandrill[template]">
            <option value="">-None-</option><?php
            foreach( $templates as $curtemplate ) {
                ?><option value="<?php esc_attr_e($curtemplate['name']); ?>" <?php selected($curtemplate['name'], $template); ?>><?php esc_html_e($curtemplate['name']); ?></option><?php
            }
            ?></select><br/><span class="setting-description"><em><?php _e('<br /><small>The selected template must have a <strong><em>mc:edit="main"</em></strong> placeholder defined. The message will be shown there.</small>', 'wpmandrill'); ?></em></span><?php

        echo '</div>';
    }

    static function askTrackOpens() {
        $track  = self::getTrackOpens();
        if ( $track == '' ) $track = 0;
        ?>
        <div class="inside">
        <input id="trackopens" name="wpmandrill[trackopens]" type="checkbox" <?php echo checked($track,1); ?> value='1' /><br/>
        </div><?php
    }

    static function askTrackClicks() {
        $track  = self::getTrackClicks();
        if ( $track == '' ) $track = 0;
        ?>
        <div class="inside">
        <input id="trackclicks" name="wpmandrill[trackclicks]" type="checkbox" <?php echo checked($track,1); ?> value='1' /><br/>
        </div><?php
    }

    static function asknl2br() {
        $nl2br  = self::getnl2br();
        if ( $nl2br == '' ) $nl2br = 0;
        ?>
        <div class="inside">
        <?php _e('Replace all line feeds ("\n") by &lt;br/&gt; in the message body?', 'wpmandrill'); ?>
        <input id="nl2br" name="wpmandrill[nl2br]" type="checkbox" <?php echo checked($nl2br,1); ?> value='1' /><br/>
        <span class="setting-description">
	        	<em>
	        		<?php _e('<br /><small>If you are sending HTML emails already keep this setting deactivated.<br/>But if you are sending text only emails (WordPress default) this option might help your emails look better.</small>', 'wpmandrill'); ?><br/>
                    <?php _e('<small>You can change the value of this setting on the fly by using the <strong><a href="#" onclick="jQuery(\'a#contextual-help-link\').trigger(\'click\');return false;">mandrill_nl2br</a></strong> filter.</small>', 'wpmandrill'); ?>
	        	</em></span>
        </div><?php
    }

    static function asknl2brWooCommerce() {
        $nl2br_woocommerce  = self::getnl2brWooCommerce();
        if ( $nl2br_woocommerce == '' ) $nl2br_woocommerce = 0;
        ?>
        <div class="inside">
        <input id="nl2br_woocommere" name="wpmandrill[nl2br_woocommerce]" type="checkbox" <?php echo checked($nl2br_woocommerce,1); ?> value='1' /><br/>
        <span class="setting-description">
	        	<em>
	        		<?php _e('<br /><small>Check this if your WooCommerce emails are spaced incorrectly after enabling the <br/> setting above.</small>', 'wpmandrill'); ?>
	        	</em></span>
        </div><?php
    }

    static function askTags() {
        echo '<div class="inside">';

        $tags  = self::getTags();

        ?><?php _e('If there are tags that you want appended to every call, list them here, one per line:<br />', 'wpmandrill'); ?><br />
        <textarea id="tags" name="wpmandrill[tags]" cols="25" rows="3"><?php echo $tags; ?></textarea><br/>
        <span class="setting-description"><br /><small><em><?php _e('Also keep in mind that you can add or remove tags using the <em><a href="#" onclick="jQuery(\'a#contextual-help-link\').trigger(\'click\');return false;">mandrill_payload</a></em> WordPress filter.', 'wpmandrill'); ?></em></small></span>
        <?php

        echo '</div>';
    }

    static function hideDashboardWidget() {
        $hideDashboardWidget = self::getHideDashboardWidget();
        if ( $hideDashboardWidget == '' ) $hideDashboardWidget = 0;
        ?>
        <div class="inside">
        <input id="hide_dashboard_widget" name="wpmandrill[hide_dashboard_widget]" type="checkbox" <?php echo checked($hideDashboardWidget,1); ?> value='1' /><br/>
        <?php
    }

    static function askTestEmailTo() {
        echo '<div class="inside">';
        ?><input id='email_to' name='wpmandrill-test[email_to]' size='45' type='text' value="<?php esc_attr_e( self::getTestEmailOption('email_to') ); ?>"/><?php
        echo '</div>';
    }

    static function askTestEmailSubject() {
        echo '<div class="inside">';
        ?><input id='email_subject' name='wpmandrill-test[email_subject]' size='45' type='text' value="<?php esc_attr_e( self::getTestEmailOption('email_subject') ); ?>" /><?php
        echo '</div>';
    }

    static function askTestEmailMessage() {
        echo '<div class="inside">';
        ?><textarea rows="5" cols="45" name="wpmandrill-test[email_message]" ><?php esc_html_e( self::getTestEmailOption('email_message') ); ?></textarea><?php
        echo '</div>';
    }

    /**
     * @param  string $field
     * @return string|bool
     */
    static function getTestEmailOption($field) {

        $email = get_option('wpmandrill-test');

        if( isset( $email[$field] ) )
            return $email[$field];

        return false;
    }


    /******************************************************************
     **  Stats-related functions
     *******************************************************************/

    /**
     * @return array
     */
    static function getRawStatistics() {
        self::getConnected();
        if ( !self::isConnected() ) {
            error_log( date('Y-m-d H:i:s') . " wpMandrill::getRawStatistics: Not Connected to Mandrill \n" );
            return array();
        }

        $stats = array();
        $final = array();

        $stats['user']      = self::$mandrill->users_info();

        $data = array();
        $container          = self::$mandrill->tags_list();
        foreach ( $container as $tag ) {
            try {
                $data[$tag['tag']] = self::$mandrill->tags_info($tag['tag']);
                if ( count($data) >= 40 ) break;
            } catch ( Exception $e ) {}
        }
        $stats['tags']      = $data;

        $data = array();
        $container        = self::$mandrill->senders_list();
        foreach ( $container as $sender ) {
            try {
                $sender_info_data = self::$mandrill->senders_info($sender['address']);
                $data[$sender['address']] = $sender_info_data;
                if ( count($data) >= 20 ) break;
            } catch ( Exception $e ) {}
        }
        $stats['senders']   = $data;

        $final['general']   = $stats['user'];

        $final['stats']   = array();
        $final['stats']['hourly']['senders']   = array();;
        $final['stats']['hourly']['tags']      = array();

        $final['general']['stats'] = $final['general']['stats']['all_time'];
        $final['stats']['hourly']['tags']['general_stats'] = self::$mandrill->tags_all_time_series();

        foreach ( array('today', 'last_7_days','last_30_days','last_60_days','last_90_days') as $timeframe) {
            if ( isset($stats['user']['stats'][$timeframe]) ) $final['stats']['periods']['account'][$timeframe] = $stats['user']['stats'][$timeframe];

            foreach ($stats['tags'] as $index => $entity) {
                if ( !in_array($entity['tag'], $final['stats']['hourly']['tags']) )
                    $final['stats']['hourly']['tags']['detailed_stats'][$entity['tag']] = self::$mandrill->tags_time_series($entity['tag']);

                if ( isset($entity['stats'][$timeframe]) )
                    $final['stats']['periods']['tags'][$timeframe][$index] = $entity['stats'][$timeframe];

            }

            foreach ($stats['senders'] as $index => $entity) {
                if ( !in_array($entity['address'], $final['stats']['hourly']['senders']) )
                    $final['stats']['hourly']['senders'][$entity['address']] = self::$mandrill->senders_time_series($entity['address']);
                if ( isset($entity['stats'][$timeframe]) )
                    $final['stats']['periods']['senders'][$timeframe][$index] = $entity['stats'][$timeframe];
            }
        }

        return $final;
    }

    static function getProcessedStats() {
        $stats = self::getRawStatistics();
        if ( empty($stats) ) {
            error_log( date('Y-m-d H:i:s') . " wpMandrill::getProcessedStats (Empty Response from ::getRawStatistics)\n" );
            return $stats;
        }

        $graph_data = array();
        for ( $i = 0; $i < 24; $i++ ) {
            $graph_data['hourly']['delivered'][ sprintf('"%02s"',$i) ]          = 0;
            $graph_data['hourly']['opens'][ sprintf('"%02s"',$i) ]              = 0;
            $graph_data['hourly']['clicks'][ sprintf('"%02s"',$i) ]             = 0;

            $graph_data['hourly']['open_rate'][ sprintf('"%02s"',$i) ]          = 0;
            $graph_data['hourly']['click_rate'][ sprintf('"%02s"',$i) ]         = 0;
        }

        for ( $i = 29; $i >= 0; $i-- ) {
            $day = date('m/d', strtotime ( "-$i day" , time() ) );

            $graph_data['daily']['delivered'][ sprintf('"%02s"',$day) ]          = 0;
            $graph_data['daily']['opens'][ sprintf('"%02s"',$day) ]              = 0;
            $graph_data['daily']['clicks'][ sprintf('"%02s"',$day) ]             = 0;

            $graph_data['daily']['open_rate'][ sprintf('"%02s"',$day) ]          = 0;
            $graph_data['daily']['click_rate'][ sprintf('"%02s"',$day) ]         = 0;
        }

        $timeOffset = get_option('gmt_offset');
        $timeOffset = is_numeric($timeOffset) ? $timeOffset * 3600 : 0;

        foreach ( $stats['stats']['hourly']['senders'] as $data_by_sender ) {
            foreach ( $data_by_sender as $data ) {
                if ( isset($data['time']) ) {
                    $hour = '"' . date('H',strtotime($data['time'])+$timeOffset) . '"';
                    $day  = '"' . date('m/d', strtotime($data['time'])+$timeOffset) . '"';

                    if ( !isset($graph_data['hourly']['delivered'][$hour]) )    $graph_data['hourly']['delivered'][$hour]   = 0;
                    if ( !isset($graph_data['hourly']['opens'][$hour]) )        $graph_data['hourly']['opens'][$hour]       = 0;
                    if ( !isset($graph_data['hourly']['clicks'][$hour]) )       $graph_data['hourly']['clicks'][$hour]      = 0;

                    $graph_data['hourly']['delivered'][$hour] += $data['sent'] - $data['hard_bounces'] - $data['soft_bounces']  - $data['rejects'];
                    $graph_data['hourly']['opens'][$hour]     += $data['unique_opens'];
                    $graph_data['hourly']['clicks'][$hour]    += $data['unique_clicks'];

                    if ( isset($graph_data['daily']['delivered'][$day]) ) {
                        $graph_data['daily']['delivered'][$day] += $data['sent'] - $data['hard_bounces'] - $data['soft_bounces']  - $data['rejects'];
                        $graph_data['daily']['opens'][$day]     += $data['unique_opens'];
                        $graph_data['daily']['clicks'][$day]    += $data['unique_clicks'];
                    }
                }
            }
        }

        foreach (array_keys($graph_data['hourly']['delivered']) as $hour ) {

            if ($graph_data['hourly']['delivered'][$hour]) {
                if ( !isset($graph_data['hourly']['opens'][$hour]) ) $graph_data['hourly']['opens'][$hour] = 0;
                if ( !isset($graph_data['hourly']['clicks'][$hour]) ) $graph_data['hourly']['clicks'][$hour] = 0;

                $graph_data['hourly']['open_rate'][$hour]  = number_format($graph_data['hourly']['opens'][$hour] * 100 / $graph_data['hourly']['delivered'][$hour],2);
                $graph_data['hourly']['click_rate'][$hour] = number_format($graph_data['hourly']['clicks'][$hour] * 100 / $graph_data['hourly']['delivered'][$hour],2);
            }
        }

        foreach (array_keys($graph_data['daily']['delivered']) as $day ) {
            if ($graph_data['daily']['delivered'][$day]) {
                if ( !isset($graph_data['daily']['opens'][$day]) ) $graph_data['daily']['opens'][$day] = 0;
                if ( !isset($graph_data['daily']['clicks'][$day]) ) $graph_data['daily']['clicks'][$day] = 0;

                $graph_data['daily']['open_rate'][$day]  = number_format($graph_data['daily']['opens'][$day] * 100 / $graph_data['daily']['delivered'][$day],2);
                $graph_data['daily']['click_rate'][$day] = number_format($graph_data['daily']['clicks'][$day] * 100 / $graph_data['daily']['delivered'][$day],2);
            }

        }

        $stats['graph'] = $graph_data;

        return $stats;
    }

    /**
     * Try to get the current stats from cache. First, using a transient, if it has expired, it returns the latest
     * saved stats if found... and if there's none saved, it creates it directly from Mandrill.
     */
    static function getCurrentStats() {
        if( is_array($_GET) ){
            if( array_key_exists('fetch_new', $_GET) && $_GET['fetch_new']=='asap'){
                $stats = self::saveProcessedStats();
                return $stats;
            }
        }

        $stats = get_transient('wpmandrill-stats');
        if ( empty($stats) ) {
            error_log( date('Y-m-d H:i:s') . " wpMandrill::getCurrentStats (Empty Transient. Getting persistent copy)\n" );
            $stats = get_option('wpmandrill-stats');

            if ( empty($stats) )  {
                error_log( date('Y-m-d H:i:s') . " wpMandrill::getCurrentStats (Empty persistent copy. Getting data from Mandrill)\n" );
                $stats = self::saveProcessedStats();
            }
        }

        return $stats;
    }

    /**
     * Creates the stats directly from Mandrill and saves it in a transient and a backup persistent option.
     *
     * @return array The saved stats
     */
    static function saveProcessedStats() {
        $stats = self::GetProcessedStats();
        if ( !empty($stats) ) {
            set_transient('wpmandrill-stats', $stats, 60 * 60);
            update_option('wpmandrill-stats', $stats, false);
        } else {
            error_log( date('Y-m-d H:i:s') . " wpMandrill::saveProcessedStats (Empty Response from ::GetProcessedStats)\n" );
        }

        return $stats;
    }

    static function addDashboardWidgets() {
        if (!current_user_can('manage_options') || self::getOption('hide_dashboard_widget')) return;

        self::getConnected();
        if ( !self::isConnected() || !apply_filters( 'wpmandrill_enable_widgets', true ) ) return;

        $widget_id      = 'mandrill_widget';

        $widget_options = get_option('dashboard_widget_options');

        if ( !$widget_options || !isset($widget_options[$widget_id])) {
            $filter     = 'none';
        } else {
            $filter     = $widget_options[$widget_id]['filter'];
        }

        if ( $filter == 'none' ) {
            $filter_used = '';
        } elseif ( substr($filter,0,2) == 's:' ) {
            $filter = substr($filter,2);
            $filter_used = 'Sender: '.$filter;
        } else {
            $filter_used = 'Tag: '.$filter;
        }

        wp_add_dashboard_widget(    "mandrill_widget",
            __("Mandrill Recent Statistics", 'wpmandrill') . (!empty($filter_used) ? ' &raquo; ' . $filter_used : ''),
            array(__CLASS__, 'showDashboardWidget'),
            array(__CLASS__, 'showDashboardWidgetOptions')
        );

        global $wp_meta_boxes;

        $normal_dashboard = $wp_meta_boxes['dashboard']['normal']['core'];

        $backup = array('mandrill_widget' => $normal_dashboard['mandrill_widget']);
        unset($normal_dashboard['mandrill_widget']);

        $sorted_dashboard = array_merge($backup, $normal_dashboard);

        $wp_meta_boxes['dashboard']['normal']['core'] = $sorted_dashboard;
    }

    static function showDashboardWidget() {
        if ( !current_user_can('manage_options') ) return;

        self::getConnected();

        $isAjaxCall = isset($_POST['ajax']) && $_POST['ajax'] ? true : false;

        $widget_id      = 'mandrill_widget';
        $widget_options =      get_option('dashboard_widget_options');

        if ( !$widget_options || !isset($widget_options[$widget_id])) {
            $filter     = 'none';
            $display    = 'volume';
        } else {

            $filter     = $widget_options[$widget_id]['filter'];
            $display    = $widget_options[$widget_id]['display'];
        }

        try {
            $stats = array();
            if ( $filter == 'none' ) {
                $filter_type = 'account';

                $data      = self::$mandrill->users_info();
                $stats['stats']['periods']['account']['today'] = $data['stats']['today'];
                $stats['stats']['periods']['account']['last_7_days'] = $data['stats']['last_7_days'];

            } elseif ( substr($filter,0,2) == 's:' ) {
                $filter_type = 'senders';
                $filter = substr($filter,2);

                $data      = self::$mandrill->senders_info($filter);
                $stats['stats']['periods']['senders']['today'][$filter]         = $data['stats']['today'];
                $stats['stats']['periods']['senders']['last_7_days'][$filter]   = $data['stats']['last_7_days'];
            } else {
                $filter_type = 'tags';

                $data      = self::$mandrill->tags_info($filter);
                $stats['stats']['periods']['tags']['today'][$filter]            = $data['stats']['today'];
                $stats['stats']['periods']['tags']['last_7_days'][$filter]      = $data['stats']['last_7_days'];
            }
        } catch ( Exception $e ) {
            if ( $isAjaxCall ) exit();

            echo '<div style="height:400px;"><div id="filtered_recent">Error trying to read data from Mandrill: '.$e->getMessage().'</div></div>';
            return;
        }
        $data = array();
        foreach ( array('today', 'last_7_days') as $period ) {
            if ( $filter_type == 'account' ) {
                $data['sent'][$period]     = $stats['stats']['periods'][$filter_type][$period]['sent'];
                $data['opens'][$period]     = $stats['stats']['periods'][$filter_type][$period]['unique_opens'];
                $data['bounces'][$period]   = $stats['stats']['periods'][$filter_type][$period]['hard_bounces'] +
                    $stats['stats']['periods'][$filter_type][$period]['soft_bounces'] +
                    $stats['stats']['periods'][$filter_type][$period]['rejects'];

                $data['unopens'][$period]   = $stats['stats']['periods'][$filter_type][$period]['sent'] -
                    $data['opens'][$period] -
                    $data['bounces'][$period];
            } else {
                $data['sent'][$period]     = $stats['stats']['periods'][$filter_type][$period][$filter]['sent'];
                $data['opens'][$period]     = $stats['stats']['periods'][$filter_type][$period][$filter]['unique_opens'];
                $data['bounces'][$period]   = $stats['stats']['periods'][$filter_type][$period][$filter]['hard_bounces'] +
                    $stats['stats']['periods'][$filter_type][$period][$filter]['soft_bounces'] +
                    $stats['stats']['periods'][$filter_type][$period][$filter]['rejects'];

                $data['unopens'][$period]   = $stats['stats']['periods'][$filter_type][$period][$filter]['sent'] -
                    $data['opens'][$period] -
                    $data['bounces'][$period];
            }
        }

        $lit = array();

        $lit['title']          = __('Sending Volume', 'wpmandrill');
        $lit['label_suffix']   = __(' emails', 'wpmandrill');
        $lit['Ylabel']         = __('Total Volume per Day', 'wpmandrill');

        $lit['last_few_days']  = __('in the last few days', 'wpmandrill');
        $lit['last_few_months']= __('in the last few months', 'wpmandrill');
        $lit['today']          = __('Today', 'wpmandrill');
        $lit['last7days']      = __('Last 7 Days', 'wpmandrill');
        $lit['last30days']     = __('Last 30 Days', 'wpmandrill');
        $lit['last60days']     = __('Last 60 Days', 'wpmandrill');
        $lit['last90days']     = __('Last 90 Days', 'wpmandrill');
        $lit['periods']        = __('Periods', 'wpmandrill');
        $lit['volume']         = __('Volume', 'wpmandrill');
        $lit['total']          = __('Total:', 'wpmandrill');
        $lit['unopened']       = __('Unopened', 'wpmandrill');
        $lit['bounced']        = __('Bounced or Rejected', 'wpmandrill');
        $lit['opened']         = __('Opened', 'wpmandrill');

        $tickFormatter = 'emailFormatter';
        if ( $display == 'average' ) {
            $lit['title']            = __('Average Sending Volume', 'wpmandrill');
            $lit['label_suffix']    .= __('/day', 'wpmandrill');
            $lit['Ylabel']           = __('Average Volume per Day', 'wpmandrill');

            foreach ( array(1 => 'today', 7 => 'last_7_days') as $days => $period ) {
                $data['opens'][$period]     = number_format($data['opens'][$period] / $days,2);
                $data['bounces'][$period]   = number_format($data['bounces'][$period] / $days,2);
                $data['unopens'][$period]   = number_format($data['unopens'][$period] / $days,2);
            }
            $tickFormatter = 'percentageFormatter';
        }
        // Filling arrays for recent stats
        $unopens['recent']    = '[0,' . $data['unopens']['today']           . '],[1,' . $data['unopens']['last_7_days'] . ']';
        $opens['recent']      = '[0,' . $data['opens']['today']             . '],[1,' . $data['opens']['last_7_days']	. ']';
        $bounces['recent']    = '[0,' . $data['bounces']['today']           . '],[1,' . $data['bounces']['last_7_days']	. ']';


        $js = '';
        if ( !$isAjaxCall ) {
            $js .= '
            <script type="text/javascript" src="'.SEWM_URL . 'js/flot/jquery.flot.js"></script>
            <script type="text/javascript" src="'.SEWM_URL . 'js/flot/jquery.flot.stack.js"></script>
			<script type="text/javascript" src="'.SEWM_URL . 'js/flot/jquery.flot.resize.js"></script>';

            $js .= '
<div style="height:400px;">
    <div id="filtered_recent" style="height:400px;">Loading...</div>
</div>
<script type="text/javascript">
jQuery(document).on( \'ready\', function() {
';
        }
        $js .= <<<JS
	function emailFormatter(v, axis) {
	    return v.toFixed(axis.tickDecimals) +" emails";
	}
	function percentageFormatter(v, axis) {
	    return v.toFixed(axis.tickDecimals) +"%";
	}
	function wpm_showTooltip(x, y, contents) {
		jQuery('<div id="wpm_tooltip">' + contents + '</div>').css( {
	        position: 'absolute',
	        display: 'none',
	        top: y + 5,
	        left: x + 5,
	        border: '1px solid #fdd',
	        padding: '2px',
	        'background-color': '#fee',
	        opacity: 0.80
	    }).appendTo("body").fadeIn(200);
	}
	var previousPoint = null;
	jQuery("#filtered_recent").on("plothover", function (event, pos, item) {
        if (item) {
            if (previousPoint != item.dataIndex) {
                previousPoint = item.dataIndex;
                
                jQuery("#wpm_tooltip").remove();
                var x = item.datapoint[0].toFixed(0);	                

                if ( '{$tickFormatter}' == 'emailFormatter' ) {
                	var y = item.datapoint[1].toFixed(0);
                	wpm_showTooltip(item.pageX, item.pageY, item.series.label + " = " + y + " emails");
                } else {
                	var y = item.datapoint[1].toFixed(2);
                	wpm_showTooltip(item.pageX, item.pageY, item.series.label + " = " + y + "%");
                }
            }
        }
        else {
        	jQuery("#wpm_tooltip").remove();
            previousPoint = null;            
        }
	});

	jQuery(function () {
		var hbounces= [{$bounces['recent']}];
		var hopens 	= [{$opens['recent']}];
		var huopens = [{$unopens['recent']}];

		if ( ! jQuery("#mandrill_widget").is(":visible") ) {
			return;
		}

		jQuery.plot(jQuery("#filtered_recent"),
	           [ { data: hbounces, label: "{$lit['bounced']}" },
	             { data: hopens, label: "{$lit['opened']}" },
	             { data: huopens, label: "{$lit['unopened']}" }],
	           {
	        	   series: {
	        	   	   stack: false,
	        	   	   bars: {show: true, barWidth: 0.6, align: "center"},
	 	   			   points: { show: false },
					   lines: { show: false },
					   shadowSize: 4
	 	           },
	        	   grid: {
	 	        	  hoverable: true,
	 	        	  aboveData: true,
	 	        	  borderWidth: 0,
	 	        	  minBorderMargin: 10,
	 	        	  margin: {
	 	        		    top: 10,
	 	        		    left: 10,
	 	        		    bottom: 15,
	 	        		    right: 10
	 	        		}
	 	           },
	               xaxes: [ { ticks: [[0,"{$lit['today']}"],[1,"{$lit['last7days']}"]] } ],
	               yaxes: [ { min: 0, tickFormatter: {$tickFormatter} } ],
	               legend: { position: 'ne', margin: [20, 10]}
		});
    });
JS;

        if ( !$isAjaxCall ) {
            $js .= '
    });
</script>';
        }

        echo $js;

        if ( $isAjaxCall ) exit();

    }

    static function showDashboardWidgetOptions() {
        $stats = self::getCurrentStats();
        if ( empty($stats) ) {
            echo '<p>' . __('There was a problem retrieving statistics.', 'wpmandrill') . '</p>';
            return;
        }

        $widget_id = 'mandrill_widget';

        if ( function_exists('is_multisite') && is_multisite() )
            $widget_options = get_site_option('dashboard_widget_options');
        else
            $widget_options = get_option('dashboard_widget_options');

        if ( !$widget_options )
            $widget_options = array();

        if ( !isset($widget_options[$widget_id]) )
            $widget_options[$widget_id] = array();

        if ( 'POST' == $_SERVER['REQUEST_METHOD'] && isset($_POST) ) {
            $filter = $_POST['filter'];
            $display = $_POST['display'];

            $widget_options[$widget_id]['filter']     = $filter;
            $widget_options[$widget_id]['display']    = $display;
            update_option( 'dashboard_widget_options', $widget_options );
        }

        $filter = isset( $widget_options[$widget_id]['filter'] ) ? $widget_options[$widget_id]['filter'] : '';
        $display = isset( $widget_options[$widget_id]['display'] ) ? $widget_options[$widget_id]['display'] : '';
        ?>
        <label for="filter"><?php _e('Filter by:', 'wpmandrill'); ?> </label>
        <select id="filter" name="filter">
            <option value="none" <?php echo selected($filter, 'none');?>><?php _e('No filter', 'wpmandrill'); ?></option>
            <optgroup label="<?php _e('Sender:', 'wpmandrill'); ?>">
                <?php
                foreach ( array_keys($stats['stats']['hourly']['senders']) as $sender) {
                    echo '<option value="s:'.$sender.'" '.selected($filter, 's:'.$sender).'>'.$sender.'</option>';
                }
                ?>
            </optgroup>
            <optgroup label="<?php _e('Tag:', 'wpmandrill'); ?>">
                <?php
                foreach ( array_keys($stats['stats']['hourly']['tags']['detailed_stats']) as $tag) {
                    echo '<option value="'.$tag.'" '.selected($filter, $tag).'>'.$tag.'</option>';
                }
                ?>
            </optgroup>
        </select>
        <label for="display"><?php _e('Display:', 'wpmandrill'); ?> </label>
        <select id="display" name="display">
        <option value="volume" <?php echo selected($display, 'volume');?>><?php _e('Total Volume per Period', 'wpmandrill'); ?></option>
        <option value="average" <?php echo selected($display, 'average');?>><?php _e('Average Volume per Period', 'wpmandrill'); ?></option>
        </select><?php
    }

    static function getAjaxStats() {
        $stats = self::getCurrentStats();
        if ( empty($stats) ) {
            exit();
        }

        $filter         = $_POST['filter'];
        $display        = $_POST['display'];

        if ( $filter == 'none' ) {
            $filter_type = 'account';
        } elseif ( substr($filter,0,2) == 's:' ) {
            $filter_type = 'senders';
            $filter = substr($filter,2);
        } else {
            $filter_type = 'tags';
        }

        $data = array();
        foreach ( array('today', 'last_7_days', 'last_30_days', 'last_60_days', 'last_90_days') as $period ) {
            if ( $filter_type == 'account' ) {
                $data['opens'][$period]     = $stats['stats']['periods'][$filter_type][$period]['unique_opens'];
                $data['bounces'][$period]   = $stats['stats']['periods'][$filter_type][$period]['hard_bounces'] +
                    $stats['stats']['periods'][$filter_type][$period]['soft_bounces'] +
                    $stats['stats']['periods'][$filter_type][$period]['rejects'];

                $data['unopens'][$period]   = $stats['stats']['periods'][$filter_type][$period]['sent'] -
                    $data['opens'][$period] -
                    $data['bounces'][$period];
            } else {
                $data['opens'][$period]     = $stats['stats']['periods'][$filter_type][$period][$filter]['unique_opens'];
                $data['bounces'][$period]   = $stats['stats']['periods'][$filter_type][$period][$filter]['hard_bounces'] +
                    $stats['stats']['periods'][$filter_type][$period][$filter]['soft_bounces'] +
                    $stats['stats']['periods'][$filter_type][$period][$filter]['rejects'];

                $data['unopens'][$period]   = $stats['stats']['periods'][$filter_type][$period][$filter]['sent'] -
                    $data['opens'][$period] -
                    $data['bounces'][$period];
            }
        }

        $lit = array();

        $lit['title']          = __('Sending Volume', 'wpmandrill');
        $lit['label_suffix']   = __(' emails', 'wpmandrill');
        $lit['Ylabel']         = __('Total Volume per Day', 'wpmandrill');

        $lit['last_few_days']  = __('in the last few days', 'wpmandrill');
        $lit['last_few_months']= __('in the last few months', 'wpmandrill');
        $lit['today']          = __('Today', 'wpmandrill');
        $lit['last7days']      = __('Last 7 Days', 'wpmandrill');
        $lit['last30days']     = __('Last 30 Days', 'wpmandrill');
        $lit['last60days']     = __('Last 60 Days', 'wpmandrill');
        $lit['last90days']     = __('Last 90 Days', 'wpmandrill');
        $lit['periods']        = __('Periods', 'wpmandrill');
        $lit['volume']         = __('Volume', 'wpmandrill');
        $lit['total']          = __('Total:', 'wpmandrill');
        $lit['unopened']       = __('Unopened', 'wpmandrill');
        $lit['bounced']        = __('Bounced or Rejected', 'wpmandrill');
        $lit['opened']         = __('Opened', 'wpmandrill');

        $tickFormatter = 'emailFormatter';
        if ( $display == 'average' ) {
            $lit['title']            = __('Average Sending Volume', 'wpmandrill');
            $lit['label_suffix']    .= __('/day', 'wpmandrill');
            $lit['Ylabel']           = __('Average Volume per Day', 'wpmandrill');

            foreach ( array(1 => 'today', 7 => 'last_7_days', 30 => 'last_30_days', 60 => 'last_60_days', 90 => 'last_90_days') as $days => $period ) {
                $data['opens'][$period]     = number_format($data['opens'][$period] / $days,2);
                $data['bounces'][$period]   = number_format($data['bounces'][$period] / $days,2);
                $data['unopens'][$period]   = number_format($data['unopens'][$period] / $days,2);
            }
            $tickFormatter = 'percentageFormatter';
        }

        // Filling arrays for recent stats
        $unopens['recent']    = '[0,' .$data['unopens']['today']    . '],[1,' . $data['unopens']['last_7_days'] . ']';
        $opens['recent']      = '[0,' .$data['opens']['today']      . '],[1,' . $data['opens']['last_7_days']	. ']';
        $bounces['recent']    = '[0,' .$data['bounces']['today']    . '],[1,' . $data['bounces']['last_7_days']	. ']';

        // Filling arrays for older stats
        $unopens['oldest']    = '[0,' .$data['unopens']['last_30_days']    . '],[1,' . $data['unopens']['last_60_days']    . '],[2,' . $data['unopens']['last_90_days']	. ']';
        $opens['oldest']      = '[0,' .$data['opens']['last_30_days']      . '],[1,' . $data['opens']['last_60_days']      . '],[2,' . $data['opens']['last_90_days']	. ']';
        $bounces['oldest']    = '[0,' .$data['bounces']['last_30_days']    . '],[1,' . $data['bounces']['last_60_days']    . '],[2,' . $data['bounces']['last_90_days']	. ']';

        // Today and 7-days-old stats data

        $obounced = $bounces['oldest'];
        $oopened  = $opens['oldest'];
        $ounopened= $unopens['oldest'];

        $rbounced = $bounces['recent'];
        $ropened  = $opens['recent'];
        $runopened= $unopens['recent'];

        $js = <<<JS
var rbounced     = [{$rbounced}];
var ropened  = [{$ropened}];
var runopened = [{$runopened}]
		
var obounced     = [{$obounced}];
var oopened  = [{$oopened}];
var ounopened = [{$ounopened}]

jQuery(function () {
	var previousPoint = null;
	jQuery("#filtered_recent").on("plothover", function (event, pos, item) {
        if (item) {
            if (previousPoint != item.dataIndex) {
                previousPoint = item.dataIndex;
                
                jQuery("#wpm_tooltip").remove();
                var x = item.datapoint[0].toFixed(0);	                

                if ( '{$tickFormatter}' == 'emailFormatter' ) {
                	var y = item.datapoint[1].toFixed(0);
                	wpm_showTooltip(item.pageX, item.pageY, item.series.label + " = " + y + " emails");
                } else {
                	var y = item.datapoint[1].toFixed(2);
                	wpm_showTooltip(item.pageX, item.pageY, item.series.label + " = " + y + "%");
                }
            }
        }
        else {
        	jQuery("#wpm_tooltip").remove();
            previousPoint = null;            
        }
	});
	jQuery("#filtered_oldest").on("plothover", function (event, pos, item) {
        if (item) {
            if (previousPoint != item.dataIndex) {
                previousPoint = item.dataIndex;
                
                jQuery("#wpm_tooltip").remove();
                var x = dticks[item.dataIndex];
                	
                if ( '{$tickFormatter}' == 'emailFormatter' ) {
                	var y = item.datapoint[1].toFixed(0);
                	wpm_showTooltip(item.pageX, item.pageY, item.series.label + " = " + y + " emails");
                } else {
                	var y = item.datapoint[1].toFixed(2);
                	wpm_showTooltip(item.pageX, item.pageY, item.series.label + " = " + y + "%");
                }
            }
        }
        else {
        	jQuery("#wpm_tooltip").remove();
            previousPoint = null;            
        }
	});
	jQuery.plot(jQuery("#filtered_recent"),
	           [ { data: rbounced, label: "{$lit['bounced']}" },
	             { data: ropened, label: "{$lit['opened']}" },
	             { data: runopened, label: "{$lit['unopened']}" }],
	           {
	        	   series: {
	        	   	   stack: false,
	        	   	   bars: {show: true, barWidth: 0.6, align: "center"},
	 	   			   points: { show: false },
					   lines: { show: false },
					   shadowSize: 4
	 	           },
	        	   grid: {
	 	        	  hoverable: true,
	 	        	  aboveData: true,
	 	        	  borderWidth: 0,
	 	        	  minBorderMargin: 10,
	 	        	  margin: {
	 	        		    top: 10,
	 	        		    left: 10,
	 	        		    bottom: 15,
	 	        		    right: 10
	 	        		}
	 	           },
	               xaxes: [ { ticks: [[0,"{$lit['today']}"],[1,"{$lit['last7days']}"]] } ],
	               yaxes: [ { min: 0, tickFormatter: {$tickFormatter} } ],
	               legend: { position: 'ne', margin: [20, 10]}
	});
	jQuery.plot(jQuery("#filtered_oldest"),
	           [ { data: obounced, label: "{$lit['bounced']}" },
	             { data: oopened, label: "{$lit['opened']}" },
	             { data: ounopened, label: "{$lit['unopened']}" }],
	           {
	        	   series: {
	        	   	   stack: false,
	        	   	   bars: {show: true, barWidth: 0.6, align: "center"},
	 	   			   points: { show: true },
					   lines: { show: true },
					   shadowSize: 4
	 	           },
	        	   grid: {
	 	        	  hoverable: true,
	 	        	  aboveData: true,
	 	        	  borderWidth: 0,
	 	        	  minBorderMargin: 10,
	 	        	  margin: {
	 	        		    top: 10,
	 	        		    left: 10,
	 	        		    bottom: 15,
	 	        		    right: 10
	 	        		}
	 	           },
	               xaxes: [ { ticks: [[0,"{$lit['last30days']}"],[1,"{$lit['last60days']}"],[2,"{$lit['last90days']}"]] } ],
	               yaxes: [ { min: 0, tickFormatter: {$tickFormatter} }],
	               legend: { position: 'ne', margin: [20, 10]}
	});
});
JS;
        echo $js;

        exit();
    }


    /******************************************************************
     **  Function to actually send the emails
     *******************************************************************
    /**
     * @param string|array $to recipients, array or comma-separated string
     * @param string $subject email subject
     * @param string $html email body
     * @param string|array $headers array or comma-separated string with additional headers for the email
     * @param array $attachments Array of paths for the files to be attached to the message.
     * @param array $tags Array of tags to be attached to the message.
     * @param string $from_name the name to use in the From field of the message
     * @param string $from_email a valid email address to use with this account
     * @param string $template_name a valid template name for the account
     * @param boolean $track_opens whether or not to track opens for the email
     * @param boolean $track_clicks whether or not to track clicks for the email
     * @param boolean $url_strip_qs whether or not to strip the query string from URLs when aggregating tracked URL data
     * @param boolean $merge whether to evaluate merge tags in the message. Will automatically be set to true if either merge_vars or global_merge_vars are provided.
     * @param array $global_merge_vars Global merge variables to use for all recipients. You can override these per recipient.
     * @param array $merge_vars Per-recipient merge variables, which override global merge variables with the same name.
     * @param array $google_analytics_domains An array of strings indicating for which any matching URLs will automatically have Google Analytics parameters appended to their query string automatically.
     * @param array|string $google_analytics_campaign Optional string indicating the value to set for the utm_campaign tracking parameter. If this isn't provided the email's from address will be used instead.
     * @param array $metadata Associative array of user metadata. Mandrill will store this metadata and make it available for retrieval. In addition, you can select up to 10 metadata fields to index and make searchable using the Mandrill search api.
     * @param boolean $important Set the important flag to true for the current email
     * @param boolean $inline_css whether or not to automatically inline all CSS styles provided in the message HTML - only for HTML documents less than 256KB in size
     * @param boolean $preserve_recipients whether or not to expose all recipients in to "To" header for each email
     * @param boolean $view_content_link set to false to remove content logging for sensitive emails
     * @param string $tracking_domain a custom domain to use for tracking opens and clicks instead of mandrillapp.com
     * @param string $signing_domain a custom domain to use for SPF/DKIM signing instead of mandrill (for "via" or "on behalf of" in email clients)
     * @param string $return_path_domain a custom domain to use for the messages's return-path
     * @param string $subaccount the unique id of a subaccount for this message - must already exist or will fail with an error
     * @param array $recipient_metadata Per-recipient metadata that will override the global values specified in the metadata parameter.
     * @param string $ip_pool the name of the dedicated ip pool that should be used to send the message. If you do not have any dedicated IPs, this parameter has no effect. If you specify a pool that does not exist, your default pool will be used instead.
     * @param string $send_at when this message should be sent as a UTC timestamp in YYYY-MM-DD HH:MM:SS format. Read more about it at https://mandrillapp.com/api/docs/messages.JSON.html#method=send
     * @param boolean $async enable a background sending mode that is optimized for bulk sending. Read more about it at https://mandrillapp.com/api/docs/messages.JSON.html#method=send
     * @return array|WP_Error
     */
    static function mail( $to, $subject, $html, $headers = '', $attachments = array(),
                          $tags = array(),
                          $from_name = '',
                          $from_email = '',
                          $template_name = '',
                          $track_opens = null,
                          $track_clicks = null,
                          $url_strip_qs = false,
                          $merge = true,
                          $global_merge_vars = array(),
                          $merge_vars = array(),
                          $google_analytics_domains = array(),
                          $google_analytics_campaign = array(),
                          $metadata = array(),
                          $important = false,
                          $inline_css = null,
                          $preserve_recipients=null,
                          $view_content_link=null,
                          $tracking_domain=null,
                          $signing_domain=null,
                          $return_path_domain=null,
                          $subaccount=null,
                          $recipient_metadata=null,
                          $ip_pool=null,
                          $send_at=null,
                          $async=null
    ) {
        if ( $track_opens === null ) $track_opens = self::getTrackOpens();
        if ( $track_clicks === null ) $track_clicks = self::getTrackClicks();

        try {
            extract( apply_filters( 'wp_mail', compact( 'to', 'subject', 'html', 'headers', 'attachments' ) ) );
            $message = compact('html', 'subject', 'from_name', 'from_email', 'to', 'headers', 'attachments',
                'url_strip_qs',
                'merge',
                'global_merge_vars',
                'merge_vars',
                'google_analytics_domains',
                'google_analytics_campaign',
                'metadata',
                'important',
                'inline_css',
                'preserve_recipients',
                'view_content_link',
                'tracking_domain',
                'signing_domain',
                'return_path_domain',
                'subaccount',
                'recipient_metadata',
                'ip_pool',
                'send_at',
                'async'
            );
            return self::sendEmail($message, $tags, $template_name, $track_opens, $track_clicks);
        } catch ( Exception $e ) {
            error_log( "\nwpMandrill::mail: Exception Caught => ".$e->getMessage()."\n" );
            return new WP_Error( $e->getMessage() );
        }
    }

    /**
     * @return boolean
     */
    static function wp_mail_native( $to, $subject, $message, $headers = '', $attachments = array() ) {
        require SEWM_PATH . '/legacy/function.wp_mail.php';
    }

    /**
     * @link https://mandrillapp.com/api/docs/messages.html#method=send
     *
     * @param array $message
     * @param boolean $track_opens
     * @param boolean $track_clicks
     * @param array $tags
     * @return array|WP_Error
     */
    static function sendEmail( $message, $tags = array(), $template_name = '', $track_opens = true, $track_clicks = true ) {

        try {
            // Checking if we are connected to Mandrill
            self::getConnected();

            if ( !self::isConnected() ) throw new Exception('Invalid API Key');

            /************
             *
             *  Processing supplied fields to make them valid for the Mandrill API
             *
             *************************/

            // Checking the user-specified headers
            if ( empty( $message['headers'] ) ) {
                $message['headers'] = array();
            } else {
                if ( !is_array( $message['headers'] ) ) {
                    $tempheaders = explode( "\n", str_replace( "\r\n", "\n", $message['headers'] ) );
                } else {
                    $tempheaders = $message['headers'];
                }
                $message['headers'] = array();

                // If it's actually got contents
                if ( !empty( $tempheaders ) ) {
                    // Iterate through the raw headers
                    foreach ( (array) $tempheaders as $header ) {
                        if ( strpos($header, ':') === false ) continue;

                        // Explode them out
                        list( $name, $content ) = explode( ':', trim( $header ), 2 );

                        // Cleanup crew
                        $name    = trim( $name    );
                        $content = trim( $content );

                        switch ( strtolower( $name ) ) {
                            case 'from':
                                if ( strpos($content, '<' ) !== false ) {
                                    // So... making my life hard again?
                                    $from_name = substr( $content, 0, strpos( $content, '<' ) - 1 );
                                    $from_name = str_replace( '"', '', $from_name );
                                    $from_name = trim( $from_name );

                                    $from_email = substr( $content, strpos( $content, '<' ) + 1 );
                                    $from_email = str_replace( '>', '', $from_email );
                                    $from_email = trim( $from_email );
                                } else {
                                    $from_name  = '';
                                    $from_email = trim( $content );
                                }
                                $message['from_email']  = $from_email;
                                $message['from_name']   = $from_name;
                                break;

                            case 'bcc':
                                // TODO: Mandrill's API only accept one BCC address. Other addresses will be silently discarded
                                $bcc = explode( ',', $content );

                                $message['bcc_address'] = $bcc[0];
                                break;

                            case 'reply-to':
                                $message['headers'][trim( $name )] = trim( $content );
                                break;
                            case 'importance':
                            case 'x-priority':
                            case 'x-msmail-priority':
                                if ( !$message['important'] ) $message['important'] = ( strpos(strtolower($content),'high') !== false ) ? true : false;
                                break;
                            default:
                                if ( substr($name,0,2) == 'x-' ) {
                                    $message['headers'][trim( $name )] = trim( $content );
                                }
                                break;
                        }
                    }
                }
            }

            // Adding a Reply-To header if needed.
            $reply_to = self::getReplyTo();
            if ( !empty($reply_to) && !in_array( 'reply-to', array_map( 'strtolower', array_keys($message['headers']) ) ) ) {
                $message['headers']['Reply-To'] = trim(self::getReplyTo());
            }

            // Checking To: field
            if( !is_array($message['to']) ) $message['to'] = explode(',', $message['to']);

            $processed_to = array();
            foreach ( $message['to'] as $email ) {
                if ( is_array($email) ) {
                    $processed_to[] = $email;
                } else {
                    $processed_to[] = self::ensureEmailFormatting( $email );
                }
            }
            $message['to'] = $processed_to;

            // Checking From: field
            if ( empty($message['from_email']) ) $message['from_email'] = self::getFromEmail();
            if ( empty($message['from_name'] ) ) $message['from_name']  = self::getFromName();

            // Checking tags.
            $message['tags']        = self::findTags($tags);

            // Checking attachments
            if ( !empty($message['attachments']) ) {
                $message['attachments'] = self::processAttachments($message['attachments']);
                if ( is_wp_error($message['attachments']) ) {
                    throw new Exception('Invalid attachment (check http://eepurl.com/nXMa1 for supported file types).');
                } elseif ( !is_array($message['attachments']) ) {	// some plugins return this value malformed.
                    unset($message['attachments']);
                }
            }
            // Default values for other parameters
            $message['auto_text']   = true;
            $message['track_opens'] = $track_opens;
            $message['track_clicks']= $track_clicks;

            // Supporting editable sections: Common transformations for the HTML part
            $nl2br = self::getnl2br() == 1;
            $nl2br = apply_filters('mandrill_nl2br', $nl2br, $message);
            if ( $nl2br ) {
                if ( is_array($message['html']) ) {
                    foreach ($message['html'] as &$value){
                        $value['content'] = preg_replace('#<(https?://[^*]+)>#', '$1', $value['content']);
                        $value['content'] = nl2br($value['content']);
                    }

                } else {
                    $message['html'] = preg_replace('#<(https?://[^*]+)>#', '$1', $message['html']);
                    $message['html'] = nl2br($message['html']);
                }
            }

            // Defining template to use
            $template = '';
            // If user specified a given template, check if it is valid for this Mandrill account
            if ( !empty($template_name) && self::isTemplateValid($template_name) ) {
                $template = $template_name;
            } else {
                $template  = self::getTemplate();   // If no template was specified or the specified was invalid, use the general one.
            }

            // Filling template regions if supply by using an array of regions in the 'html' field of the payload
            if ( $template ) {
                if ( is_array($message['html']) ) {

                    $message['template']['name']    = $template;
                    foreach ($message['html'] as $region) {
                        $message['template']['content'][] = $region;
                    }

                    $message['html']                = '';

                } else {
                    // Default region if no array was supplied
                    $message['template']['name']    = $template;
                    $message['template']['content'] = array( array('name' => 'main', 'content' => $message['html']) );
                    $message['html']                = '';
                }
            }
			
			// Add subaccount if specified in settings
            if ( !empty(self::getSubAccount()) ) $message['subaccount'] = self::getSubAccount();

            // Letting user to filter/change the message payload
            $message['from_email']  = apply_filters('wp_mail_from', $message['from_email']);
            $message['from_name']	= apply_filters('wp_mail_from_name', $message['from_name']);
            $message  = apply_filters('mandrill_payload', $message);

            // if user doesn't want to process this email by wp_mandrill, so be it.
            if ( isset($message['force_native']) && $message['force_native'] ) throw new Exception('Manually falling back to native wp_mail()');

            // Setting the tags property correctly to be received by the Mandrill's API
            if ( !is_array($message['tags']['user']) )      $message['tags']['user']        = array();
            if ( !is_array($message['tags']['general']) )   $message['tags']['general']     = array();
            if ( !is_array($message['tags']['automatic']) ) $message['tags']['automatic']   = array();

            $message['tags'] = array_merge( $message['tags']['general'], $message['tags']['automatic'], $message['tags']['user'] );

            // Sending the message
            if ( empty($message['template'])  || empty($message['template']['name'])  || empty($message['template']['content']) ) {
                return self::$mandrill->messages_send($message);
            } else {
                $template           = $message['template']['name'];
                $template_content   = $message['template']['content'];
                unset($message['template']);

                return self::$mandrill->messages_send_template($template, $template_content, $message);
            }

        } catch ( Exception $e) {
            error_log( date('Y-m-d H:i:s') . " wpMandrill::sendEmail: Exception Caught => ".$e->getMessage()."\n" );
            return new WP_Error( $e->getMessage() );
        }
    }

    static function processAttachments($attachments = array()) {
        if ( !is_array($attachments) && $attachments )
            $attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );

        foreach ( $attachments as $index => $attachment ) {
            try {
                $attachments[$index] = Mandrill::getAttachmentStruct($attachment);
            } catch ( Exception $e ) {
                error_log( "\nwpMandrill::processAttachments: $attachment => ".$e->getMessage()."\n" );
                return new WP_Error( $e->getMessage() );
            }
        }

        return $attachments;
    }

    static function getUserAgent() {
        global $wp_version;

        if ( ! function_exists( 'get_plugins' ) ) require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	    $plugin_folder = get_plugins( '/' . dirname( SEWM_BASE ) );
	    $plugin_file = basename( SEWM_BASE );

        $me 	= $plugin_folder[$plugin_file]['Version'];
        $php 	= phpversion();
        $wp 	= $wp_version;

        return "wpMandrill/$me (PHP/$php; WP/$wp)";
    }
    
    /**
     * Ensures the email field sent to Mandrill is formatted accordingly for emails with the name formatting
     *
     * @param $email
     * @param string $type
     * @return array
     */
    static function ensureEmailFormatting( $email, $type = 'to' ) {
        if( preg_match( '/(.*)<(.+)>/', $email, $matches ) ) {
            if ( count( $matches ) == 3 ) {
                return array(
                    'email' => $matches[2],
                    'name' => $matches[1],
                    'type' => $type
                );
            }
        }
        
        return array( 'email' => $email );
    }
}

function wpMandrill_transformJSArray(&$value, $key, $params = 0) {
    if ( is_array($params) ) {
        $format 	= isset($params[0]) ? $params[0] : 0;
        $day_keys 	= isset($params[1]) ? $params[1] : array();

        switch ( $format ) {
            case 0:
                $value = "[$key,$value]";
                break;
            case 1:
                $key = array_search($key,$day_keys);
                $value = "[$key,$value]";
                break;
        }
    }
}
