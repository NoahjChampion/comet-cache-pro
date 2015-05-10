<?php
namespace WebSharks\ZenCache\Pro;

/**
 * CDN Filters.
 *
 * @since 150422 Rewrite.
 */
class CdnFilters extends AbsBase
{
    /**
     * @since 150422 Rewrite.
     *
     * @type string Local host name.
     */
    protected $local_host;

    /**
     * @since 150422 Rewrite.
     *
     * @type bool Enable CDN filters?
     */
    protected $cdn_enable;

    /**
     * @since 150409 Improving CDN support.
     *
     * @type bool Enable CDN filters in HTML Compressor?
     */
    protected $htmlc_enable;

    /**
     * @since 150422 Rewrite.
     *
     * @type string CDN serves files from this host.
     */
    protected $cdn_host;

    /**
     * @since 150422 Rewrite.
     *
     * @type bool CDN over SSL connections?
     */
    protected $cdn_over_ssl;

    /**
     * @since 150422 Rewrite.
     *
     * @type string Invalidation variable name.
     */
    protected $cdn_invalidation_var;

    /**
     * @since 150422 Rewrite.
     *
     * @type int Invalidation counter.
     */
    protected $cdn_invalidation_counter;

    /**
     * @since 150422 Rewrite.
     *
     * @type array Array of whitelisted extensions.
     */
    protected $cdn_whitelisted_extensions;

    /**
     * @since 150422 Rewrite.
     *
     * @type array Array of blacklisted extensions.
     */
    protected $cdn_blacklisted_extensions;

    /**
     * @since 150422 Rewrite.
     *
     * @type string|null CDN whitelisted URI patterns.
     */
    protected $cdn_whitelisted_uri_patterns;

    /**
     * @since 150422 Rewrite.
     *
     * @type string|null CDN blacklisted URI patterns.
     */
    protected $cdn_blacklisted_uri_patterns;

    /**
     * Class constructor.
     *
     * @since 150422 Rewrite.
     */
    public function __construct()
    {
        parent::__construct();

        /* Primary switch; CDN filters enabled? */

        $this->cdn_enable = (boolean) $this->plugin->options['cdn_enable'];

        /* Another switch; HTML Compressor enabled? */

        $this->htmlc_enable = (boolean) $this->plugin->options['htmlc_enable'];

        /* Host-related properties. */

        $this->local_host = strtolower((string) parse_url(network_home_url(), PHP_URL_HOST));
        $this->cdn_host   = strtolower($this->plugin->options['cdn_host']);

        /* Configure invalidation-related properties. */

        $this->cdn_invalidation_var     = (string) $this->plugin->options['cdn_invalidation_var'];
        $this->cdn_invalidation_counter = (integer) $this->plugin->options['cdn_invalidation_counter'];

        /* CDN supports SSL connections? */

        $this->cdn_over_ssl = (boolean) $this->plugin->options['cdn_over_ssl'];

        /* Whitelisted extensions; MUST have these at all times. */

        if (!($cdn_whitelisted_extensions = trim($this->plugin->options['cdn_whitelisted_extensions']))) {
            $cdn_whitelisted_extensions = implode('|', static::defaultWhitelistedExtensions());
        }
        $this->cdn_whitelisted_extensions = trim(strtolower($cdn_whitelisted_extensions), "\r\n\t\0\x0B".' |;,');
        $this->cdn_whitelisted_extensions = preg_split('/[|;,\s]+/', $this->cdn_whitelisted_extensions, null, PREG_SPLIT_NO_EMPTY);
        $this->cdn_whitelisted_extensions = array_unique($this->cdn_whitelisted_extensions);

        /* Blacklisted extensions; if applicable. */

        $cdn_blacklisted_extensions = $this->plugin->options['cdn_blacklisted_extensions'];

        $this->cdn_blacklisted_extensions   = trim(strtolower($cdn_blacklisted_extensions), "\r\n\t\0\x0B".' |;,');
        $this->cdn_blacklisted_extensions   = preg_split('/[|;,\s]+/', $this->cdn_blacklisted_extensions, null, PREG_SPLIT_NO_EMPTY);
        $this->cdn_blacklisted_extensions[] = 'php'; // Always exclude.

        $this->cdn_blacklisted_extensions = array_unique($this->cdn_blacklisted_extensions);

        /* Whitelisted URI patterns; if applicable. */

        $cdn_whitelisted_uri_patterns = trim(strtolower($this->plugin->options['cdn_whitelisted_uri_patterns']));
        $cdn_whitelisted_uri_patterns = preg_split('/['."\r\n".']+/', $cdn_whitelisted_uri_patterns, null, PREG_SPLIT_NO_EMPTY);
        $cdn_whitelisted_uri_patterns = array_unique($cdn_whitelisted_uri_patterns);

        if ($cdn_whitelisted_uri_patterns) {
            $this->cdn_whitelisted_uri_patterns = '/(?:'.implode('|', array_map(function ($pattern) {
                return preg_replace(array('/\\\\\*/', '/\\\\\^/'), array('.*?', '[^\/]*?'), preg_quote('/'.ltrim($pattern, '/'), '/')); #

            }, $cdn_whitelisted_uri_patterns)).')/i'; // CaSe inSensitive.
        }
        /* Blacklisted URI patterns; if applicable. */

        $cdn_blacklisted_uri_patterns   = trim(strtolower($this->plugin->options['cdn_blacklisted_uri_patterns']));
        $cdn_blacklisted_uri_patterns   = preg_split('/['."\r\n".']+/', $cdn_blacklisted_uri_patterns, null, PREG_SPLIT_NO_EMPTY);
        $cdn_blacklisted_uri_patterns[] = '*/wp-admin/*'; // Always.

        if (is_multisite()) {
            $cdn_blacklisted_uri_patterns[] = '/^/files/*';
        }
        if (defined('WS_PLUGIN__S2MEMBER_VERSION')) {
            $cdn_blacklisted_uri_patterns[] = '*/s2member-files/*';
        }
        $cdn_blacklisted_uri_patterns = array_unique($cdn_blacklisted_uri_patterns);

        if ($cdn_blacklisted_uri_patterns) {
            $this->cdn_blacklisted_uri_patterns = '/(?:'.implode('|', array_map(function ($pattern) {
                return preg_replace(array('/\\\\\*/', '/\\\\\^/'), array('.*?', '[^\/]*?'), preg_quote('/'.ltrim($pattern, '/'), '/')); #

            }, $cdn_blacklisted_uri_patterns)).')/i'; // CaSe inSensitive.
        }
        /* Maybe attach filters. */

        $this->maybeSetupFilters();
    }

    /**
     * Setup URL and content filters.
     *
     * @since 150422 Rewrite.
     */
    protected function maybeSetupFilters()
    {
        if (is_admin()) {
            return; // Not applicable.
        }
        if (!$this->cdn_enable) {
            return; // Disabled currently.
        }
        if (!$this->local_host) {
            return; // Not possible.
        }
        if (!$this->cdn_host) {
            return; // Not possible.
        }
        if (!$this->cdn_over_ssl && is_ssl()) {
            return; // Disable in this case.
        }
        if (is_multisite() && (!is_main_site() && defined('SUBDOMAIN_INSTALL') && SUBDOMAIN_INSTALL)) {/*
             * @TODO this is something we need to look at in the future.
             *
             * We expect a single local host name at present.
             *    However, it MIGHT be feasible to allow for wildcarded host names
             *    in order to support sub-domain installs in the future.
             *
             * ~ Domain mapping will be another thing to look at.
             *    I don't see an easy way to support domain mapping plugins.
             */
            return; // Not possible; requires a sub-directory install (for now).
        }
        add_filter('home_url', array($this, 'urlFilter'), PHP_INT_MAX - 10, 4);
        add_filter('site_url', array($this, 'urlFilter'), PHP_INT_MAX - 10, 4);

        add_filter('network_home_url', array($this, 'urlFilter'), PHP_INT_MAX - 10, 3);
        add_filter('network_site_url', array($this, 'urlFilter'), PHP_INT_MAX - 10, 3);

        add_filter('content_url', array($this, 'urlFilter'), PHP_INT_MAX - 10, 2);
        add_filter('plugins_url', array($this, 'urlFilter'), PHP_INT_MAX - 10, 2);

        add_filter('wp_get_attachment_url', array($this, 'urlFilter'), PHP_INT_MAX - 10, 1);

        add_filter('script_loader_src', array($this, 'urlFilter'), PHP_INT_MAX - 10, 1);
        add_filter('style_loader_src', array($this, 'urlFilter'), PHP_INT_MAX - 10, 1);

        add_filter('the_content', array($this, 'contentFilter'), PHP_INT_MAX - 10, 1);
        add_filter('get_the_excerpt', array($this, 'contentFilter'), PHP_INT_MAX - 10, 1);
        add_filter('widget_text', array($this, 'contentFilter'), PHP_INT_MAX - 10, 1);

        if ($this->htmlc_enable) {
            // If the HTML Compressor is enabled, attach early hook. Runs later.
            if (empty($GLOBALS['WebSharks\\HtmlCompressor_early_hooks']) || !is_array($GLOBALS['WebSharks\\HtmlCompressor_early_hooks'])) {
                $GLOBALS['WebSharks\\HtmlCompressor_early_hooks'] = array(); // Initialize.
            }
            $GLOBALS['WebSharks\\HtmlCompressor_early_hooks'][__CLASS__] = array(
                'hook'          => 'part_url', // Filters JS/CSS parts.
                'function'      => array($this, 'urlFilter'),
                'priority'      => PHP_INT_MAX - 10,
                'accepted_args' => 1,
            );
        }
    }

    /**
     * Filter home/site URLs that should be served by the CDN.
     *
     * @since 150422 Rewrite.
     *
     * @param string      $url     Input URL|URI|query; passed by filter.
     * @param string      $path    The path component(s) passed through by the filter.
     * @param string|null $scheme  `NULL`, `http`, `https`, `login`, `login_post`, `admin`, or `relative`.
     * @param int|null    $blog_id Blog ID; passed only by non-`network_` filter variations.
     *
     * @return string The URL after having been filtered.
     */
    public function urlFilter($url, $path = '', $scheme = null, $blog_id = null)
    {
        return $this->filterUrl($url, $scheme);
    }

    /**
     * Filter content for URLs that should be served by the CDN.
     *
     * @since 150422 Rewrite.
     *
     * @param string $string Input content string to filter; i.e. HTML code.
     *
     * @return string The content string after having been filtered.
     */
    public function contentFilter($string)
    {
        if (!($string = (string) $string)) {
            return $string; // Nothing to do.
        }
        if (strpos($string, '<') === false) {
            return $string; // Nothing to do.
        }
        $_this = $this; // Reference needed by closures below.

        $regex_url_attrs = '/'.// HTML attributes containing a URL.

                           '(\<)'.// Open tag; group #1.
                           '([\w\-]+)'.// Tag name; group #2.

                           '([^>]*?)'.// Others before; group #3.

                           '(\s(?:href|src)\s*\=\s*)'.// ` attribute=`; group #4.
                           '(["\'])'.// Open double or single; group #5.
                           '([^"\'>]+?)'.// Possible URL; group #6.
                           '(\\5)'.// Close quote; group #7.

                           '([^>]*?)'.// Others after; group #8.

                           '(\>)'.// Tag close; group #9.

                           '/i'; // End regex pattern; case insensitive.

        $orig_string = $string; // In case of regex errors.
        $string      = preg_replace_callback($regex_url_attrs, function ($m) use ($_this) {
            unset($m[0]); // Discard full match.
            $m[6] = $_this->filterUrl($m[6], null, true);
            return implode('', $m); // Concatenate all parts.
        }, $string); // End content filter.

        return $string ? $string : $orig_string;
    }

    /**
     * Filter URLs that should be served by the CDN.
     *
     * @since 150422 Rewrite.
     *
     * @param string      $url_uri_query Input URL|URI|query.
     * @param string|null $scheme        `NULL`, `http`, `https`, `login`, `login_post`, `admin`, or `relative`.
     * @param bool        $esc           Defaults to a FALSE value; do not deal with HTML entities.
     *
     * @return string The URL after having been filtered.
     */
    public function filterUrl($url_uri_query, $scheme = null, $esc = false)
    {
        if (!($url_uri_query = trim((string) $url_uri_query))) {
            return; // Unparseable.
        }
        $orig_url_uri_query = $url_uri_query;
        if ($esc) {
            $url_uri_query = wp_specialchars_decode($url_uri_query, ENT_QUOTES);
        }
        if (!($local_file = $this->localFile($url_uri_query))) {
            return $orig_url_uri_query; // Not a local file.
        }
        if (!in_array($local_file->extension, $this->cdn_whitelisted_extensions, true)) {
            return $orig_url_uri_query; // Not a whitelisted extension.
        }
        if ($this->cdn_blacklisted_extensions && in_array($local_file->extension, $this->cdn_blacklisted_extensions, true)) {
            return $orig_url_uri_query; // Exclude; it's a blacklisted extension.
        }
        if ($this->cdn_whitelisted_uri_patterns && !preg_match($this->cdn_whitelisted_uri_patterns, $local_file->uri)) {
            return $orig_url_uri_query; // Exclude; not a whitelisted URI pattern.
        }
        if ($this->cdn_blacklisted_uri_patterns && preg_match($this->cdn_blacklisted_uri_patterns, $local_file->uri)) {
            return $orig_url_uri_query; // Exclude; it's a blacklisted URI pattern.
        }
        if (!isset($scheme) && isset($local_file->scheme)) {
            $scheme = $local_file->scheme; // Use original scheme.
        }
        $url = set_url_scheme('//'.$this->cdn_host.$local_file->uri, $scheme);

        if ($this->cdn_invalidation_var && $this->cdn_invalidation_counter) {
            $url = add_query_arg($this->cdn_invalidation_var, $this->cdn_invalidation_counter, $url);
        }
        return $esc ? esc_attr($url) : $url;
    }

    /**
     * Parse a URL|URI|query into a local file array.
     *
     * @since 150422 Rewrite.
     *
     * @param string $url_uri_query Input URL|URI|query.
     *
     * @return object|null An object with: `scheme`, `extension`, `uri` properties.
     *                     This returns NULL for any URL that is not local, or does not lead to a file.
     */
    protected function localFile($url_uri_query)
    {
        if (!($url_uri_query = trim((string) $url_uri_query))) {
            return; // Unparseable.
        }
        if (!($parsed = @parse_url($url_uri_query))) {
            return; // Unparseable.
        }
        if (!empty($parsed['host']) && strcasecmp($parsed['host'], $this->local_host) !== 0) {
            return; // Not on this host name.
        }
        if (!isset($parsed['path'][0]) || $parsed['path'][0] !== '/') {
            return; // Missing or unexpected path.
        }
        if (substr($parsed['path'], -1) === '/') {
            return; // Directory, not a file.
        }
        if (strpos($parsed['path'], '..') !== false || strpos($parsed['path'], './') !== false) {
            return; // A relative path that is not absolute.
        }
        $scheme = null; // Default scheme handling.
        if (!empty($parsed['scheme'])) {
            $scheme = strtolower($parsed['scheme']);
        }
        if (!($extension = $this->extension($parsed['path']))) {
            return; // No extension; i.e. not a file.
        }
        $uri = $parsed['path']; // Put URI together.

        if (!empty($parsed['query'])) {
            $uri .= '?'.$parsed['query'];
        }
        if (!empty($parsed['fragment'])) {
            $uri .= '#'.$parsed['fragment'];
        }
        return (object) compact('scheme', 'extension', 'uri');
    }

    /**
     * Get extension from a file path.
     *
     * @since 150422 Rewrite.
     *
     * @param string $path Input file path.
     *
     * @return string File extension (lowercase), else an empty string.
     */
    protected function extension($path)
    {
        if (!($path = trim((string) $path))) {
            return ''; // No path.
        }
        return strtolower(ltrim((string) strrchr(basename($path), '.'), '.'));
    }

    /**
     * Default whitelisted extensions.
     *
     * @since 150314 Auto-excluding font file extensions.
     *
     * @return array Default whitelisted extensions.
     */
    public static function defaultWhitelistedExtensions()
    {
        $wp_media_library_extensions = array_keys(wp_get_mime_types());
        $wp_media_library_extensions = explode('|', strtolower(implode('|', $wp_media_library_extensions)));
        $font_file_extensions        = array('eot', 'ttf', 'otf', 'woff');

        return array_unique(array_merge($wp_media_library_extensions, $font_file_extensions));
    }
}
