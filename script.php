<?php
/**
 * Push Agent - install / uninstall script
 *
 * @copyright  (C) 2026 Push Agent
 * @license    GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

// Joomla 3.0 - 3.7 don't have the namespaced class names (added in 3.8); map them to the old ones.
if (!class_exists('Joomla\\CMS\\Factory') && class_exists('JFactory'))
{
	class_alias('JFactory', 'Joomla\\CMS\\Factory');
}

if (!class_exists('Joomla\\CMS\\Language\\Text') && class_exists('JText'))
{
	class_alias('JText', 'Joomla\\CMS\\Language\\Text');
}

class PlgSystemPushagentInstallerScript
{
	public function postflight($type, $parent)
	{
		if ($type !== 'install')
		{
			return true;
		}

		// Switch the plugin on right away. It stays idle until an access token is entered,
		// and being enabled lets it create pushagent-sw.js the moment the settings are saved.
		try
		{
			$db    = method_exists('Joomla\CMS\Factory', 'getContainer') ? Factory::getContainer()->get('DatabaseDriver') : Factory::getDbo();
			$query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
			$query->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 1')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
				->where($db->quoteName('element') . ' = ' . $db->quote('pushagent'));
			$db->setQuery($query)->execute();
		}
		catch (\Exception $e)
		{
			// Not critical: the user can still enable it in the Plugins list
		}

		$lang = method_exists(Factory::getApplication(), 'getLanguage') ? Factory::getApplication()->getLanguage() : Factory::getLanguage();
		$lang->load('plg_system_pushagent.sys', JPATH_ADMINISTRATOR, null, true, true);

		Factory::getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_PUSHAGENT_MSG_INSTALLED'), 'message');

		return true;
	}

	public function uninstall($parent)
	{
		// Remove the service worker only if this plugin created it
		$file = JPATH_ROOT . '/pushagent-sw.js';

		if (is_file($file) && strpos((string) @file_get_contents($file), 'pushagent-v2') !== false)
		{
			@unlink($file);
		}

		return true;
	}
}
