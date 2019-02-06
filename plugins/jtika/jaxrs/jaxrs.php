
<?php
/**
 * @package     JTika.Plugins
 * @copyright   Copyright (C) 2013-2017 KnowledgeArc Ltd. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined('_JEXEC') or die;

use \JString as JString;
use \JFactory as JFactory;
use \JLanguageHelper as JLanguageHelper;
use \JArrayHelper as JArrayHelper;

/**
 * A class for extracting content and metadata using the TikaJAXRS server.
 *
 * @package     JTika.Plugins
 */
class PlgJTikaJaxrs extends \JPlugin
{
    protected $autoloadLanguage = true;

    public function onJTikaExtract($file)
    {
        $this->file = $file;

        $metadata = $this->getMetadata();

        if ($this->isAllowedContentType()) {
            $data = array();

            if ($this->isContentIndexable()) {
                $data["content"] = $this->getContent();
            }

            $data["metadata"] = $metadata;

            $data["lang"] = $this->getLanguage();

            return (object) $data;
        } else {
            return null;
        }
    }

    private function extract($endpoint, $headers = ["Accept"=>"text/plain"])
    {
        \JLog::addLogger(array());

        $url = $this->params->get('url');

        if ($url) {
            if (substr_compare($url, '/', -strlen('/')) !== 0) {
                $url .= '/';
            }
        }

        $url .= $endpoint;

        \JLog::add('server url: '.$url, \JLog::DEBUG, 'tikaserver');

        $headers = array_merge(['fileUrl'=>$this->file], $headers);

        \JLog::add('server headers: '.print_r($headers, true), \JLog::DEBUG, 'tikaserver');

        $http = \JHttpFactory::getHttp();
        $response = $http->put($url, null, $headers);

        if ($response->code == 200) {
            return $response->body;
        } else {
            throw new \Exception($response->body, $response->code);
        }
    }

    public function getContentType()
    {
        return $this->getMetadata()->get('Content-Type');
    }

    /**
     * Gets an array of languages associated with this document.
     *
     * In many cases only a two-letter iso language (iso639) is attached to the
     * document. However, Joomla supports both language (iso639) and region
     * (iso3166), E.g. en-AU.
     * This method will attempt to match all the language codes with their
     * language+region counterparts so it is possible that more than one code
     * will be returned for the document.
     *
     * @return array An array of languages associated with the document.
     */
    public function getLanguage()
    {
        $result = $this->getMetadata()->get('language');

        $results = explode(",", $result);
        $results = array_map('trim', $results);

        $array = array();

        foreach ($results as $value) {
            if ($value) {
                if (JString::strlen($value) == 5) { // assume iso with region
                    $array[] = str_replace('_', '-', $value);
                } elseif (JString::strlen($value) == 2) { // assume iso without region
                    $found = false;
                    $languages = JLanguageHelper::getLanguages();

                    while (($language = current($languages)) && !$found) {
                        $parts = explode('-', $language->lang_code);
                        if ($value == JArrayHelper::getValue($parts, 0)) {
                            if (array_search($language->lang_code, $array) === false) {
                                $array[] = $language->lang_code;
                            }

                            $found = true;
                        }

                        next($languages);
                    }

                    reset($languages);
                }
            }
        }

        // if no languages could be detected, use the system lang.
        if (!count($array)) {
            $array[] = JFactory::getLanguage()->getTag();
        }

        return $array;
    }

    public function getContent()
    {
        return $this->extract('tika');
    }

    public function getMetadata()
    {
        $result = $this->extract('meta', array("Accept"=>"application/json"));
        return new \Joomla\Registry\Registry($result);
    }

    public function isAllowedContentType()
    {
        $allowed = false;

        $contentType = $this->getContentType();

        $types = $this->params->get('allowed_content_types');

        $types = array_map('trim', explode(',', trim($types)));

        while ((($type = current($types)) !== false) && !$allowed) {
            if (preg_match("#".$type."#i", $contentType)) {
                $allowed = true;
            }

            next($types);
        }

        return $allowed;
    }

    public function isContentIndexable()
    {
        $allowed = false;

        $contentType = $this->getContentType();

        $types = $this->params->get('allowed_full_text_indexing');

        $types = array_map('trim', explode(',', trim($types)));

        while ((($type = current($types)) !== false) && !$allowed) {
            if (preg_match("#".$type."#i", $contentType)) {
                $allowed = true;
            }

            next($types);
        }

        return $allowed;
    }
}
