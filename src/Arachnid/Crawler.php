<?php

namespace Arachnid;

use Goutte\Client;

/**
 * Crawler
 *
 * This class will crawl all unique internal links found on a given website
 * up to a specified maximum page depth.
 *
 * This library is based on the original blog post by Zeid Rashwani here:
 *
 * <http://zrashwani.com/simple-web-spider-php-goutte>
 *
 * Josh Lockhart adapted the original blog post's code (with permission)
 * for Composer and Packagist and updated the syntax to conform with
 * the PSR-2 coding standard.
 *
 * @package Crawler
 * @author  Josh Lockhart <https://github.com/codeguy>
 * @author  Zeid Rashwani <http://zrashwani.com>
 * @version 1.0.0
 */
class Crawler
{
    /**
     * The base URL from which the crawler begins crawling
     * @var string
     */
    protected $baseUrl;

    /**
     * The max depth the crawler will crawl
     * @var int
     */
    protected $maxDepth;

    protected $maxCount;

    /**
     * Array of links (and related data) found by the crawler
     * @var array
     */
    protected $links;

    /**
     * Constructor
     * @param string $baseUrl
     * @param int    $maxDepth
     */
    public function __construct($baseUrl, $maxDepth = 3, $maxCount = 100)
    {

        $un =  new \URL\Normalizer( $baseUrl );
        $this->baseUrl = $un->normalize();
        $this->maxDepth = $maxDepth;
        $this->maxCount = $maxCount;
        $this->links = array();
    }

    /**
     * Initiate the crawl
     * @param string $url
     */
    public function traverse($url = null)
    {
        if ($url === null) {
            $url = $this->baseUrl;
            $this->links[$url] = array(
                'frequency' => 1,
                'visited' => false,
            );
        }

        $this->traverseSingle($url, $this->maxDepth,$this->maxCount);
    }

    /**
     * Get links (and related data) found by the crawler
     * @return array
     */
    public function getLinks()
    {
        return $this->links;
    }

    /**
     * Crawl single URL
     * @param string $url
     * @param int    $depth
     * @param int    $count
     */
    protected function traverseSingle($url, $depth, $count)
    {
        try {
            $client = new Client();
            $client->followRedirects(true);
            $crawler = $client->request('GET', $url);
            $statusCode = $client->getResponse()->getStatus();

            $hash = $this->getNormalUrl($url);

            $this->links[$hash]['depth'] = $this->getDepth($hash);
            $this->links[$hash]['status_code'] = $statusCode;


            if ($statusCode === 200) {
                $this->extractTitleInfo($crawler, $hash);
                $childLinks = array();
                $childLinks = $this->extractLinksInfo($crawler, $hash);
                $this->traverseChildren($childLinks, $depth - 1,$count);
            }else{
                $this->links[$hash]['title'] = '';
                $this->links[$hash]['description'] = '';
                $this->links[$hash]['h1'] = '';
                $this->links[$hash]['body_length'] = 0;
            }
            $this->links[$hash]['visited'] = true;
        } catch (\Guzzle\Http\Exception\CurlException $e) {
            unset($this->links[$url]);
            echo($e->getMessage());
            echo($e->getLine());
        } catch (\Exception $e) {
            unset($this->links[$url]);
            echo($e->getMessage());
            echo($e->getLine());
        }
    }


    protected function getDepth($url){
        $parsedUrl = parse_url($url);

        if(!isset($parsedUrl['path'])){
            return 0;
        }

        $path = explode('/',$parsedUrl['path']);

        return count(array_filter($path));
    }


    protected function getNormalUrl($url){
        $url = preg_replace('/^\/\//', 'http://', $url);
        //echo($url);
        $url = strtok($url, '?');
        $normalized = new \URL\Normalizer( $this->rel2abs($url,$this->baseUrl) );
        return $normalized->normalize();
    }


    /**
     * Crawl child links
     * @param array $childLinks
     * @param int   $depth
     */
    protected function traverseChildren($childLinks, $depth, $count)
    {


        if ($depth === 0) {
            return;
        }

//avar_dump($childLinks);die();


        foreach ($childLinks as $url => $info) {


            if(!$url){
                continue;
            }

            if(count($this->links)>=$count){
                break;
            }


            $hash = $this->getNormalUrl($url);

            if (isset($this->links[$hash]) === false) {
                $this->links[$hash] = $info;
            } else {

                if (isset($this->links[$hash]['visited']) === true && $this->links[$hash]['visited'] === true) {
                    $oldFrequency = isset($info['frequency']) ? $info['frequency'] : 0;
                    $this->links[$hash]['frequency'] = isset($this->links[$hash]['frequency']) ? $this->links[$hash]['frequency'] + $oldFrequency : 1;
                }
            }

            if (isset($this->links[$hash]['visited']) === false) {
                $this->links[$hash]['visited'] = false;
            }

            $this->links[$hash]['depth'] = $this->getDepth($hash);

            if($this->links[$hash]['visited'] === false){
                $this->traverseSingle($hash, $depth,$count);
            }


        }
        //var_dump($this->links);die();
    }

    /**
     * Extract links information from url
     * @param  \Symfony\Component\DomCrawler\Crawler $crawler
     * @param  string                                $url
     * @return array
     */
    protected function extractLinksInfo(\Symfony\Component\DomCrawler\Crawler $crawler, $url)
    {
        $childLinks = array(
        );
        $crawler->filter('a')->each(function (\Symfony\Component\DomCrawler\Crawler $node, $i) use (&$childLinks) {
                  //  $node_text = trim($node->text());
                    $node_url = $node->attr('href');
                   // $node_url_is_crawlable = $this->checkIfCrawlable($node_url);
                    $hash = $this->getNormalUrl($node_url);
                    if(!$this->checkIfExternal($hash) && $this->checkIfCrawlable($hash))  {

                    if (isset($this->links[$hash]) === false) {


                                $childLinks[$hash]['visited'] = false;
                                $childLinks[$hash]['frequency'] = isset($childLinks[$hash]['frequency']) ? $childLinks[$hash]['frequency'] + 1 : 1;


                        }
                    }
                    else{
                        unset( $childLinks[$hash]);
                    }
                });

        // Avoid cyclic loops with pages that link to themselves
        if (isset($childLinks[$url]) === true) {
            $childLinks[$url]['visited'] = true;
        }



        return $childLinks;
    }

    /**
     * Extract title information from url
     * @param \Symfony\Component\DomCrawler\Crawler $crawler
     * @param string                                $url
     */
    protected function extractTitleInfo(\Symfony\Component\DomCrawler\Crawler $crawler, $url)
    {

        try{
            $this->links[$url]['body_length'] = strip_tags(strlen(trim($crawler->filterXPath('html/body')->text())));
        }catch (\Exception $e){
            $this->links[$url]['title'] = 0;
        }


        try{
            $this->links[$url]['title'] = trim($crawler->filterXPath('html/head/title')->text());
        }catch (\Exception $e){
            $this->links[$url]['title'] = '';
        }

        try{
            $this->links[$url]['description'] = trim($crawler->filterXpath('//meta[@name="description"]')->attr('content'));
        }catch (\Exception $e){
            $this->links[$url]['description'] = '';
        }

        try{
            $this->links[$url]['h1'] = $crawler->filter('h1')->first()->text();
        }catch (\Exception $e){
            $this->links[$url]['h1'] = '';
        }


    }

    /**
     * Is a given URL crawlable?
     * @param  string $uri
     * @return bool
     */
    protected function checkIfCrawlable($uri)
    {
        if (empty($uri) === true) {
            return false;
        }

        $stop_links = array(
            '@^javascript\:.*$@i',
            '@^#.*@',
            '@^mailto\:.*@i',
            '@^irc\:.*@i',
        );

        foreach ($stop_links as $ptrn) {
            if (preg_match($ptrn, $uri) == true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is URL external?
     * @param  string $url An absolute URL (with scheme)
     * @return bool
     */
    protected function checkIfExternal($url)
    {
        $base_url_trimmed = str_replace(array('http://', 'https://'), '', $this->baseUrl);

        return preg_match("@http(s)?\://$base_url_trimmed@", $url) == false;
    }

    /**
     * Normalize link (remove hash, etc.)
     * @param  string $url
     * @return string
     */
    protected function normalizeLink($uri)
    {
        $newUrl = preg_replace('@#.*$@', '', $uri);


        return preg_replace('@#.*$@', '', $newUrl);
    }

    /**
     * extrating the relative path from url string
     * @param  type $url
     * @return type
     */
    protected function getPathFromUrl($url)
    {
        if (strpos($url, $this->baseUrl) === 0 && $url !== $this->baseUrl) {
            return str_replace($this->baseUrl,'', $url);
        } else {
            return $url;
        }
    }


    protected function rel2abs($rel, $base)
    {

      //  var_dump($rel);
        if(!$rel){
            return $base;
        }

        /* return if already absolute URL */
        if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;



        /* queries and anchors */
        try{
            if ($rel[0]=='#' || $rel[0]=='?') return $base.$rel;
        }catch (\Exception $e){

        }


        /* parse base URL and convert to local variables:
           $scheme, $host, $path */
        $path = '';
        extract(parse_url($base));




        /* remove non-directory element from path */
        $path = preg_replace('#/[^/]*$#', '', $path);

        /* destroy path if relative url points to root */
        if ($rel[0] == '/') $path = '';

        /* dirty absolute URL */
        $abs = "$host$path/$rel";

        /* replace '//' or '/./' or '/foo/../' with '/' */
        $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
        for($n=1; $n>0; $abs=preg_replace($re, '/', $abs, -1, $n)) {}

        /* absolute URL is ready! */
        return $scheme.'://'.$abs;
    }

}
