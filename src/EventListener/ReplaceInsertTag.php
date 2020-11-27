<?php


namespace Netzhirsch\CookieOptInBundle\EventListener;



use Doctrine\DBAL\DBALException;

class ReplaceInsertTag
{
    /**
     * @param $insertTag
     * @return mixed
     * @throws DBALException
     * @throws \Exception
     */
    public function onReplaceInsertTagsListener($insertTag)
    {
        global $objPage;
        if (PageLayoutListener::shouldRemoveModules($objPage)) {
            $modIdsInBuffer = PageLayoutListener::getModuleIdFromHtmlElement($insertTag);
            if (!empty($modIdsInBuffer)) {
                $return = PageLayoutListener::findCookieModuleByPid($modIdsInBuffer);
                if (!empty($return)) {
                    $cookieBarId = $return['pid'];
                    $insertTag = str_replace('{{insert_module::'.$cookieBarId.'}}','',$insertTag);
                }
            }
        }

        return $insertTag;
    }
}