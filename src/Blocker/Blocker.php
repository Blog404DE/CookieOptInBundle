<?php


namespace Netzhirsch\CookieOptInBundle\Blocker;


use Contao\LayoutModel;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception;
use Netzhirsch\CookieOptInBundle\Classes\DataFromExternalMediaAndBar;
use Netzhirsch\CookieOptInBundle\EventListener\PageLayoutListener;
use Netzhirsch\CookieOptInBundle\Repository\BarRepository;
use Netzhirsch\CookieOptInBundle\Repository\ToolRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class Blocker
{

    public static function getModulData(RequestStack $requestStack) {
        $moduleData = [];
        $attributes = $requestStack->getCurrentRequest()->attributes;
        if (empty($attributes))
            return $moduleData;

        /**
         * Aus dem PageModel die ModulIds finden, damit diese mit dem PID der CookieBar verglichen werden kann.
         * Stimmt die PID ,so ist diese Cookiebar für dieses Modul gedacht.
         */
        /** @var PageModel $pageModel */
        $pageModel = $attributes->get('pageModel');
        // Contao 4.4
        if (empty($pageModel))
            $pageModel = $GLOBALS['objPage'];

        // Achtung moduleData enthält nur die ID
        $layout = LayoutModel::findById($pageModel->layout);
        // Achtung moduleData enthält die ID, col, enable
        $moduleData = StringUtil::deserialize($layout->modules);

        $moduleInPage = PageLayoutListener::checkModules($pageModel, [], []);
        foreach ($moduleInPage as $modulInPage) {
            if (isset($modulInPage['moduleIds']))
                $moduleData[] = ['mod' => $modulInPage['moduleIds']];
            else
                $moduleData[] = ['mod' => $modulInPage[0]];
        }
        $moduleInContent = PageLayoutListener::getModuleIdFromInsertTag($pageModel, $layout);
        $moduleData[] = ['mod' => $moduleInContent['moduleIds']];

        return $moduleData;
    }

    public static function isAllowed(DataFromExternalMediaAndBar $dataFromExternalMediaAndBar){
        if (
            isset($_SESSION)
            && isset($_SESSION['_sf2_attributes'])
            && isset($_SESSION['_sf2_attributes']['ncoi'])
            && isset($_SESSION['_sf2_attributes']['ncoi']['cookieIds'])
        ) {
            $cookieIds = $_SESSION['_sf2_attributes']['ncoi']['cookieIds'];
            foreach ($dataFromExternalMediaAndBar->getCookieIds() as $id) {
                if (in_array($id,$cookieIds)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param DataFromExternalMediaAndBar $dataFromExternalMediaAndBar
     * @param Connection $conn
     * @param array $externalMediaCookiesInDB
     * @param $moduleData
     * @return DataFromExternalMediaAndBar
     */
    public static function getDataFromExternalMediaAndBar(DataFromExternalMediaAndBar $dataFromExternalMediaAndBar, Connection $conn,$externalMediaCookiesInDB,$moduleData)
    {
        global $objPage;
        $provider = $objPage->rootTitle;
        $dataFromExternalMediaAndBar->setProvider($provider);

        $barRepo = new BarRepository($conn);
        $cookieBars = $barRepo->findAll();
        foreach ($cookieBars as $cookieBar) {
            foreach ($moduleData as $moduleId) {
                if ($cookieBar['pid'] == $moduleId['mod']) {
                    foreach ($externalMediaCookiesInDB as $externalMediaCookieInDB) {
                        if ($cookieBar['pid'] == $externalMediaCookieInDB['pid']) {
                            $dataFromExternalMediaAndBar
                                ->addBlockedIFrames($externalMediaCookieInDB['cookieToolsSelect']);

                            $dataFromExternalMediaAndBar->addCookieId($externalMediaCookieInDB['id']);

                            if (!empty($externalMediaCookieInDB['cookieToolsProvider'])) {
                                $dataFromExternalMediaAndBar
                                    ->setProvider($externalMediaCookieInDB['cookieToolsProvider']);
                            }
                            $dataFromExternalMediaAndBar->setModId($cookieBar['pid']);

                            $privacyPolicyLink = '';
                            if (!empty($externalMediaCookieInDB['cookieToolsPrivacyPolicyUrl'])) {
                                $privacyPolicyLink = $externalMediaCookieInDB['cookieToolsPrivacyPolicyUrl'];

                            }
                            elseif (!empty(PageModel::findById($cookieBar['privacyPolicy']))) {
                                $privacyPolicyLink = PageModel::findById($cookieBar['privacyPolicy']);
                                $privacyPolicyLink = $privacyPolicyLink->getFrontendUrl();
                            }
                            $dataFromExternalMediaAndBar
                                ->setPrivacyPolicyLink($privacyPolicyLink);

                            $disclaimer = $externalMediaCookieInDB['i_frame_blocked_text'];
                            if (!empty($disclaimer))
                                $dataFromExternalMediaAndBar->setDisclaimer($disclaimer);

                        }
                    }
                }
            }
        }

        return $dataFromExternalMediaAndBar;
    }

    /**
     * @param Connection $conn
     * @param $url
     * @return mixed[]
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public static function getExternalMediaByUrl(Connection $conn, $url) {
        $toolRepo = new ToolRepository($conn);
        $topLevelPosition = strpos($url,'.com');
        $url = substr($url,0,$topLevelPosition);
        $urlArray = explode('.',$url);
        $secondLevel = $urlArray[array_key_last($urlArray)];
        return $toolRepo->findByUrl($secondLevel);
    }

    /**
     * @param $iframeHTML
     * @param Connection $conn
     * @param null $type
     * @return array
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public static function getExternalMediaByType($iframeHTML,Connection $conn,$type = null){

        $toolRepo = new ToolRepository($conn);

        if (empty($type))
            $type = self::getIFrameType($iframeHTML);
        if (empty($type))
            return [];

        return $toolRepo->findByType($type);
    }

    public static function getIFrameType($iframeHTML){

        $type = 'iframe';
        //Type des iFrames suchen damit danach in der Datenbank gesucht werden kann
        if (strpos($iframeHTML, 'youtube') !== false || strpos($iframeHTML, 'youtu.be') !== false) {
            $type = 'youtube';
        }elseif (strpos($iframeHTML, 'player.vimeo') !== false) {
            $type = 'vimeo';
        }elseif (strpos($iframeHTML, 'google.com/maps') || strpos($iframeHTML, 'maps.google') !== false) {
            $type = 'googleMaps';
        }

        return $type;
    }

    public static function getHtmlContainer(
        DataFromExternalMediaAndBar $dataFromExternalMediaAndBar,
        $blockTexts,
        $disclaimerString,
        $height,
        $width,
        $html,
        $iconPath = ''
    ) {

        $cookieIds = $dataFromExternalMediaAndBar->getCookieIds();
        $privacyPolicyLink = $dataFromExternalMediaAndBar->getPrivacyPolicyLink();
        $provider = $dataFromExternalMediaAndBar->getProvider();

        //eigene Container immer mit ausgeben, damit über JavaScript .ncoi---hidden setzten kann.
        $htmlDisclaimer = '<div class="ncoi---blocked-disclaimer">';
        $htmlIcon = '';

        $disclaimerString = str_replace('{{provider}}','<a href="'.$privacyPolicyLink.'" target="_blank">'.$provider.'</a>',$disclaimerString);
        $htmlDisclaimer .= $disclaimerString;

        $id = uniqid();
        $iframeTypInHtml = $dataFromExternalMediaAndBar->getIFrameType();
        $blockClass = $dataFromExternalMediaAndBar->getIFrameType();
        switch($iframeTypInHtml) {
            case 'youtube':
                $htmlIcon = '<div class="ncoi---blocked-icon"><img alt="youtube" src="' . $iconPath . 'youtube-brands.svg"></div>';
                $htmlReleaseAll = '<input id="'.$id.'" type="checkbox" name="'.$blockClass.'" class="ncoi---sliding ncoi---blocked" data-block-class="'.$blockClass.'"><label for="'.$id.'" class="ncoi--release-all ncoi---sliding ncoi---hidden"><i></i><span>YouTube '.$blockTexts['i_frame_always_load'].'</span></label>';
                break;
            case 'googleMaps':
                $htmlIcon = '<div class="ncoi---blocked-icon"><img alt="map-marker" src="' . $iconPath . 'map-marker-alt-solid.svg"></div>';
                $htmlReleaseAll = '<input id="'.$id.'" name="'.$blockClass.'" type="checkbox" class="ncoi---sliding ncoi---blocked" data-block-class="'.$blockClass.'"><label for="'.$id.'" class="ncoi--release-all ncoi---sliding ncoi---hidden"><i></i><span>Google Maps '.$blockTexts['i_frame_always_load'].'</span></label>';
                break;
            case 'vimeo':
                $htmlIcon = '<div class="ncoi---blocked-icon"><img alt="map-marker" src="' . $iconPath . 'vimeo-v-brands.svg"></div>';
                $htmlReleaseAll = '<input id="'.$id.'" name="'.$blockClass.'" type="checkbox" class="ncoi---sliding ncoi---blocked--vimeo" data-block-class="'.$blockClass.'"><label for="'.$id.'" class="ncoi--release-all ncoi---sliding ncoi---hidden"><i></i><span>Vimeo '.$blockTexts['i_frame_always_load'].'</span></label>';
                break;
            case 'iframe':
                $htmlReleaseAll = '<input id="'.$id.'" name="'.$blockClass.'" type="checkbox" class="ncoi---sliding ncoi---blocked" data-block-class="'.$blockClass.'"><label for="'.$id.'" class="ncoi--release-all ncoi---sliding ncoi---hidden"><i></i><span>iFrames '.$blockTexts['i_frame_always_load'].'</span></label>';
                break;
            case 'script':
                $htmlReleaseAll = '<input id="'.$id.'" name="'.$blockClass.'" type="checkbox" class="ncoi---sliding ncoi---blocked" data-block-class="script"><label for="'.$id.'" class="ncoi--release-all ncoi---sliding ncoi---hidden"><i></i><span>Script '.$blockTexts['i_frame_always_load'].'</span></label>';
                break;
        }

        $htmlDisclaimer .= '</div>';


        //$blockclass im JS um blocked Container ein. und auszublenden
        $class = 'ncoi---blocked ncoi---iframes ncoi---'.$blockClass;
        if (!empty($cookieIds)) {
            if (count($cookieIds) == 1) {
                $class .= ' ncoi---cookie-id-'.$cookieIds[0];
            } else {
                $class .= implode(' ncoi---cookie-id-',$cookieIds);
            }
        }


        if(!self::hasUnit($width))
            $width .= 'px';
        if(!self::hasUnit($height))
            $height .= 'px';

        //Umschliedender Container damit Kinder zentiert werden könne
        $htmlContainer = '<div class="'.$class.'" style="height:' . $height . '; width:' . $width . '" >';
        $htmlContainerEnd = '</div>';

        //Container für alle Inhalte
        $htmlConsentBox = '<div class="ncoi---consent-box">';
        $htmlConsentBoxEnd = '</div>';

        $htmlForm = '<form action="/cookie/allowed/iframe" method="post">';
        $htmlFormEnd = '</form>';
        //Damit JS das iFrame wieder laden kann
        $htmlConsentButton = '<div class="ncoi---blocked-link"><button type="submit" name="iframe" value="'.$iframeTypInHtml.'" class="ncoi---release">';

        $htmlConsentButtonEnd = '<span>' . $blockTexts['i_frame_load'].'</span></button></div>';
        $htmlInputCurrentPage = '<input class="ncoi---no-script--hidden" type="text" name="currentPage" value="'.$_SERVER['REDIRECT_URL'].'">';
        $htmlInputModID = '<input class="ncoi---no-script--hidden" type="text" name="data[modId]" value="'.$dataFromExternalMediaAndBar->getModId().'">';

        //Damit JS das iFrame wieder von base64 in ein HTML iFrame umwandel kann.
        $iframe = '<script type="text/template">' . base64_encode($html) . '</script>';

        return $htmlContainer  .$htmlConsentBox . $htmlDisclaimer . $htmlForm . $htmlConsentButton . $htmlIcon . $htmlConsentButtonEnd . $htmlInputCurrentPage .$htmlInputModID .$htmlFormEnd  .$htmlReleaseAll . $htmlConsentBoxEnd . $iframe .$htmlContainerEnd;
    }

    public static function hasUnit($html)
    {
        $units = [
            'px',
            '%',
            'em',
            'rem',
            'vw',
            'vh',
            'vmin',
            'vmax',
            'ex',
            'pt',
            'pc',
            'in',
            'cm',
            'mm',
        ];
        foreach ($units as $unit) {
            if (strpos($html,$unit) !== false)
                return true;

        }
        return false;
    }
}