<?php
/**
 * Feed Me Medium Parser plugin for Feed Me
 *
 * Add featured image to Medium.com feed - Feed Me plugin
 *
 * @link      https://www.webmenedzser.hu
 * @copyright Copyright (c) 2019 Ottó Radics
 */

namespace webmenedzser\feedmemediumfeaturedimage;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\feedme\events\FeedDataEvent;
use craft\feedme\services\DataTypes;
use craft\helpers;

use yii\base\Event;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://craftcms.com/docs/plugins/introduction
 *
 * @author    Ottó Radics
 * @package   FeedMeMediumFeaturedImage
 * @since     1.0.0
 */
class FeedMeMediumFeaturedImage extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * FeedMeMediumFeaturedImage::$plugin
     *
     * @var FeedMeMediumFeaturedImage
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public $schemaVersion = '1.0.0';

    /*
     * Collect article URLs into this array.
     *
     * @var array
     */
    public $urls = [];

    /*
     * Collect featured images into this array.
     *
     * @var array
     */
    public $data = [];

    /*
     * Count the items in the feed
     *
     * @var int
     */
    public $count = 0;

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * FeedMeMediumFeaturedImage::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        Event::on(DataTypes::class, DataTypes::EVENT_AFTER_FETCH_FEED, function(FeedDataEvent $event) {
            if ($event->response['success']) {
                $feedUrl = $event->url;

                if ($this->_isMediumFeed($feedUrl)) {
                    $xml = $event->response['data'];

                    $this->urls = $this->_findURLs($xml);
                    $this->data['images'] = $this->_getMediumImages($this->urls);
                    $this->data['descriptions'] = $this->_getMediumDescriptions($this->urls);
                }
            }
        });

        Event::on(DataTypes::class, DataTypes::EVENT_AFTER_PARSE_FEED, function(FeedDataEvent $event) {
            if ($event->response['success']) {
                for ($i = 0; $i < $this->count; $i++) {
                    $event->response['data'][$i]['mediumFeaturedImage'] = $this->data['images'][$i];
                    $event->response['data'][$i]['mediumDescription'] = $this->data['descriptions'][$i];
                }
            }
        });
    }

    // Private Methods
    // =========================================================================

    private function _isMediumFeed($feedUrl) {
        if (strpos($feedUrl, 'medium.com')) {
            return true;
        }

        return false;
    }

    private function _findURLs($xml) {
        $xmlDoc = new \DOMDocument();
        $xmlDoc->loadXML($xml);
        $items = $xmlDoc->getElementsByTagName('item');
        $this->count = count($items);

        foreach ($items as $item) {
            $urls[] = $item->getElementsByTagName('link')->item(0)->nodeValue;
        }

        return $urls;
    }

    private function _loadXPath($url) {
        $contentDoc = new \DOMDocument();

        // Workaround for "Tag figure invalid in Entity" error
        // https://stackoverflow.com/questions/6090667/php-domdocument-errors-warnings-on-html5-tags
        libxml_use_internal_errors(true);
        $contentDoc->loadHTMLFile($url);
        libxml_clear_errors();

        // Fetch actual page & parse <head>, looking for og:image
        $xpath = new \DOMXPath($contentDoc);

        return $xpath;
    }

    private function _getMediumImages($urls) {
        $images = [];

        foreach ($urls as $url) {
            $xpath = $this->_loadXPath($url);

            $image = $xpath->evaluate('string(//meta[@property="og:image"]/@content)');
            $images[] = $image;
        }

        return $images;
    }

    private function _getMediumDescriptions($urls) {
        $descriptions = [];

        foreach ($urls as $url) {
            $xpath = $this->_loadXPath($url);

            $description = $xpath->evaluate('string(//meta[@name="description"]/@content)');
            $descriptions[] = $description;
        }

        return $descriptions;
    }

    private function _writeLogs($message) {
        $file = Craft::getAlias('@storage/logs/parser.log');
        $log = date('Y-m-d H:i:s').' ' . $message."\n";

        \craft\helpers\FileHelper::writeToFile($file, $log, ['append' => true]);
    }
}
