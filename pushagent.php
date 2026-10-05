<?php
/**
 * Push Agent - web push notifications for Joomla 3.x to 6.x
 *
 * Adds the Push Agent script to every front-end page and creates the
 * /pushagent-sw.js service worker file in the site root automatically.
 *
 * @copyright  (C) 2026 Push Agent
 * @license    GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Registry\Registry;

// Joomla 3.0 - 3.7 don't have the namespaced class names (added in 3.8); map them to the old ones.
if (!class_exists('Joomla\\CMS\\Factory') && class_exists('JFactory'))
{
	class_alias('JFactory', 'Joomla\\CMS\\Factory');
}

if (!class_exists('Joomla\\CMS\\Language\\Text') && class_exists('JText'))
{
	class_alias('JText', 'Joomla\\CMS\\Language\\Text');
}

if (!class_exists('Joomla\\CMS\\Plugin\\CMSPlugin') && class_exists('JPlugin'))
{
	class_alias('JPlugin', 'Joomla\\CMS\\Plugin\\CMSPlugin');
}

if (!class_exists('Joomla\\Registry\\Registry') && class_exists('JRegistry'))
{
	class_alias('JRegistry', 'Joomla\\Registry\\Registry');
}

class PlgSystemPushagent extends CMSPlugin
{
	/** Marker the Push Agent app looks for when it verifies the service worker. */
	const SW_MARKER = 'pushagent-v2';
	const SW_FILE   = 'pushagent-sw.js';
	const DEFAULT_APP_URL = 'https://app.pushagent.net/';

	protected $autoloadLanguage = true;

	/**
	 * Inject the subscriber script just before </body> on front-end HTML pages.
	 */
	public function onAfterRender()
	{
		$app = Factory::getApplication();

		$isSite = method_exists($app, 'isClient') ? $app->isClient('site') : $app->isSite();

		if (!$isSite)
		{
			return;
		}

		$doc = method_exists($app, 'getDocument') ? $app->getDocument() : Factory::getDocument();

		if (!$doc || $doc->getType() !== 'html')
		{
			return;
		}

		$token = trim((string) $this->params->get('token', ''));

		if ($token === '')
		{
			return;
		}

		$appUrl = self::appUrl($this->params->get('app_url', self::DEFAULT_APP_URL));

		// Make sure the service worker exists (cheap check; rewritten only when missing or different)
		if ((int) $this->params->get('create_sw', 1) === 1)
		{
			self::writeServiceWorker($token, $appUrl, false);
		}

		$body = $app->getBody();

		// Don't add it twice if the site owner also pasted the snippet by hand
		if ($body === '' || stripos($body, $appUrl . 'embed.php') !== false)
		{
			return;
		}

		$pos = strripos($body, '</body>');

		if ($pos === false)
		{
			return;
		}

		$src = $appUrl . 'embed.php?t=' . rawurlencode($token) . '&delay=' . max(0, (int) $this->params->get('delay', 3));
		$tag = '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" async></script>' . "\n";

		$app->setBody(substr($body, 0, $pos) . $tag . substr($body, $pos));
	}

	/**
	 * When the plugin settings are saved: create/refresh pushagent-sw.js and tell the admin what happened.
	 */
	public function onExtensionAfterSave($context, $table, $isNew = false)
	{
		if ($context !== 'com_plugins.plugin' || !is_object($table)
			|| !isset($table->element, $table->folder) || $table->element !== 'pushagent' || $table->folder !== 'system')
		{
			return;
		}

		$this->loadLanguage();
		$app    = Factory::getApplication();
		$params = new Registry($table->params);
		$token  = trim((string) $params->get('token', ''));

		if ($token === '')
		{
			$app->enqueueMessage(Text::_('PLG_SYSTEM_PUSHAGENT_MSG_NO_TOKEN'), 'warning');

			return;
		}

		if ((int) $params->get('create_sw', 1) === 1)
		{
			$appUrl = self::appUrl($params->get('app_url', self::DEFAULT_APP_URL));

			if (self::writeServiceWorker($token, $appUrl, true))
			{
				$app->enqueueMessage(Text::_('PLG_SYSTEM_PUSHAGENT_MSG_SW_OK'), 'message');
			}
			else
			{
				$app->enqueueMessage(Text::sprintf('PLG_SYSTEM_PUSHAGENT_MSG_SW_FAIL', JPATH_ROOT), 'warning');
			}
		}

		if (isset($table->enabled) && (int) $table->enabled !== 1)
		{
			$app->enqueueMessage(Text::_('PLG_SYSTEM_PUSHAGENT_MSG_ENABLE'), 'notice');
		}
	}

	/**
	 * Normalise the app address: https, trailing slash.
	 */
	public static function appUrl($url)
	{
		$url = trim((string) $url);

		if ($url === '' || !preg_match('~^https?://~i', $url))
		{
			$url = self::DEFAULT_APP_URL;
		}

		return rtrim($url, '/') . '/';
	}

	/**
	 * The one-line service worker. Its logic is loaded from Push Agent, so future fixes need no re-upload.
	 */
	public static function serviceWorkerContent($token, $appUrl)
	{
		return "// Push Agent service worker - created by the Push Agent Joomla plugin. Do not delete.\n"
			. '// ' . self::SW_MARKER . "\n"
			. "importScripts('" . $appUrl . 'sw.php?t=' . rawurlencode($token) . "');\n";
	}

	/**
	 * Create or update JPATH_ROOT/pushagent-sw.js. Returns true when the file is correct afterwards.
	 */
	public static function writeServiceWorker($token, $appUrl, $force)
	{
		$file    = JPATH_ROOT . '/' . self::SW_FILE;
		$content = self::serviceWorkerContent($token, $appUrl);

		if (is_file($file) && filesize($file) === strlen($content))
		{
			if (!$force)
			{
				return true;
			}

			if (@file_get_contents($file) === $content)
			{
				return true;
			}
		}

		return @file_put_contents($file, $content, LOCK_EX) !== false;
	}
}
