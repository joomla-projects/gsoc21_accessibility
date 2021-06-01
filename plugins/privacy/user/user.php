<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Privacy.user
 *
 * @copyright   (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\SessionManager;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\CMS\Table\User as JTableUser;
use Joomla\CMS\User\User;
use Joomla\Component\Privacy\Administrator\Plugin\PrivacyPlugin;
use Joomla\Component\Privacy\Administrator\Removal\Status;
use Joomla\Component\Privacy\Administrator\Table\RequestTable;
use Joomla\Database\ParameterType;
use Joomla\Utilities\ArrayHelper;

/**
 * Privacy plugin managing Joomla user data
 *
 * @since  3.9.0
 */
class PlgPrivacyUser extends PrivacyPlugin
{
	/**
	 * Application object
	 *
	 * @var    CMSApplicationInterface
	 * @since  4.0.0
	 */
	protected $app;

	/**
	 * Performs validation to determine if the data associated with a remove information request can be processed
	 *
	 * This event will not allow a super user account to be removed
	 *
	 * @param   RequestTable  $request  The request record being processed
	 * @param   User          $user     The user account associated with this request if available
	 *
	 * @return  Status
	 *
	 * @since   3.9.0
	 */
	public function onPrivacyCanRemoveData(RequestTable $request, User $user = null)
	{
		$status = new Status;

		if (!$user)
		{
			return $status;
		}

		if ($user->authorise('core.admin'))
		{
			$status->canRemove = false;
			$status->reason    = Text::_('PLG_PRIVACY_USER_ERROR_CANNOT_REMOVE_SUPER_USER');
		}

		return $status;
	}

	/**
	 * Processes an export request for Joomla core user data
	 *
	 * This event will collect data for the following core tables:
	 *
	 * - #__users (excluding the password, otpKey, and otep columns)
	 * - #__user_notes
	 * - #__user_profiles
	 * - User custom fields
	 *
	 * @param   RequestTable  $request  The request record being processed
	 * @param   User          $user     The user account associated with this request if available
	 *
	 * @return  \Joomla\Component\Privacy\Administrator\Export\Domain[]
	 *
	 * @since   3.9.0
	 */
	public function onPrivacyExportRequest(RequestTable $request, User $user = null)
	{
		if (!$user)
		{
			return array();
		}

		/** @var JTableUser $userTable */
		$userTable = User::getTable();
		$userTable->load($user->id);

		$domains = array();
		$domains[] = $this->createUserDomain($userTable);
		$domains[] = $this->createNotesDomain($userTable);
		$domains[] = $this->createProfileDomain($userTable);
		$domains[] = $this->createCustomFieldsDomain('com_users.user', array($userTable));

		return $domains;
	}

	/**
	 * Removes the data associated with a remove information request
	 *
	 * This event will pseudoanonymise the user account
	 *
	 * @param   RequestTable  $request  The request record being processed
	 * @param   User          $user     The user account associated with this request if available
	 *
	 * @return  void
	 *
	 * @since   3.9.0
	 */
	public function onPrivacyRemoveData(RequestTable $request, User $user = null)
	{
		// This plugin only processes data for registered user accounts
		if (!$user)
		{
			return;
		}

		$db = $this->db;

		$pseudoanonymisedData = [
			'name'      => 'User ID ' . $user->id,
			'username'  => bin2hex(random_bytes(12)),
			'email'     => 'UserID' . $user->id . 'removed@email.invalid',
			'block'     => true,
		];

		$user->bind($pseudoanonymisedData);

		$user->save();

		// Destroy all sessions for the user account if able
		if (!$this->app->get('session_metadata', true))
		{
			return;
		}

		try
		{
			$userId = (int) $user->id;

			$sessionIds = $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('session_id'))
					->from($this->db->quoteName('#__session'))
					->where($this->db->quoteName('userid') . ' = :userid')
					->bind(':userid', $userId, ParameterType::INTEGER)
			)->loadColumn();
		}
		catch (ExecutionFailureException $e)
		{
			return;
		}

		// If there aren't any active sessions then there's nothing to do here
		if (empty($sessionIds))
		{
			return;
		}

		/** @var SessionManager $sessionManager */
		$sessionManager = Factory::getContainer()->get('session.manager');
		$sessionManager->destroySessions($sessionIds);

		try
		{
			$this->db->setQuery(
				$this->db->getQuery(true)
					->delete($this->db->quoteName('#__session'))
					->whereIn($this->db->quoteName('session_id'), $sessionIds, ParameterType::LARGE_OBJECT)
			)->execute();
		}
		catch (ExecutionFailureException $e)
		{
			// No issue, let things go
		}
	}

	/**
	 * Create the domain for the user notes data
	 *
	 * @param   JTableUser  $user  The JTableUser object to process
	 *
	 * @return  \Joomla\Component\Privacy\Administrator\Export\Domain
	 *
	 * @since   3.9.0
	 */
	private function createNotesDomain(JTableUser $user)
	{
		$domain = $this->createDomain('user_notes', 'joomla_user_notes_data');
		$db     = $this->db;

		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__user_notes'))
			->where($db->quoteName('user_id') . ' = :userid')
			->bind(':userid', $user->id, ParameterType::INTEGER);

		$items = $db->setQuery($query)->loadAssocList();

		// Remove user ID columns
		foreach (['user_id', 'created_user_id', 'modified_user_id'] as $column)
		{
			$items = ArrayHelper::dropColumn($items, $column);
		}

		foreach ($items as $item)
		{
			$domain->addItem($this->createItemFromArray($item, $item['id']));
		}

		return $domain;
	}

	/**
	 * Create the domain for the user profile data
	 *
	 * @param   JTableUser  $user  The JTableUser object to process
	 *
	 * @return  \Joomla\Component\Privacy\Administrator\Export\Domain
	 *
	 * @since   3.9.0
	 */
	private function createProfileDomain(JTableUser $user)
	{
		$domain = $this->createDomain('user_profile', 'joomla_user_profile_data');
		$db     = $this->db;

		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__user_profiles'))
			->where($db->quoteName('user_id') . ' = :userid')
			->order($db->quoteName('ordering') . ' ASC')
			->bind(':userid', $user->id, ParameterType::INTEGER);

		$items = $db->setQuery($query)->loadAssocList();

		foreach ($items as $item)
		{
			$domain->addItem($this->createItemFromArray($item));
		}

		return $domain;
	}

	/**
	 * Create the domain for the user record
	 *
	 * @param   JTableUser  $user  The JTableUser object to process
	 *
	 * @return  \Joomla\Component\Privacy\Administrator\Export\Domain
	 *
	 * @since   3.9.0
	 */
	private function createUserDomain(JTableUser $user)
	{
		$domain = $this->createDomain('users', 'joomla_users_data');
		$domain->addItem($this->createItemForUserTable($user));

		return $domain;
	}

	/**
	 * Create an item object for a JTableUser object
	 *
	 * @param   JTableUser  $user  The JTableUser object to convert
	 *
	 * @return  \Joomla\Component\Privacy\Administrator\Export\Item
	 *
	 * @since   3.9.0
	 */
	private function createItemForUserTable(JTableUser $user)
	{
		$data    = [];
		$exclude = ['password', 'otpKey', 'otep'];

		foreach (array_keys($user->getFields()) as $fieldName)
		{
			if (!in_array($fieldName, $exclude))
			{
				$data[$fieldName] = $user->$fieldName;
			}
		}

		return $this->createItemFromArray($data, $user->id);
	}
}
