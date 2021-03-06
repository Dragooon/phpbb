<?php
/**
*
* @package phpBB3
* @copyright (c) 2013 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace phpbb\feed;

/**
* Forum feed
*
* This will give you the last {$this->num_items} posts made
* within a specific forum.
*
* @package phpBB3
*/
class forum extends \phpbb\feed\post_base
{
	var $forum_id		= 0;
	var $forum_data		= array();

	/**
	* Set the Forum ID
	*
	* @param int	$forum_id			Forum ID
	* @return	\phpbb\feed\forum
	*/
	public function set_forum_id($forum_id)
	{
		$this->forum_id = (int) $forum_id;

		return $this;
	}

	function open()
	{
		// Check if forum exists
		$sql = 'SELECT forum_id, forum_name, forum_password, forum_type, forum_options
			FROM ' . FORUMS_TABLE . '
			WHERE forum_id = ' . $this->forum_id;
		$result = $this->db->sql_query($sql);
		$this->forum_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (empty($this->forum_data))
		{
			trigger_error('NO_FORUM');
		}

		// Forum needs to be postable
		if ($this->forum_data['forum_type'] != FORUM_POST)
		{
			trigger_error('NO_FEED');
		}

		// Make sure forum is not excluded from feed
		if (phpbb_optionget(FORUM_OPTION_FEED_EXCLUDE, $this->forum_data['forum_options']))
		{
			trigger_error('NO_FEED');
		}

		// Make sure we can read this forum
		if (!$this->auth->acl_get('f_read', $this->forum_id))
		{
			trigger_error('SORRY_AUTH_READ');
		}

		// Make sure forum is not passworded or user is authed
		if ($this->forum_data['forum_password'])
		{
			$forum_ids_passworded = $this->get_passworded_forums();

			if (isset($forum_ids_passworded[$this->forum_id]))
			{
				trigger_error('SORRY_AUTH_READ');
			}

			unset($forum_ids_passworded);
		}

		parent::open();
	}

	function get_sql()
	{
		// Determine topics with recent activity
		$sql = 'SELECT topic_id, topic_last_post_time
			FROM ' . TOPICS_TABLE . '
			WHERE forum_id = ' . $this->forum_id . '
				AND topic_moved_id = 0
				AND ' . $this->content_visibility->get_visibility_sql('topic', $this->forum_id) . '
			ORDER BY topic_last_post_time DESC';
		$result = $this->db->sql_query_limit($sql, $this->num_items);

		$topic_ids = array();
		$min_post_time = 0;
		while ($row = $this->db->sql_fetchrow())
		{
			$topic_ids[] = (int) $row['topic_id'];

			$min_post_time = (int) $row['topic_last_post_time'];
		}
		$this->db->sql_freeresult($result);

		if (empty($topic_ids))
		{
			return false;
		}

		$this->sql = array(
			'SELECT'	=>	'p.post_id, p.topic_id, p.post_time, p.post_edit_time, p.post_visibility, p.post_subject, p.post_text, p.bbcode_bitfield, p.bbcode_uid, p.enable_bbcode, p.enable_smilies, p.enable_magic_url, p.post_attachment, ' .
							'u.username, u.user_id',
			'FROM'		=> array(
				POSTS_TABLE		=> 'p',
				USERS_TABLE		=> 'u',
			),
			'WHERE'		=> $this->db->sql_in_set('p.topic_id', $topic_ids) . '
							AND ' . $this->content_visibility->get_visibility_sql('post', $this->forum_id, 'p.') . '
							AND p.post_time >= ' . $min_post_time . '
							AND p.poster_id = u.user_id',
			'ORDER_BY'	=> 'p.post_time DESC',
		);

		return true;
	}

	function adjust_item(&$item_row, &$row)
	{
		parent::adjust_item($item_row, $row);

		$item_row['title'] = (isset($row['forum_name']) && $row['forum_name'] !== '') ? $row['forum_name'] . ' ' . $this->separator . ' ' . $item_row['title'] : $item_row['title'];
	}

	function get_item()
	{
		return ($row = parent::get_item()) ? array_merge($this->forum_data, $row) : $row;
	}
}
