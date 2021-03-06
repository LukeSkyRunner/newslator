<?php
/**
 * Created by PhpStorm.
 * User: desaroger
 * Date: 6/11/16
 * Time: 17:39
 */

namespace AppBundle\Utils;

use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use AppBundle\Entity\Feed;

use Sunra\PhpSimple\HtmlDomParser;

/**
 * @codeCoverageIgnore
 */
class Scraper
{
    /**
     * TODO: Restructure call tree
     * Now we have a little decoupled code here, where the order of calling
     * the methods isn't very clear. This will need a little reorganization
     * where we follow the simple calling path:
     *  httpCall -> SimpleXML -> Feed
     */

    public function __construct(EntityManager $em, Container $container)
    {
        $this->em = $em;
        $this->container = $container;
    }


    /**
     * It stores the valid publishers. Perhaps in the future this might be
     * stored on DB, but for now we need to make custom scraping for
     * different publishers.
     *
     * @var array
     */
    public $publishers = [
        'elpais' => [
            'code' => 'elpais',
            'url' => 'http://elpais.com/',
            'rss' => 'http://ep00.epimg.net/rss/elpais/portada.xml'
        ],
        'elmundo' => [
            'code' => 'elmundo',
            'url' => 'http://elmundo.com/',
            'rss' => 'http://estaticos.elmundo.es/elmundo/rss/portada.xml'
        ],
        'elconfidencial' => [
            'code' => 'elconfidencial',
            'url' => 'http://www.elconfidencial.com/',
            'rss' => 'http://rss.elconfidencial.com/espana/'
        ],
        'larazon' => [
            'code' => 'larazon',
            'url' => 'http://www.larazon.es/',
            'rss' => 'http://www.larazon.es/rss/portada.xml'
        ],
        'elperiodico' => [
            'code' => 'elperiodico',
            'url' => 'http://www.elperiodico.com/es/',
            'rss' => 'http://www.elperiodico.com/es/rss/rss_portada.xml'
        ]
    ];

    /**
     * The method you actually will call to get the feed object.
     *
     * @param $publisherCode
     * @return Feed
     */
    public function read($publisherCode) {

        // Get SimpleXML object
        $xml = $this->readRss($publisherCode);

        // Get Feed object
        $feed = $this->parseToFeed($xml, $publisherCode);

        return $feed;
    }

    public function readAndPersist($publisherCode) {

        // Determine if scrap one or all the publishers
        $publishers = [];
        if ($publisherCode) {
            $publishers[$publisherCode] = [];
        } else {
            $publishers = $this->publishers;
        }

        $feeds = [];
        foreach ($publishers as $code => $publisher) {
            $feeds[] = $this->readAndPersistOne($code);
        }

        return $feeds;
    }

    public function readAndPersistOne($publisherCode) {

        $isCreation = true;
        $feed = $this->read($publisherCode);

        // Prepare persistence
        $doctrine = $this->container->get('doctrine');
        $repository = $doctrine->getRepository('AppBundle:Feed');
        $em = $doctrine->getManager();

        // Find existing Feed
        $previousFeed = $repository->findOneBy([
            'title' => (string) $feed->getTitle(),
            'created' => new \DateTime(),
            'publisher' => $publisherCode
        ]);
        if (!is_null($previousFeed)) {
            $isCreation = false;
            $previousFeed->hydrate($feed);
            $feed = $previousFeed;
        }

        // Persist
        $em->persist($feed);
        $em->flush($feed);

        $feed->_createdNow = $isCreation;

        return $feed;
    }



    /**
     * Parses an SimpleXML object to a Feed doctrine object
     *
     * @param $content - The SimpleXML object
     * @param $publisherCode - The publisher internal code. eg: elpais
     * @return Feed
     */
    public function parseToFeed($content, $publisherCode) {

        $feedArray = [];

        /**
         * TODO: Refactor
         * For now this works, but there is two mayor problems:
         * - We are choosing the first entry on the rss, but sometimes isn't the
         *  same of frontpage. We will need to actually read the DOM. Ouch :/
         * - This is a ugly way to do this per-publisher scraping.
         */
        if ($publisherCode == 'elpais') {

            $entry = $content->xpath('channel/item')[0];
            $feedArray['title'] = $entry->xpath('title')[0];
            $feedArray['body'] = $entry->xpath('description')[0];
            $feedArray['image'] = $entry->xpath('enclosure')/*[0]['url']*/;
            if (count($feedArray['image'])) {
                $feedArray['image'] = $feedArray['image'][0]['url'];
            } else {
                unset($feedArray['image']);
            }
            $feedArray['source'] = $entry->xpath('link')[0];

        } else if ($publisherCode == 'elmundo') {

            $entry = $content->xpath('channel/item')[0];
            $feedArray['title'] = $entry->xpath('title')[0];
            $feedArray['body'] = $entry->xpath('media:description')[0];
            $feedArray['image'] = $entry->xpath('media:content')[0]['url'];
            $feedArray['source'] = $entry->xpath('link')[0];

        } else if ($publisherCode == 'elconfidencial') {

            $entry = $content->entry[0];
            $feedArray['title'] = $entry->title;
            $feedArray['body'] = $entry->summary;
            $feedArray['image'] = $entry->link[1]['href'];
            $feedArray['source'] = $entry->link[0]['href'];

        } else if ($publisherCode == 'larazon') {

            $entry = $content->xpath('channel/item')[0];
            $feedArray['title'] = $entry->xpath('title')[0];
            $feedArray['body'] = $entry->xpath('subtitle')[0];
            $feedArray['image'] = $entry->xpath('media:content')[0]['url'];
            $feedArray['source'] = $entry->xpath('link')[0];

        } else if ($publisherCode == 'elperiodico') {

            $entry = $content->xpath('channel/item')[0];
            $feedArray['title'] = $entry->xpath('title')[0];
            $feedArray['body'] = $entry->xpath('description')[0];
            $dom = HtmlDomParser::str_get_html( (string) $feedArray['body'] );
            $images = $dom->find('img');
            if (count($images)) {
                reset($images);
                $image = $images[key($images)];
                $feedArray['image'] = $image->src;
            }
            $feedArray['source'] = $entry->xpath('link')[0];
        }

        // Convert to a Feed Entity
        $feed = new Feed();
        $feed->setTitle(trim((string) $feedArray['title']));

        // Body html fixed
        $body = trim((string) $feedArray['body']);
        $body = htmlspecialchars_decode($body); // Decode html
        $body = strip_tags($body);              // Delete it
        $body = htmlspecialchars_decode($body); // Redecode (sometimes there is br encoded)
        $feed->setBody($body);
        if (isset($feedArray['image'])) {
            $feed->setImage(trim((string) $feedArray['image']));
        }
        if (isset($feedArray['source'])) {
            $feed->setSource(trim((string) $feedArray['source']));
        }
        $feed->setPublisher($publisherCode);

        
        return $feed;
    }

    /**
     * Calls to the rss of a publisher and gets the rss SimpleXML object.
     *
     * @param $publisherCode - The publisher internal code. eg: elpais
     * @return \SimpleXMLElement
     * @throws \Exception
     */
    public function readRss($publisherCode) {
        if (!isset($this->publishers[$publisherCode])) {
            throw new \Exception("The publisher '$publisherCode' was not found.");
        }
        $rssUrl = $this->publishers[$publisherCode]['rss'];
        $content = file_get_contents($rssUrl);

        return $this->readXML($content);
    }

    /**
     * Converts a xml string to a SimpleXML object
     * @param $xmlstr - Input xml string
     * @return \SimpleXMLElement
     */
    public function readXML($xmlstr) {
        return new \SimpleXMLElement($xmlstr);
    }

    private function xpath($item, $path) {
        return (string) $item->xpath($path)[0];
    }

    private function removeNamespace($xml) {
        $sxe = new \SimpleXMLElement($xml);
        $dom_sxe = dom_import_simplexml($sxe);

        $dom = new \DOMDocument('1.0');
        $dom_sxe = $dom->importNode($dom_sxe, true);
        $dom_sxe = $dom->appendChild($dom_sxe);

        $element = $dom->childNodes->item(0);

    // See what the XML looks like before the transformation
        //echo "<pre>\n" . htmlspecialchars($dom->saveXML()) . "\n</pre>";
        foreach ($sxe->getDocNamespaces() as $name => $uri) {
            $element->removeAttributeNS($uri, $name);
        }
    // See what the XML looks like after the transformation
        return "<pre>\n" . htmlspecialchars($dom->saveXML()) . "\n</pre>";
    }

}






