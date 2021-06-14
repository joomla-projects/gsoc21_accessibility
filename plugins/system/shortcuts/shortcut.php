<?php
// no direct access
defined( '_JEXEC' ) or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Language\Text;

/**
 * Shortcuts plugin to add accessible keyboard navigation to the site and keyboard shortcuts.
 *
 * @since  4.1
 */

class PlgSystemShortcut extends CMSPlugin
{

    protected $app;
    protected $_basePath = 'media/plg_system_shortcut';
    public function onBeforeCompileHead()
    {
        if ($this->app->isClient('administrator'))
        {
            $wa = $this->app->getDocument()->getWebAssetManager();

            if (!$wa->assetExists('script', 'shortcut'))
            {
                $wa->registerScript('shortcut', $this->_basePath . '/js/shortcut.js', [], ['defer' => true]);
            }
            $wa->useScript('shortcut');
            return true;
        }
        return true;
    }
}