<?php
/*
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2008-2010, StatusNet, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.     See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.     If not, see <http://www.gnu.org/licenses/>.
 */

if (!defined('GNUSOCIAL')) { exit(1); }


/**
 * Utility class to wrap basic oEmbed lookups.
 *
 * Blacklisted hosts will use an alternate lookup method:
 *  - Twitpic
 *
 * Whitelisted hosts will use known oEmbed API endpoints:
 *  - Flickr, YFrog
 *
 * Sites that provide discovery links will use them directly; a bug
 * in use of discovery links with query strings is worked around.
 *
 * Others will fall back to oohembed (unless disabled).
 * The API endpoint can be configured or disabled through config
 * as 'oohembed'/'endpoint'.
 */
class oEmbedHelper
{
    protected static $apiMap = array(
        'flickr.com' => 'https://www.flickr.com/services/oembed/',
        'youtube.com' => 'https://www.youtube.com/oembed',
        'viddler.com' => 'http://lab.viddler.com/services/oembed/',
        'revision3.com' => 'https://revision3.com/api/oembed/',
        'vimeo.com' => 'https://vimeo.com/api/oembed.json',
    );

    /**
     * Perform or fake an oEmbed lookup for the given resource.
     *
     * Some known hosts are whitelisted with API endpoints where we
     * know they exist but autodiscovery data isn't available.
     * If autodiscovery links are missing and we don't recognize the
     * host, we'll pass it to noembed.com's public service which
     * will either proxy or fake info on a lot of sites.
     *
     * A few hosts are blacklisted due to known problems with oohembed,
     * in which case we'll look up the info another way and return
     * equivalent data.
     *
     * Throws exceptions on failure.
     *
     * @param string $url
     * @param array $params
     * @return object
     */
    public static function getObject($url, $params=array())
    {
        common_log(LOG_INFO, 'Checking for remote URL metadata for ' . $url);

        // TODO: Make this class something like UrlMetadata, or use a dataobject?
        $metadata = new stdClass();

        if (Event::handle('GetRemoteUrlMetadata', array($url, &$metadata))) {
            // If that event didn't return anything, try downloading the body and parse it
            $body = HTTPClient::quickGet($url);

            // DOMDocument::loadHTML may throw warnings on unrecognized elements,
            // and notices on unrecognized namespaces.
            $old = error_reporting(error_reporting() & ~(E_WARNING | E_NOTICE));
            $dom = new DOMDocument();
            $ok = $dom->loadHTML($body);
            unset($body);   // storing the DOM in memory is enough...
            error_reporting($old);

            if (!$ok) {
                throw new oEmbedHelper_BadHtmlException();
            }

            Event::handle('GetRemoteUrlMetadataFromDom', array($url, $dom, &$metadata));
        }

        return self::normalize($metadata);
    }

    /**
     * Partially ripped from OStatus' FeedDiscovery class.
     *
     * @param string $url source URL, used to resolve relative links
     * @param string $body HTML body text
     * @return mixed string with URL or false if no target found
     */
    static function oEmbedEndpointFromHTML(DOMDocument $dom)
    {
        // Ok... now on to the links!
        $feeds = array(
            'application/json+oembed' => false,
        );

        $nodes = $dom->getElementsByTagName('link');
        for ($i = 0; $i < $nodes->length; $i++) {
            $node = $nodes->item($i);
            if ($node->hasAttributes()) {
                $rel = $node->attributes->getNamedItem('rel');
                $type = $node->attributes->getNamedItem('type');
                $href = $node->attributes->getNamedItem('href');
                if ($rel && $type && $href) {
                    $rel = array_filter(explode(" ", $rel->value));
                    $type = trim($type->value);
                    $href = trim($href->value);

                    if (in_array('alternate', $rel) && array_key_exists($type, $feeds) && empty($feeds[$type])) {
                        // Save the first feed found of each type...
                        $feeds[$type] = $href;
                    }
                }
            }
        }

        // Return the highest-priority feed found
        foreach ($feeds as $type => $url) {
            if ($url) {
                return $url;
            }
        }

        throw new oEmbedHelper_DiscoveryException();
    }

    /**
     * Actually do an oEmbed lookup to a particular API endpoint.
     *
     * @param string $api oEmbed API endpoint URL
     * @param string $url target URL to look up info about
     * @param array $params
     * @return object
     */
    static function getOembedFrom($api, $url, $params=array())
    {
        if (empty($api)) {
            // TRANS: Server exception thrown in oEmbed action if no API endpoint is available.
            throw new ServerException(_('No oEmbed API endpoint available.'));
        }
        $params['url'] = $url;
        $params['format'] = 'json';
        $key=common_config('oembed','apikey');
        if(isset($key)) {
            $params['key'] = common_config('oembed','apikey');
        }
        return HTTPClient::quickGetJson($api, $params);
    }

    /**
     * Normalize oEmbed format.
     *
     * @param object $orig
     * @return object
     */
    static function normalize(stdClass $data)
    {
        if (empty($data->type)) {
            throw new Exception('Invalid oEmbed data: no type field.');
        }
        if ($data->type == 'image') {
            // YFrog does this.
            $data->type = 'photo';
        }

        if (isset($data->thumbnail_url)) {
            if (!isset($data->thumbnail_width)) {
                // !?!?!
                $data->thumbnail_width = common_config('thumbnail', 'width');
                $data->thumbnail_height = common_config('thumbnail', 'height');
            }
        }

        return $data;
    }
}

class oEmbedHelper_Exception extends Exception
{
    public function __construct($message = "", $code = 0, $previous = null)
    {
        parent::__construct($message, $code);
    }
}

class oEmbedHelper_BadHtmlException extends oEmbedHelper_Exception
{
    function __construct($previous=null)
    {
        return parent::__construct('Bad HTML in discovery data.', 0, $previous);
    }
}

class oEmbedHelper_DiscoveryException extends oEmbedHelper_Exception
{
    function __construct($previous=null)
    {
        return parent::__construct('No oEmbed discovery data.', 0, $previous);
    }
}
