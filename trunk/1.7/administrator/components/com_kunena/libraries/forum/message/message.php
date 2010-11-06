<?php
/**
 * @version $Id: message.php 3759 2010-10-20 13:48:28Z mahagr $
 * Kunena Component - KunenaForumMessage Class
 * @package Kunena
 *
 * @Copyright (C) 2010 www.kunena.com All rights reserved
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.com
 **/
defined ( '_JEXEC' ) or die ();

jimport ('joomla.user.helper');
jimport ('joomla.mail.helper');
kimport ('kunena.error');
kimport ('kunena.user.helper');
kimport ('kunena.forum.category.helper');
kimport ('kunena.forum.topic.helper');
kimport ('kunena.forum.message.helper');
kimport ('kunena.forum.message.attachment');
kimport ('kunena.forum.message.attachment.helper');

/**
 * Kunena Forum Message Class
 */
class KunenaForumMessage extends JObject {
	protected $_exists = false;
	protected $_db = null;
	protected $_attachments_add = array();
	protected $_attachments_del = array();
	protected $_topic = null;
	protected $_hold = 1;

	/**
	 * Constructor
	 *
	 * @access	protected
	 */
	public function __construct($identifier = 0) {
		// Always load the message -- if message does not exist: fill empty data
		$this->_db = JFactory::getDBO ();
		$this->load ( $identifier );
	}

	/**
	 * Returns KunenaForumMessage object
	 *
	 * @access	public
	 * @param	identifier		The message to load - Can be only an integer.
	 * @return	KunenaForumMessage		The message object.
	 * @since	1.7
	 */
	static public function getInstance($identifier = null, $reload = false) {
		return KunenaForumMessageHelper::get($identifier, $reload);
	}

	function exists($exists = null) {
		$return = $this->_exists;
		if ($exists !== null) $this->_exists = $exists;
		return $return;
	}

	public function newReply($fields=array(), $user=null) {
		$user = KunenaUserHelper::get($user);
		$topic = $this->getTopic();
		$category = $this->getCategory();

		$message = new KunenaForumMessage();
		$message->setTopic($topic);
		$message->parent = $this->id;
		$message->thread = $topic->id;
		$message->catid = $topic->category_id;
		$message->name = $user->getName('');
		$message->userid = $user->userid;
		$message->subject = $this->subject;
		$message->ip = $_SERVER ["REMOTE_ADDR"];
		$message->hold = $category->review ? (int)!$category->authorise ('moderate', $user, true) : 0;
		if ($fields === true) {
			$text = preg_replace('/\[confidential\](.*?)\[\/confidential\]/su', '', $this->message );
			$message->message = "[quote=\"{$this->name}\" post={$this->id}]" .  $text . "[/quote]";
		} elseif (is_array($fields)) {
			$message->bind($fields, array ('name', 'email', 'subject', 'message' ));
		}
		return array($topic, $message);
	}

	public function sendNotification($url=null) {
		// TODO: if hold=>1, always send message to moderators and never to subscribers
		// TODO: if hold=>0, always send message to subscribers
	}

	public function publish($value=KunenaForum::PUBLISHED) {
		if ($this->hold == $value)
			return true;

		$this->hold = (int)$value;
		$result = $this->save();
		return $result;
	}

	public function getTopic() {
		if (!$this->_topic) {
			$this->_topic = KunenaForumTopicHelper::get($this->thread);
		}
		return $this->_topic;
	}

	public function setTopic($topic) {
		$this->_topic = $topic;
	}

	public function getCategory() {
		return KunenaForumCategoryHelper::get($this->catid);
	}

	public function authorise($action='read', $user=null, $silent=false) {
		static $actions  = array(
			'read'=>array('Read'),
			'reply'=>array('Read','NotHold'),
			'edit'=>array('Read','Own','EditTime'),
			'move'=>array('Read'),
			'approve'=>array('Read'),
			'delete'=>array('Read','Own','EditTime'),
			'undelete'=>array('Read'),
			'permdelete'=>array('Read'),
			'attachment.read'=>array('Read'),
			'attachment.create'=>array('Read','Own','EditTime'),
			'attachment.delete'=>array('Read','Own','EditTime'),
		);
		$user = KunenaUser::getInstance($user);
		if (!isset($actions[$action])) {
			if (!$silent) $this->setError ( JText::_ ( 'COM_KUNENA_LIB_MESSAGE_NO_ACTION' ) );
			return false;
		}
		$topic = $this->getTopic();
		$auth = $topic->authorise('post.'.$action, $user, $silent);
		if (!$auth) {
			if (!$silent) $this->setError ( $topic->getError() );
			return false;
		}
		foreach ($actions[$action] as $function) {
			$authFunction = 'authorise'.$function;
			if (! method_exists($this, $authFunction) || ! $this->$authFunction($user)) {
				if (!$silent) $this->setError ( JText::_ ( 'COM_KUNENA_NO_ACCESS' ) );
				return false;
			}
		}
		return true;
	}

	public function edit($fields = array(), $user=null) {
		$category = $this->getCategory();
		$user = KunenaUserHelper::get($user);

		$this->bind($fields, array ('name', 'email', 'subject', 'message', 'modified_reason' ));

		// Update rest of the information
		$this->hold = $category->review ? (int)!$category->authorise ('moderate', $user, true) : 0;
		$this->modified_by = $user->userid;
		$this->modified_time = JFactory::getDate()->toUnix();
	}

	public function makeAnonymous($user=null) {
		$user = KunenaUserHelper::get($user);
		if ($user->userid == $this->userid && $this->modified_by == $this->userid) {
			// I am the author and previous modification was made by me => delete modification information to hide my personality
			$this->modified_by = 0;
			$this->modified_time = 0;
			$this->modified_reason = '';
		} else if ($user->userid == $this->userid) {
			// I am the author, but somebody else has modified the message => leave modification information intact
			$this->modified_by = null;
			$this->modified_time = null;
			$this->modified_reason = null;
		}
		// Remove userid, email and ip address
		$this->userid = 0;
		$this->ip = '';
		$this->email = '';
	}

	public function uploadAttachment($tmpid, $postvar) {
		$attachment = new KunenaForumMessageAttachment();
		$attachment->mesid = $this->id;
		$attachment->userid = $this->userid;
		$success = $attachment->upload($postvar);
		$this->_attachments_add[$tmpid] = $attachment;
		return $success;
	}

	public function removeAttachment($ids=false) {
		if ($ids === false) {
			$this->_attachments_del = $this->getAttachments();
		} elseif (is_array($ids)) {
			$this->_attachments_del += array_combine($ids, $ids);
		} else {
			$this->_attachments_del[$ids] = $ids;
		}
	}

	public function getAttachments($ids=false) {
		if ($ids === false) {
			return KunenaForumMessageAttachmentHelper::getByMessage($this->id);
		} else {
			return KunenaForumMessageAttachmentHelper::getById($ids);
		}
	}

	protected function updateAttachments() {
		// Save new attachments and update message text
		foreach ($this->_attachments_add as $tmpid=>$attachment) {
			$attachment->mesid = $this->id;
			if (!$attachment->save()) {
				$this->setError ( $attachment->getError() );
			}
			// Fix attachments names inside message
			if ($attachment->exists()) {
				$this->message = preg_replace('/\[attachment\:'.$tmpid.'\].*?\[\/attachment\]/u', "[attachment={$attachment->id}]{$attachment->filename}[/attachment]", $this->message);
			}
		}
		// Delete removed attachments and update message text
		$attachments = $this->getAttachments(array_keys($this->_attachments_del));
		foreach ($attachments as $attachment) {
			if (!$attachment->delete()) {
				$this->setError ( $attachment->getError() );
			}
			$this->message = preg_replace('/\[attachment\='.$attachment->id.'\].*?\[\/attachment\]/u', '', $this->message);
			$this->message = preg_replace('/\[attachment\]'.$attachment->filename.'\[\/attachment\]/u', '', $this->message);
		}
		// Remove missing temporary attachments from the message text
		$this->message = trim(preg_replace('/\[attachment\:\d+\].*?\[\/attachment\]/u', '', $this->message));
	}

	/**
	 * Method to get the messages table object
	 *
	 * This function uses a static variable to store the table name of the user table to
	 * it instantiates. You can call this function statically to set the table name if
	 * needed.
	 *
	 * @access	public
	 * @param	string	The messages table name to be used
	 * @param	string	The messages table prefix to be used
	 * @return	object	The messages table object
	 * @since	1.6
	 */
	public function getTable($type = 'KunenaMessages', $prefix = 'Table') {
		static $tabletype = null;

		//Set a custom table type is defined
		if ($tabletype === null || $type != $tabletype ['name'] || $prefix != $tabletype ['prefix']) {
			$tabletype ['name'] = $type;
			$tabletype ['prefix'] = $prefix;
		}

		// Create the user table object
		return JTable::getInstance ( $tabletype ['name'], $tabletype ['prefix'] );
	}

	public function bind($data, $allow = array()) {
		if (!empty($allow)) $data = array_intersect_key($data, array_flip($allow));
		$this->setProperties ( $data );
	}

	/**
	 * Method to load a KunenaForumMessage object by id
	 *
	 * @access	public
	 * @param	mixed	$id The message id to be loaded
	 * @return	boolean			True on success
	 * @since 1.6
	 */
	public function load($id) {
		// Create the table object
		$table = $this->getTable ();

		// Load the KunenaTable object based on id
		$this->_exists = $table->load ( $id );

		// Assuming all is well at this point lets bind the data
		$this->setProperties ( $table->getProperties () );
		$this->_hold = $this->hold === null ? 1 : $this->hold;
		return $this->_exists;
	}

	/**
	 * Method to save the KunenaForumMessage object to the database
	 *
	 * @access	public
	 * @param	boolean $updateOnly Save the object only if not a new message
	 * @return	boolean True on success
	 * @since 1.6
	 */
	public function save($updateOnly = false) {
		//are we creating a new message
		$isnew = ! $this->_exists;

		// If we aren't allowed to create new message return
		if ($isnew && $updateOnly) {
			$this->setError ( JText::_('COM_KUNENA_LIB_MESSAGE_ERROR_UPDATE_ONLY') );
			return false;
		}

		if (! $this->check ()) {
			return false;
		}

		$postDelta = $this->postDelta();

		// Create the messages table object
		$table = $this->getTable ();
		$table->bind ( $this->getProperties () );
		$table->exists ( $this->_exists );

		// Check and store the object.
		if (! $table->check ()) {
			$this->setError ( $table->getError () );
			return false;
		}

		// In case we are creating new message, we need to save and load it first
		if ($isnew) {
			if (!$table->store ()) {
				$this->setError ( $table->getError () );
				return false;
			}
			$this->load ( $table->get ( 'id' ) );
		}

		// Update topic
		$topic = $this->getTopic();
		if (! $topic->update($this, $postDelta)) {
			$this->setError ( $topic->getError () );
		}

		$this->thread = $topic->id;

		// Update attachments and message text (allowed to fail)
		$this->updateAttachments();

		// Store the message data in the database
		$table->bind ( $this->getProperties () );
		if (! $result = $table->store ()) {
			$this->setError ( $table->getError () );
		}

		return $result;
	}

	/**
	 * Method to delete the KunenaForumMessage object from the database
	 *
	 * @access	public
	 * @return	boolean	True on success
	 * @since 1.6
	 */
	public function delete() {
		if (!$this->exists()) {
			return true;
		}

		// Create the table object
		$table = $this->getTable ();

		$result = $table->delete ( $this->id );
		if (! $result) {
			$this->setError ( $table->getError () );
			return false;
		}
		$this->_exists = false;
		$this->hold = 1;

		$db = JFactory::getDBO ();
		// Delete attacments
		require_once KPATH_SITE.'/lib/kunean.attachments.class.php';
		CKunenaAttachments::deleteMessage($this->id);
		// Delete thank yous
		$queries[] = "DELETE FROM #__kunena_thankyou WHERE postid={$db->quote($this->id)}";
		// Delete message
		$queries[] = "DELETE FROM #__kunena_messages_text WHERE mesid={$db->quote($this->id)}";

		if ($this->_hold == 0) {
			$queries[] = "UPDATE #__kunena_users SET posts=posts-1 WHERE userid={$db->quote($this->userid)}";
			$queries[] = "UPDATE #__kunena_user_topics SET posts=posts-1 WHERE user_id={$db->quote($this->userid)} AND topic_id={$db->quote($this->thread)}";
			$this->getTopic()->update($this, -1);
			// TODO: event missing
		}

		foreach ($queries as $query) {
			$db->setQuery($query);
			$db->query();
			KunenaError::checkDatabaseError ();
		}

		return $result;
	}

	// Internal functions

	protected function authoriseRead($user) {
		// Check that user has the right to see the post (user can see his own unapproved posts)
		if ($this->hold > 1 || ($this->hold == 1 && $this->userid != $user->userid)) {
			$access = KunenaFactory::getAccessControl();
			$hold = $access->getAllowedHold($user->userid, $this->catid, false);
			if (!in_array($this->hold, $hold)) {
				$this->setError ( JText::_ ( 'COM_KUNENA_NO_ACCESS' ) );
				return false;
			}
		}
		return true;
	}
	protected function authoriseNotHold($user) {
		if ($this->hold) {
			// Nobody can reply to unapproved or deleted post
			$this->setError ( JText::_ ( 'COM_KUNENA_NO_ACCESS' ) );
			return false;
		}
		return true;
	}
	protected function authoriseOwn($user) {
		// Check that topic owned by the user or user is a moderator
		// TODO: check #__kunena_user_topics
		if ((!$this->userid || $this->userid != $user->userid) && !$user->isModerator($this->catid)) {
			$this->setError ( JText::_ ( 'COM_KUNENA_POST_EDIT_NOT_ALLOWED' ) );
			return false;
		}
		return true;
	}
	protected function authoriseEditTime($user) {
		// Do not perform rest of the checks to moderators and admins
		if ($user->isModerator($this->catid)) {
			return true;
		}
		// User is only allowed to edit post within time specified in the configuration
		if (! CKunenaTools::editTimeCheck ( $this->modified_time, $this->time )) {
			$this->setError ( JText::_ ( 'COM_KUNENA_POST_EDIT_NOT_ALLOWED' ) );
			return false;
		}
		return true;
	}

	protected function check() {
		$author = KunenaUserHelper::get($this->userid);

		// Check username
		if (! $this->userid) {
			$this->name = trim($this->name);
			// Unregistered or anonymous users: Do not allow existing username
			$nicktaken = JUserHelper::getUserId ( $this->name );
			if (empty ( $this->name ) || $nicktaken) {
				$this->name = JText::_ ( 'COM_KUNENA_USERNAME_ANONYMOUS' );
			}
		} else {
			$this->name = $author->getName();
		}

		// Check email address
		$this->email = trim($this->email);
		if ($this->email) {
			// Email address must be valid
			if (! JMailHelper::isEmailAddress ( $this->email )) {
				// FIXME: add language string
				$this->setError ( JText::_ ( 'COM_KUNENA_LIB_ERROR_MESSAGE_EMAIL_INVALID' ) );
				return false;
			}
		} else if (! KunenaFactory::getUser()->userid && KunenaFactory::getConfig()->askemail) {
			// FIXME: add language string
			$this->setError ( JText::_ ( 'COM_KUNENA_LIB_ERROR_MESSAGE_EMAIL_EMPTY' ) );
			return false;
		}

		$this->subject = trim($this->subject);
		if (!$this->subject) {
			// FIXME: add language string
			$this->setError ( JText::_ ( 'COM_KUNENA_LIB_ERROR_MESSAGE_SUBJECT_EMPTY' ) );
			return false;
		}
		$this->message = trim($this->message);
		if (!$this->message) {
			// FIXME: add language string
			$this->setError ( JText::_ ( 'COM_KUNENA_LIB_ERROR_MESSAGE_TEXT_EMPTY' ) );
			return false;
		}
		if (!$this->time) {
			$this->time = JFactory::getDate()->toUnix();
		}
		if ($this->hold < 0 || $this->hold > 3) {
			// FIXME: add language string
			$this->setError ( JText::_ ( 'COM_KUNENA_LIB_ERROR_MESSAGE_HOLD_INVALID' ) );
			return false;
		}
		if ($this->modified_by !== null) {
			if (!$this->modified_by) {
				$this->modified_time = 0;
				$this->modified_reason = '';
			} elseif (!$this->modified_time) {
				$this->modified_time = JFactory::getDate()->toUnix();
			}
		}
		return true;
	}

	protected function postDelta() {
		if (!$this->hold && $this->_hold) {
			// Create or publish message
			return 1;
		} elseif ($this->hold && !$this->_hold) {
			// Delete or unpublish message
			return -1;
		}
		return 0;
	}
}