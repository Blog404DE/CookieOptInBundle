<?php


namespace Netzhirsch\CookieOptInBundle\Blocker;


use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Connection;
use Netzhirsch\CookieOptInBundle\Classes\DataFromExternalMediaAndBar;
use Netzhirsch\CookieOptInBundle\Repository\BarRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use DOMDocument;
use DOMElement;

class ScriptBlocker
{
    /**
     * @param $buffer
     * @param Connection $conn
     * @param RequestStack $requestStack
     * @return string
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function script($buffer,Connection $conn,RequestStack $requestStack) {

        if (empty($requestStack))
            return $buffer;

        /**
         * Scripts von anderen HTML Tags trennen.
         * Scripts encoden und in Container div einbetten.
         * Andere HTML Tags einfach ans Return anhängen.
         */
        $newBuffer = '';
        $doc = new DOMDocument();
//        $clearBuffer = Blocker::clearHtmlComments($buffer);
        $doc->loadHTML($buffer);
        $htmlArray = self::getAllDOMElement($doc);
        foreach ($htmlArray as $html) {
            $newBuffer .= self::getScriptHTML($html,$requestStack,$conn,$buffer);
        }
        return $newBuffer;
    }

    /**
     * @param DOMElement $DOMElement
     * @param RequestStack $requestStack
     * @param Connection $conn
     * @param $buffer
     * @return null|string
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    private static function getScriptHTML(DOMElement $DOMElement, RequestStack $requestStack, Connection $conn,$buffer){

        $moduleData = Blocker::getModulData($requestStack);
        if (empty($moduleData))
            return $buffer;

        $src = self::getSrc($DOMElement);
        if (empty($src))
            return $buffer;

        $externalMediaCookiesInDB = Blocker::getExternalMediaByUrl($conn, $src);
        if (empty($externalMediaCookiesInDB))
            return $buffer;

        $dataFromExternalMediaAndBar = new DataFromExternalMediaAndBar();
        $dataFromExternalMediaAndBar = Blocker::getDataFromExternalMediaAndBar(
            $dataFromExternalMediaAndBar,$conn,$externalMediaCookiesInDB,$moduleData
        );
        $dataFromExternalMediaAndBar->setDisclaimer($externalMediaCookiesInDB[0]['i_frame_blocked_text']);
        $dataFromExternalMediaAndBar->setIFrameType('script');
        $barRepo = new BarRepository($conn);
        $blockText = $barRepo->loadBlockContainerTexts($dataFromExternalMediaAndBar->getModId());


        if (Blocker::isAllowed($dataFromExternalMediaAndBar))
            return $buffer;

        $size = [
            'height' => $DOMElement->getAttribute('height'),
            'width' => $DOMElement->getAttribute('width'),
        ];

        return Blocker::getHtmlContainer(
            $dataFromExternalMediaAndBar,
            $blockText,
            $size,
            $buffer
        );
    }

    private static function getSrc(DOMElement $DOMElement){
        $src = $DOMElement->getAttribute('src');
        if (!empty($src))
            return $src;

        $src = $DOMElement->getAttribute('data-ncoi-src');
        if (!empty($src))
            return $src;

        return null;
    }


    private static function getAllDOMElement($doc) {
        $domElementArray = [];
        $domElementArray = self::addToDomElementArray($doc,$domElementArray,'script');
        $domElementArray = self::addToDomElementArray($doc,$domElementArray,'link');
        return $domElementArray;
    }

    private static function addToDomElementArray($doc,$domElementArray,$tag) {
        $scriptElements = $doc->getElementsByTagName($tag);
        foreach ($scriptElements as $scriptElement) {
            $domElementArray[] = $scriptElement;
        }
        return $domElementArray;
    }
}